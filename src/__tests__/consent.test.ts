/**
 * Browser Consent SDK tests
 *
 * Exercises the full state machine (`idle` → `queuing` → `live` →
 * `withdrawn`) with cookie + network IO injected so we observe what
 * the gate WOULD do to the browser instead of touching `document.cookie`
 * or the real network.
 *
 * Coverage matches the acceptance criteria in #105:
 *   - cookie absent → queuing; identify still passthrough
 *   - browse events accumulate with original timestamps
 *   - setConsent({ analytics: true }) → live, flushes with consent + sf_id
 *   - setConsent({ analytics: false }) → withdrawn, drops queue, removes
 *     sf_id + sf_utm cookies, emits consent_withdrawn telemetry
 *   - re-grant after withdraw → fresh sf_id (no identity restore)
 */

import { describe, it, expect, beforeEach, vi } from "vitest";
import { ConsentGate, __testing } from "../consent";

const { CONSENT_COOKIE_NAME, ID_COOKIE_NAME } = __testing;

interface MockCookieStore {
  read: ReturnType<typeof vi.fn>;
  write: ReturnType<typeof vi.fn>;
  remove: ReturnType<typeof vi.fn>;
  state: Map<string, string>;
}

interface MockNetwork {
  postBatch: ReturnType<typeof vi.fn>;
  calls: Array<{
    host: string;
    writeKey: string;
    batch: unknown[];
    anonymousId: string;
    consent: { analytics: boolean; marketing: boolean };
  }>;
}

interface MockSdk {
  track: ReturnType<typeof vi.fn>;
  page: ReturnType<typeof vi.fn>;
  identify: ReturnType<typeof vi.fn>;
}

function makeMocks() {
  const state = new Map<string, string>();
  const cookies: MockCookieStore = {
    state,
    read: vi.fn((name: string) => state.get(name) ?? null),
    write: vi.fn((name: string, value: string) => {
      state.set(name, value);
    }),
    remove: vi.fn((name: string) => {
      state.delete(name);
    }),
  };

  const networkCalls: MockNetwork["calls"] = [];
  const network: MockNetwork = {
    calls: networkCalls,
    postBatch: vi.fn(async (host, writeKey, batch, anonymousId, consent) => {
      networkCalls.push({ host, writeKey, batch, anonymousId, consent });
    }),
  };

  const sdk: MockSdk = {
    track: vi.fn(),
    page: vi.fn(),
    identify: vi.fn(),
  };

  const gate = new ConsentGate(cookies, network, () => sdk);
  gate.init({ host: "https://api.example.test", writeKey: "wk_test" });

  return { gate, cookies, network, sdk };
}

beforeEach(() => {
  vi.useRealTimers();
});

// ---------------------------------------------------------------------------
// init / initial state
// ---------------------------------------------------------------------------

describe("init", () => {
  it("starts in queuing when sf_consent cookie is absent", () => {
    const { gate } = makeMocks();
    expect(gate.getState()).toBe("queuing");
  });

  it("starts in live when sf_consent grants analytics", () => {
    const state = new Map<string, string>([
      [CONSENT_COOKIE_NAME, btoa(JSON.stringify({ a: 1, m: 0 }))],
    ]);
    const cookies = {
      state,
      read: (name: string) => state.get(name) ?? null,
      write: () => {},
      remove: () => {
        state.delete(CONSENT_COOKIE_NAME);
      },
    };
    const network = {
      postBatch: async () => {},
    };
    const sdk = { track: vi.fn(), page: vi.fn(), identify: vi.fn() };
    const gate = new ConsentGate(cookies, network, () => sdk);
    gate.init({ host: "https://h", writeKey: "wk" });
    expect(gate.getState()).toBe("live");
  });

  it("starts in withdrawn when sf_consent denies analytics", () => {
    const state = new Map<string, string>([
      [CONSENT_COOKIE_NAME, btoa(JSON.stringify({ a: 0, m: 0 }))],
    ]);
    const cookies = {
      state,
      read: (name: string) => state.get(name) ?? null,
      write: () => {},
      remove: () => {},
    };
    const network = { postBatch: async () => {} };
    const sdk = { track: vi.fn(), page: vi.fn(), identify: vi.fn() };
    const gate = new ConsentGate(cookies, network, () => sdk);
    gate.init({ host: "https://h", writeKey: "wk" });
    expect(gate.getState()).toBe("withdrawn");
  });
});

