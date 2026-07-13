/**
 * Browser Consent SDK tests
 *
 * Consent is permission only: no identity cookie, no anonymous queue,
 * and no direct ingest network path from the gate.
 */

import { describe, it, expect, beforeEach, vi } from "vitest";
import { ConsentGate, __testing } from "../consent";

const { CONSENT_COOKIE_NAME, UTM_COOKIE_NAME } = __testing;

interface CookieIO {
  read(name: string): string | null;
  write(name: string, value: string, maxAgeSeconds: number): void;
  remove(name: string): void;
}

function makeMocks() {
  const state = new Map<string, string>();
  const readCalls: string[] = [];
  const writeCalls: Array<[string, string]> = [];
  const removeCalls: string[] = [];

  const cookies: CookieIO & {
    state: Map<string, string>;
    readCalls: string[];
    writeCalls: Array<[string, string]>;
    removeCalls: string[];
  } = {
    state,
    readCalls,
    writeCalls,
    removeCalls,
    read(name: string): string | null {
      readCalls.push(name);
      return state.get(name) ?? null;
    },
    write(name: string, value: string, _maxAgeSeconds: number): void {
      writeCalls.push([name, value]);
      state.set(name, value);
    },
    remove(name: string): void {
      removeCalls.push(name);
      state.delete(name);
    },
  };

  const sdk = {
    track: vi.fn(),
    page: vi.fn(),
    identify: vi.fn(),
    setConsent: vi.fn(),
  };

  const gate = new ConsentGate(cookies, () => sdk as never);
  gate.init({ host: "https://api.example.test", writeKey: "wk_test" });

  return { gate, cookies, sdk };
}

beforeEach(() => {
  vi.useRealTimers();
});

describe("init", () => {
  it("starts in pending when sf_consent cookie is absent", () => {
    const { gate, cookies } = makeMocks();
    expect(gate.getState()).toBe("pending");
    expect(gate.getFlags()).toBeNull();
    expect(cookies.readCalls).not.toContain("sf_id");
  });

  it("starts in live when sf_consent grants analytics", () => {
    const state = new Map<string, string>([
      [CONSENT_COOKIE_NAME, btoa(JSON.stringify({ a: 1, m: 0 }))],
    ]);
    const cookies = {
      read: (name: string) => state.get(name) ?? null,
      write: () => {},
      remove: () => {
        state.delete(CONSENT_COOKIE_NAME);
      },
    };
    const sdk = { track: vi.fn(), page: vi.fn(), identify: vi.fn(), setConsent: vi.fn() };
    const gate = new ConsentGate(cookies, () => sdk as never);
    gate.init({ host: "https://h", writeKey: "wk" });
    expect(gate.getState()).toBe("live");
    expect(sdk.setConsent).toHaveBeenCalledWith({ analytics: true, marketing: false });
  });

  it("starts in withdrawn when sf_consent denies analytics", () => {
    const state = new Map<string, string>([
      [CONSENT_COOKIE_NAME, btoa(JSON.stringify({ a: 0, m: 0 }))],
    ]);
    const cookies = {
      read: (name: string) => state.get(name) ?? null,
      write: () => {},
      remove: () => {},
    };
    const sdk = { track: vi.fn(), page: vi.fn(), identify: vi.fn(), setConsent: vi.fn() };
    const gate = new ConsentGate(cookies, () => sdk as never);
    gate.init({ host: "https://h", writeKey: "wk" });
    expect(gate.getState()).toBe("withdrawn");
  });

  it("never creates or reads sf_id on boot", () => {
    const { cookies } = makeMocks();
    expect(cookies.state.has("sf_id")).toBe(false);
    expect(cookies.writeCalls.some(([name]) => name === "sf_id")).toBe(false);
    expect(cookies.readCalls.every((name) => name !== "sf_id")).toBe(true);
  });
});

