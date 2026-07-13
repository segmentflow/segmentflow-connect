/**
 * Segmentflow Connect — Storefront bootstrap
 *
 * Owns consent wiring, UTM first-touch capture, and page-context bootstrap.
 * Does NOT emit WooCommerce browse events or browser form submissions —
 * those are owned by the shared SDK adapter and PHP server hooks.
 *
 * @package Segmentflow_Connect
 */

import { installConsentGate } from "./consent";

// ---------- Consent gate ----------
//
// Boot the gate as soon as storefront.iife.js loads. PHP injects
// `window.__sf_config` ahead of this script, so writeKey/host are
// already available. Gate is permission-only and never creates identity.

if (window.__sf_config) {
  installConsentGate(window.__sf_config);
}

// ---------- UTM first-touch capture ----------
//
// Stamps UTM params from the landing URL into a first-party `sf_utm`
// cookie so the PHP checkout hook can read them and attach them to the
// WooCommerce order as `_segmentflow_utm_*` meta.
//
// First-touch semantics: once the cookie is set, we never overwrite it
// within the 30-day window. This preserves the original attribution for
// a visitor who clicks multiple campaigns before checking out.

const UTM_COOKIE_NAME = "sf_utm";
const UTM_COOKIE_MAX_AGE_SECONDS = 30 * 24 * 60 * 60; // 30 days
const UTM_KEYS = ["source", "medium", "campaign", "content", "term"] as const;

type UtmKey = (typeof UTM_KEYS)[number];
type Utm = Partial<Record<UtmKey, string>>;

function readUtmCookie(): Utm | null {
  const raw = document.cookie.split("; ").find((row) => row.startsWith(`${UTM_COOKIE_NAME}=`));
  if (!raw) return null;
  try {
    const value = decodeURIComponent(raw.substring(UTM_COOKIE_NAME.length + 1));
    const parsed = JSON.parse(value) as unknown;
    if (!parsed || typeof parsed !== "object") return null;
    return parsed as Utm;
  } catch {
    return null;
  }
}

function extractUtmFromLocation(): Utm {
  const params = new URLSearchParams(window.location.search);
  const utm: Utm = {};
  for (const key of UTM_KEYS) {
    const value = params.get(`utm_${key}`);
    if (value && value.trim().length > 0) {
      utm[key] = value.trim();
    }
  }
  return utm;
}

function writeUtmCookie(utm: Utm): void {
  const encoded = encodeURIComponent(JSON.stringify(utm));
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie =
    `${UTM_COOKIE_NAME}=${encoded}` +
    `; Max-Age=${UTM_COOKIE_MAX_AGE_SECONDS}` +
    `; Path=/` +
    `; SameSite=Lax` +
    secure;
}

(function captureUtmFirstTouch(): void {
  // Respect first-touch: if a cookie already exists with any UTM, don't
  // overwrite it, even if the current URL carries different UTMs.
  const existing = readUtmCookie();
  if (existing && Object.keys(existing).length > 0) return;

  const incoming = extractUtmFromLocation();
  if (Object.keys(incoming).length === 0) return;

  writeUtmCookie(incoming);
})();