// ---------------------------------------------------------------------------
// queuing — pre-consent behavior
// ---------------------------------------------------------------------------

describe("queuing state", () => {
  it("queues browse track events with timestamps", () => {
    const { gate, sdk } = makeMocks();
    gate.track("product_viewed", { id: 1 });
    gate.track("cart_viewed");
    gate.track("checkout_started");

    expect(gate.getQueueSize()).toBe(3);
    expect(sdk.track).not.toHaveBeenCalled();
  });

  it("queues page events with timestamps", () => {
    const { gate, sdk } = makeMocks();
    gate.page("Home");
    expect(gate.getQueueSize()).toBe(1);
    expect(sdk.page).not.toHaveBeenCalled();
  });

  it("passes non-browse track events straight through", () => {
    const { gate, sdk } = makeMocks();
    gate.track("form_submission", { email: "a@b.c" });
    expect(gate.getQueueSize()).toBe(0);
    expect(sdk.track).toHaveBeenCalledWith({
      event: "form_submission",
      properties: { email: "a@b.c" },
    });
  });

  it("passes identify straight through (standalone fire)", () => {
    const { gate, sdk } = makeMocks();
    gate.identify({ traits: { email: "a@b.c" } });
    expect(sdk.identify).toHaveBeenCalledTimes(1);
    expect(gate.getQueueSize()).toBe(0);
  });
});

// ---------------------------------------------------------------------------
// setConsent — grant path
// ---------------------------------------------------------------------------

describe("setConsent — grant", () => {
  it("transitions to live", () => {
    const { gate } = makeMocks();
    gate.setConsent({ analytics: true, marketing: true });
    expect(gate.getState()).toBe("live");
  });

  it("writes sf_consent and creates fresh sf_id", () => {
    const { gate, cookies } = makeMocks();
    gate.setConsent({ analytics: true, marketing: true });

    expect(cookies.state.get(CONSENT_COOKIE_NAME)).toBeDefined();
    expect(cookies.state.get(ID_COOKIE_NAME)).toBeDefined();

    const decoded = JSON.parse(atob(cookies.state.get(ID_COOKIE_NAME)!)) as {
      a?: string;
    };
    expect(decoded.a).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
  });

  it("flushes queued browse events with original timestamps + consent + anonymousId", () => {
    const { gate, network } = makeMocks();

    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-01-15T10:00:00.000Z"));
    gate.track("product_viewed", { id: 1 });

    vi.setSystemTime(new Date("2026-01-15T10:00:05.000Z"));
    gate.track("cart_viewed");

    vi.setSystemTime(new Date("2026-01-15T10:00:10.000Z"));
    gate.setConsent({ analytics: true, marketing: false });
    vi.useRealTimers();

    expect(network.calls).toHaveLength(1);
    const call = network.calls[0]!;
    expect(call.consent).toEqual({ analytics: true, marketing: false });
    expect(call.batch).toHaveLength(2);
    expect(call.anonymousId).toMatch(/^[0-9a-f]{8}-/);
    expect((call.batch[0] as { timestamp: string }).timestamp).toBe("2026-01-15T10:00:00.000Z");
    expect((call.batch[1] as { timestamp: string }).timestamp).toBe("2026-01-15T10:00:05.000Z");
  });

  it("emits consent_granted telemetry on transition", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: true, marketing: true });

    expect(sdk.track).toHaveBeenCalledWith(expect.objectContaining({ event: "consent_granted" }));
  });

  it("emits consent_queue_flushed when queue had events", () => {
    const { gate, sdk } = makeMocks();
    gate.track("product_viewed");
    gate.setConsent({ analytics: true, marketing: true });

    const flushedCall = sdk.track.mock.calls.find(
      (c) => (c[0] as { event: string }).event === "consent_queue_flushed",
    );
    expect(flushedCall).toBeDefined();
    expect((flushedCall![0] as { properties: { count: number } }).properties.count).toBe(1);
  });

  it("does not emit consent_queue_flushed when queue is empty", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: true, marketing: true });

    const flushedCall = sdk.track.mock.calls.find(
      (c) => (c[0] as { event: string }).event === "consent_queue_flushed",
    );
    expect(flushedCall).toBeUndefined();
  });

  it("future browse events pass through after grant", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: true, marketing: true });

    sdk.track.mockClear();
    gate.track("product_viewed", { id: 99 });

    expect(sdk.track).toHaveBeenCalledWith({
      event: "product_viewed",
      properties: { id: 99 },
    });
  });
});