describe("pending state", () => {
  it("drops browse track events without queuing", () => {
    const { gate, sdk } = makeMocks();
    gate.track("product_viewed", { id: 1 });
    gate.track("cart_viewed");
    gate.track("checkout_started");

    expect(sdk.track).not.toHaveBeenCalled();
  });

  it("drops page events without queuing", () => {
    const { gate, sdk } = makeMocks();
    gate.page("Home");
    expect(sdk.page).not.toHaveBeenCalled();
  });

  it("passes non-browse track events straight through", () => {
    const { gate, sdk } = makeMocks();
    gate.track("form_submission", { email: "a@b.c" });
    expect(sdk.track).toHaveBeenCalledWith({
      event: "form_submission",
      properties: { email: "a@b.c" },
    });
  });

  it("passes identify straight through without creating identity", () => {
    const { gate, sdk, cookies } = makeMocks();
    gate.identify({ traits: { email: "a@b.c" } });
    expect(sdk.identify).toHaveBeenCalledTimes(1);
    expect(cookies.state.has("sf_id")).toBe(false);
  });
});

describe("setConsent — grant", () => {
  it("transitions to live without creating an identifier", () => {
    const { gate, cookies } = makeMocks();
    gate.setConsent({ analytics: true, marketing: true });
    expect(gate.getState()).toBe("live");
    expect(cookies.state.get(CONSENT_COOKIE_NAME)).toBeDefined();
    expect(cookies.state.has("sf_id")).toBe(false);
  });

  it("does not flush historical browse events on grant", () => {
    const { gate, sdk } = makeMocks();
    gate.track("product_viewed", { id: 1 });
    gate.track("cart_viewed");
    sdk.track.mockClear();

    gate.setConsent({ analytics: true, marketing: false });

    expect(sdk.track).toHaveBeenCalledWith(expect.objectContaining({ event: "consent_granted" }));
    expect(
      sdk.track.mock.calls.some(
        (call) => (call[0] as { event: string }).event === "product_viewed",
      ),
    ).toBe(false);
    expect(
      sdk.track.mock.calls.some(
        (call) => (call[0] as { event: string }).event === "consent_queue_flushed",
      ),
    ).toBe(false);
  });

  it("forwards consent flags to the shared SDK", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: true, marketing: false });
    expect(sdk.setConsent).toHaveBeenCalledWith({ analytics: true, marketing: false });
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

describe("setConsent — revoke", () => {
  it("transitions to withdrawn", () => {
    const { gate } = makeMocks();
    gate.setConsent({ analytics: false, marketing: false });
    expect(gate.getState()).toBe("withdrawn");
  });

  it("clears only analytics-owned sf_utm state", () => {
    const { gate, cookies } = makeMocks();
    cookies.state.set(UTM_COOKIE_NAME, "%7B%22source%22%3A%22google%22%7D");
    cookies.state.set("sf_id", btoa(JSON.stringify({ a: "should-not-be-managed" })));

    gate.setConsent({ analytics: false, marketing: false });

    expect(cookies.state.has(UTM_COOKIE_NAME)).toBe(false);
    expect(cookies.removeCalls).not.toContain("sf_id");
  });

  it("emits consent_withdrawn telemetry without anonymous fields", () => {
    const { gate, sdk } = makeMocks();
    gate.setConsent({ analytics: false, marketing: false });

    expect(sdk.track).toHaveBeenCalledWith({
      event: "consent_withdrawn",
      properties: { analytics: false, marketing: false },
    });
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

describe("re-grant after revoke", () => {
  it("restores permission only and creates no identifier", () => {
    const { gate, cookies } = makeMocks();

    gate.setConsent({ analytics: false, marketing: false });
    gate.setConsent({ analytics: true, marketing: true });

    expect(gate.getState()).toBe("live");
    expect(cookies.state.has("sf_id")).toBe(false);
  });
});

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

describe("sf_utm attribution", () => {
  it("treats sf_utm as attribution metadata rather than identity", () => {
    const { gate, cookies } = makeMocks();
    cookies.state.set(UTM_COOKIE_NAME, "%7B%22source%22%3A%22google%22%7D");

    gate.setConsent({ analytics: true, marketing: true });

    expect(cookies.state.get(UTM_COOKIE_NAME)).toBe("%7B%22source%22%3A%22google%22%7D");
    expect(cookies.state.has("sf_id")).toBe(false);
  });
});
