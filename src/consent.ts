/**
 * Browser Consent SDK
 *
 * Cookie-consent state machine for the storefront. Sits in front of the
 * main CDN SDK (`window.segmentflow`) and decides whether each track or
 * page call is allowed to leave the browser based on the visitor's
 * recorded `sf_consent` cookie.
 *
 * The state machine has four states:
 *
 *   idle       — pre-init only. The gate has not read the cookie yet.
 *   queuing    — no consent decision yet (`sf_consent` absent). Browse
 *                events accumulate in memory with their original
 *                capture timestamp; identify still fires standalone.
 *   live       — analytics granted. Browse events flush, future events
 *                pass straight through to the CDN SDK.
 *   withdrawn  — analytics denied or later revoked. Browse events are
 *                silently dropped; the queue is cleared and `sf_id` /
 *                `sf_utm` cookies are deleted on transition.
 *
 * The gate ALSO writes the same `sf_consent` cookie format as the PHP
 * `Segmentflow_Consent_Cookie` class, so server hooks and the SDK reach
 * the same decision without a round-trip.
 */

const CONSENT_COOKIE_NAME = "sf_consent";
const ID_COOKIE_NAME = "sf_id";
const UTM_COOKIE_NAME = "sf_utm";
const COOKIE_LIFETIME_SECONDS = 31536000; // 1 year — ePrivacy ceiling.

const BROWSE_EVENT_NAMES: ReadonlySet<string> = new Set([
  "product_viewed",
  "cart_viewed",
  "checkout_started",
]);

const TELEMETRY_EVENTS = {
  granted: "consent_granted",
  withdrawn: "consent_withdrawn",
  queueFlushed: "consent_queue_flushed",
} as const;

export type ConsentState = "idle" | "queuing" | "live" | "withdrawn";

export interface ConsentFlags {
  analytics: boolean;
  marketing: boolean;
}

export interface ConsentConfig {
  writeKey: string;
  host: string;
}

interface QueuedEvent {
  type: "track" | "page";
  event?: string;
  name?: string;
  properties?: Record<string, unknown>;
  timestamp: string;
}

interface CookieIO {
  read(name: string): string | null;
  write(name: string, value: string, maxAgeSeconds: number): void;
  remove(name: string): void;
}

interface NetworkIO {
  postBatch(
    host: string,
    writeKey: string,
    batch: QueuedEvent[],
    anonymousId: string,
    consent: ConsentFlags,
  ): Promise<void>;
}

interface SdkBridge {
  track(params: { event: string; properties?: Record<string, unknown> }): void;
  page(params?: { name?: string; properties?: Record<string, unknown> }): void;
  identify(params: {
    userId?: string;
    traits?: Record<string, unknown>;
    context?: Record<string, unknown>;
  }): void;
}

// ---------- Cookie codec ----------

function encodeConsentCookie(flags: ConsentFlags): string {
  return btoa(JSON.stringify({ a: flags.analytics ? 1 : 0, m: flags.marketing ? 1 : 0 }));
}

function decodeConsentCookie(raw: string | null): ConsentFlags | null {
  if (!raw) return null;
  try {
    const json = atob(raw);
    const decoded = JSON.parse(json) as { a?: number | boolean; m?: number | boolean };
    if (
      typeof decoded !== "object" ||
      decoded === null ||
      (decoded.a === undefined && decoded.m === undefined)
    ) {
      return null;
    }
    return {
      analytics: Boolean(decoded.a),
      marketing: Boolean(decoded.m),
    };
  } catch {
    return null;
  }
}

function decodeIdCookie(raw: string | null): { a?: string } | null {
  if (!raw) return null;
  try {
    const json = atob(raw);
    const parsed = JSON.parse(json) as unknown;
    if (parsed && typeof parsed === "object") {
      return parsed as { a?: string };
    }
    return null;
  } catch {
    return null;
  }
}

function encodeIdCookie(payload: { a: string }): string {
  return btoa(JSON.stringify(payload));
}