// ---------------------------------------------------------------------------
// setConsent — revoke path
// ---------------------------------------------------------------------------

describe("setConsent — revoke", () => {
  it("transitions to withdrawn", () => {
    const { gate } = makeMocks();
    gate.setConsent({ analytics: false, marketing: false });
    expect(gate.getState()).toBe("withdrawn");
  });

  it("deletes sf_id and sf_utm cookies on revoke", () => {
    const { gate, cookies } = makeMocks();
    // Seed the cookies as if a prior visit consented.
    cookies.state.set(ID_COOKIE_NAME, btoa(JSON.stringify({ a: "old-id" })));
    cookies.state.set("sf_utm", "%7B%22source%22%3A%22google%22%7D");

    gate.setConsent({ analytics: false, marketing: false });

    expect(cookies.state.has(ID_COOKIE_NAME)).toBe(false);
    expect(cookies.state.has("sf_utm")).toBe(false);
  });

  it("drops the queue", () => {
    const { gate, network } = makeMocks();
    gate.track("product_viewed");
    gate.track("cart_viewed");
    expect(gate.getQueueSize()).toBe(2);

    gate.setConsent({ analytics: false, marketing: false });

    expect(gate.getQueueSize()).toBe(0);
    expect(network.calls).toHaveLength(0);
  });

  it("emits consent_withdrawn telemetry", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: false, marketing: false });

    expect(sdk.track).toHaveBeenCalledWith(expect.objectContaining({ event: "consent_withdrawn" }));
  });

  it("subsequent browse track calls are dropped silently", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: false, marketing: false });
    sdk.track.mockClear();

    gate.track("product_viewed");
    gate.track("cart_viewed");

    expect(sdk.track).not.toHaveBeenCalled();
  });

  it("identify still fires after revoke (standalone)", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: false, marketing: false });
    sdk.identify.mockClear();

    gate.identify({ traits: { email: "user@example.com" } });

    expect(sdk.identify).toHaveBeenCalledTimes(1);
  });
});

// ---------------------------------------------------------------------------
// Re-grant after revoke — fresh identity, no restore
// ---------------------------------------------------------------------------

describe("re-grant after revoke", () => {
  it("generates a fresh sf_id rather than restoring the old one", () => {
    const { gate, cookies } = makeMocks();
    cookies.state.set(ID_COOKIE_NAME, btoa(JSON.stringify({ a: "original-id" })));

    gate.setConsent({ analytics: false, marketing: false });
    expect(cookies.state.has(ID_COOKIE_NAME)).toBe(false);

    gate.setConsent({ analytics: true, marketing: true });
    const idCookie = cookies.state.get(ID_COOKIE_NAME);
    expect(idCookie).toBeDefined();

    const decoded = JSON.parse(atob(idCookie!)) as { a: string };
    expect(decoded.a).not.toBe("original-id");
  });
});

// ---------------------------------------------------------------------------
// Marketing-only revoke (analytics still granted)
// ---------------------------------------------------------------------------

describe("marketing-only revoke", () => {
  it("stays in live when analytics: true, marketing: false", () => {
    const { gate } = makeMocks();
    gate.setConsent({ analytics: true, marketing: false });
    expect(gate.getState()).toBe("live");
  });

  it("future browse events still flow through with marketing: false flag", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: true, marketing: false });
    sdk.track.mockClear();

    gate.track("product_viewed", { id: 1 });

    expect(sdk.track).toHaveBeenCalledWith({
      event: "product_viewed",
      properties: { id: 1 },
    });
  });
});
