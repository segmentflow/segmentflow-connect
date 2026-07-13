/**
 * Browser Consent SDK
 *
 * Cookie-consent permission gate for the storefront. Sits in front of the
 * main CDN SDK (`window.segmentflow`) and decides whether each track or
 * page call is allowed to leave the browser based on the visitor's
 * recorded `sf_consent` cookie.
 *
 * Consent is permission only: it never creates identity, never queues
 * anonymous history, and never opens a direct ingest network path.
 *
 * The state machine has four states:
 *
 *   idle       — pre-init only. The gate has not read the cookie yet.
 *   pending    — no consent decision yet (`sf_consent` absent). Browse
 *                analytics are dropped; identify still fires standalone.
 *   live       — analytics granted. Browse events pass through to the
 *                CDN SDK.
 *   withdrawn  — analytics denied or later revoked. Browse events are
 *                silently dropped; `sf_utm` attribution is cleared on
 *                transition. No identity cookie exists to clear.
 *
 * The gate ALSO writes the same `sf_consent` cookie format as the PHP
 * `Segmentflow_Consent_Cookie` class, so server hooks and the SDK reach
 * the same decision without a round-trip.
 */

const CONSENT_COOKIE_NAME = "sf_consent";
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
} as const;

export type ConsentState = "idle" | "pending" | "live" | "withdrawn";

export interface ConsentFlags {
  analytics: boolean;
  marketing: boolean;
}

export interface ConsentConfig {
  writeKey: string;
  host: string;
}

interface CookieIO {
  read(name: string): string | null;
  write(name: string, value: string, maxAgeSeconds: number): void;
  remove(name: string): void;
}

interface SdkBridge {
  track(params: { event: string; properties?: Record<string, unknown> }): void;
  page(params?: { name?: string; properties?: Record<string, unknown> }): void;
  identify(params: {
    userId?: string;
    traits?: Record<string, unknown>;
    context?: Record<string, unknown>;
  }): void;
  setConsent?(flags: ConsentFlags): void;
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

// ---------- Real cookie adapter ----------

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

// ---------- Consent gate ----------

export class ConsentGate {
  private state: ConsentState = "idle";
  private flags: ConsentFlags | null = null;

  constructor(
    private readonly cookies: CookieIO = browserCookieIO,
    private readonly sdk: () => SdkBridge | undefined = () => window.segmentflow,
  ) {}

  init(_config: ConsentConfig): void {
    const flags = decodeConsentCookie(this.cookies.read(CONSENT_COOKIE_NAME));
    if (flags === null) {
      this.flags = null;
      this.state = "pending";
      return;
    }
    this.flags = flags;
    this.state = flags.analytics ? "live" : "withdrawn";
    this.forwardConsentToSdk(flags);
  }

  getState(): ConsentState {
    return this.state;
  }

  getFlags(): ConsentFlags | null {
    return this.flags;
  }

  /**
   * Public surface for cookie banners. Records the visitor's decision
   * and updates browse-event permission. Never creates an identifier
   * or flushes historical events.
   */
  setConsent(flags: ConsentFlags): void {
    const previousState = this.state;
    this.cookies.write(CONSENT_COOKIE_NAME, encodeConsentCookie(flags), COOKIE_LIFETIME_SECONDS);
    this.flags = flags;
    this.forwardConsentToSdk(flags);

    if (flags.analytics) {
      this.state = "live";
      if (previousState !== "live") {
        this.emitTelemetry(TELEMETRY_EVENTS.granted, { ...flags });
      }
      return;
    }

    // Revoke or initial deny: clear analytics-owned attribution only.
    this.cookies.remove(UTM_COOKIE_NAME);
    this.state = "withdrawn";
    this.emitTelemetry(TELEMETRY_EVENTS.withdrawn, { ...flags });
  }

  /**
   * Gate a track call. Browse events pass only when analytics is live;
   * everything else (form_submission, etc.) flows through.
   */
  track(event: string, properties?: Record<string, unknown>): void {
    if (BROWSE_EVENT_NAMES.has(event) && this.state !== "live") {
      return;
    }
    this.sdk()?.track({ event, properties });
  }

  /**
   * Page events are browse analytics. Drop when analytics is not live.
   */
  page(name?: string, properties?: Record<string, unknown>): void {
    if (this.state !== "live") {
      return;
    }
    this.sdk()?.page({ name, properties });
  }

  /**
   * Identify is always allowed — confirmed user action, not a tracking
   * cookie. Consent absence does not invent identity or restore history.
   */
  identify(params: {
    userId?: string;
    traits?: Record<string, unknown>;
    context?: Record<string, unknown>;
  }): void {
    this.sdk()?.identify(params);
  }

  // ---------- internals ----------

  private forwardConsentToSdk(flags: ConsentFlags): void {
    const sdk = this.sdk();
    sdk?.setConsent?.(flags);
  }

  private emitTelemetry(event: string, properties: Record<string, unknown>): void {
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
  options?: { cookies?: CookieIO; sdk?: () => SdkBridge | undefined },
): ConsentGate {
  installed = new ConsentGate(options?.cookies, options?.sdk);
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
  decodeConsentCookie,
  encodeConsentCookie,
  BROWSE_EVENT_NAMES,
  CONSENT_COOKIE_NAME,
  UTM_COOKIE_NAME,
};