// ---------- UUID v7 ----------

function generateUuidV7(): string {
  const ms = Date.now();
  const msHex = ms.toString(16).padStart(12, "0");
  const random = new Uint8Array(10);
  crypto.getRandomValues(random);

  const bytes: string[] = [];
  for (let i = 0; i < 6; i++) {
    bytes.push(msHex.slice(i * 2, i * 2 + 2));
  }
  for (let i = 0; i < 10; i++) {
    bytes.push((random[i] ?? 0).toString(16).padStart(2, "0"));
  }

  // Set version (byte 6 high nibble = 7).
  bytes[6] = "7" + (bytes[6] ?? "00").slice(1);
  // Set variant (byte 8 high two bits = 10).
  const byte8 = parseInt(bytes[8] ?? "00", 16);
  bytes[8] = ((byte8 & 0x3f) | 0x80).toString(16).padStart(2, "0");

  const hex = bytes.join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
}

// ---------- Real cookie/network adapters ----------

const browserCookieIO: CookieIO = {
  read(name: string): string | null {
    const row = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
    if (!row) return null;
    return decodeURIComponent(row.substring(name.length + 1));
  },
  write(name: string, value: string, maxAgeSeconds: number): void {
    const secure = window.location.protocol === "https:" ? "; Secure" : "";
    document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAgeSeconds}; Path=/; SameSite=Lax${secure}`;
  },
  remove(name: string): void {
    const secure = window.location.protocol === "https:" ? "; Secure" : "";
    document.cookie = `${name}=; Max-Age=0; Path=/; SameSite=Lax${secure}`;
  },
};

const browserNetworkIO: NetworkIO = {
  async postBatch(host, writeKey, batch, anonymousId, consent) {
    const events = batch.map((item) => ({
      ...item,
      anonymousId,
    }));
    try {
      await fetch(`${host}/api/v1/ingest/batch`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ writeKey, batch: events, consent }),
        keepalive: true,
      });
    } catch {
      // Fire-and-forget; flushing is best-effort. Do not throw — the
      // caller has already cleared the queue and emitted telemetry.
    }
  },
};

// ---------- Consent gate ----------

export class ConsentGate {
  private state: ConsentState = "idle";
  private queue: QueuedEvent[] = [];
  private config: ConsentConfig | null = null;

  constructor(
    private readonly cookies: CookieIO = browserCookieIO,
    private readonly network: NetworkIO = browserNetworkIO,
    private readonly sdk: () => SdkBridge | undefined = () => window.segmentflow,
  ) {}

  init(config: ConsentConfig): void {
    this.config = config;

    const flags = decodeConsentCookie(this.cookies.read(CONSENT_COOKIE_NAME));
    if (flags === null) {
      this.state = "queuing";
      return;
    }
    this.state = flags.analytics ? "live" : "withdrawn";
  }

  getState(): ConsentState {
    return this.state;
  }

  /**
   * Public surface for cookie banners. Records the visitor's decision,
   * flushes the queue (or drops it), and updates `sf_id` / `sf_utm` to
   * match the new state.
   */
  setConsent(flags: ConsentFlags): void {
    const previousState = this.state;
    this.cookies.write(CONSENT_COOKIE_NAME, encodeConsentCookie(flags), COOKIE_LIFETIME_SECONDS);

    if (flags.analytics) {
      const anonymousId = this.ensureSfId();
      this.state = "live";

      const flushed = this.flushQueue(anonymousId, flags);
      if (previousState !== "live") {
        this.emitTelemetry(TELEMETRY_EVENTS.granted, flags);
      }
      if (flushed > 0) {
        this.emitTelemetry(TELEMETRY_EVENTS.queueFlushed, {
          ...flags,
          count: flushed,
        });
      }
      return;
    }

    // Revoke or initial deny: clear identifiers + drop queue. A previously
    // queued visitor leaves no trace; a previously-consented visitor
    // gets a new `sf_id` if they re-grant later (no identity restore).
    this.cookies.remove(ID_COOKIE_NAME);
    this.cookies.remove(UTM_COOKIE_NAME);
    this.queue = [];
    this.state = "withdrawn";
    this.emitTelemetry(TELEMETRY_EVENTS.withdrawn, flags);
  }

  /**
   * Gate a track call. Browse events are queued or dropped based on
   * state; everything else (form_submission, etc.) flows through.
   */
  track(event: string, properties?: Record<string, unknown>): void {
    if (BROWSE_EVENT_NAMES.has(event)) {
      if (this.state === "queuing") {
        this.queue.push({
          type: "track",
          event,
          properties,
          timestamp: new Date().toISOString(),
        });
        return;
      }
      if (this.state === "withdrawn") {
        return;
      }
    }
    this.sdk()?.track({ event, properties });
  }

  /**
   * Page events are always classified as browse (per the API gate at
   * #104). Same queue/drop semantics as track for browse events.
   */
  page(name?: string, properties?: Record<string, unknown>): void {
    if (this.state === "queuing") {
      this.queue.push({
        type: "page",
        name,
        properties,
        timestamp: new Date().toISOString(),
      });
      return;
    }
    if (this.state === "withdrawn") {
      return;
    }
    this.sdk()?.page({ name, properties });
  }

  /**
   * Identify is always allowed — `identify({ email })` is a confirmed
   * user action (Art. 6 legitimate interest), not a tracking cookie.
   * When consent is absent the call fires standalone: it does not
   * restore queued browse events, and it does not write `sf_id`.
   */
  identify(params: {
    userId?: string;
    traits?: Record<string, unknown>;
    context?: Record<string, unknown>;
  }): void {
    this.sdk()?.identify(params);
  }

  /** Test/visibility hook. */
  getQueueSize(): number {
    return this.queue.length;
  }

  // ---------- internals ----------

  private ensureSfId(): string {
    const existing = decodeIdCookie(this.cookies.read(ID_COOKIE_NAME));
    if (existing && existing.a) {
      return existing.a;
    }
    const fresh = generateUuidV7();
    this.cookies.write(ID_COOKIE_NAME, encodeIdCookie({ a: fresh }), COOKIE_LIFETIME_SECONDS);
    return fresh;
  }

  private flushQueue(anonymousId: string, consent: ConsentFlags): number {
    const events = this.queue;
    this.queue = [];
    if (events.length === 0 || !this.config) return 0;

    void this.network.postBatch(
      this.config.host,
      this.config.writeKey,
      events,
      anonymousId,
      consent,
    );

    return events.length;
  }

  private emitTelemetry(event: string, properties: Record<string, unknown>): void {
    // Telemetry events are not in BROWSE_EVENT_NAMES so they pass
    // straight through the gate to the CDN SDK and join the analytics
    // pipeline like any other internal event.
    this.sdk()?.track({ event, properties });
  }
}

let installed: ConsentGate | null = null;

/**
 * Install (or reinstall) the consent gate as a singleton. Idempotent:
 * a second call replaces the previous gate, used by tests.
 */
export function installConsentGate(
  config: ConsentConfig,
  options?: { cookies?: CookieIO; network?: NetworkIO; sdk?: () => SdkBridge | undefined },
): ConsentGate {
  installed = new ConsentGate(options?.cookies, options?.network, options?.sdk);
  installed.init(config);

  // Expose `setConsent` on the global SDK object so banners can call it.
  if (window.segmentflow) {
    (window.segmentflow as unknown as { setConsent: (flags: ConsentFlags) => void }).setConsent = (
      flags: ConsentFlags,
    ) => installed!.setConsent(flags);
  }

  return installed;
}

export function getConsentGate(): ConsentGate | null {
  return installed;
}

export const __testing = {
  generateUuidV7,
  decodeConsentCookie,
  encodeConsentCookie,
  decodeIdCookie,
  BROWSE_EVENT_NAMES,
  CONSENT_COOKIE_NAME,
  ID_COOKIE_NAME,
};
