/**
 * Segmentflow Connect — Storefront Form Tracking
 *
 * Listens for form submission events from Contact Form 7 and Elementor Pro,
 * and fires segmentflow.track("form_submission", ...) for each.
 *
 * Loaded via wp_enqueue_script() on all frontend pages where form plugins
 * are active. Uses optional chaining on window.segmentflow in case the
 * SDK is blocked by an ad blocker.
 *
 * @package Segmentflow_Connect
 */

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

// ---------- WooCommerce page-view tracking ----------
//
// The PHP layer (class-segmentflow-wc-tracking.php) injects window.__sf_wc
// with the current page type and contextual data. Fire the corresponding
// track events so the Abandoned Cart / view-based segment templates work
// for WooCommerce stores. Order events are handled server-to-server via
// the WC REST webhook and are NOT fired from the client.
//
// Client-side (rather than PHP) because page caches (WP Rocket, LiteSpeed,
// hosted WP) cache product/cart/checkout HTML for guests — server-side
// fires would be suppressed. Trade-off: ad-blocker users (~10–15%) miss
// these events. Order events stay 100% covered via the webhook path.

function fireWhenSdkReady(callback: (sdk: NonNullable<Window["segmentflow"]>) => void): void {
  if (window.segmentflow) {
    callback(window.segmentflow);
    return;
  }
  const start = Date.now();
  const interval = window.setInterval(() => {
    if (window.segmentflow) {
      window.clearInterval(interval);
      callback(window.segmentflow);
    } else if (Date.now() - start > 5000) {
      // SDK never loaded — likely blocked by an ad blocker. Give up silently.
      window.clearInterval(interval);
    }
  }, 100);
}

(function captureWcPageView(): void {
  const wc = window.__sf_wc;
  if (!wc) return;

  // The PHP get_page_type() already excludes the order-received thank-you
  // page from "checkout", so no extra guard is needed here.
  switch (wc.page) {
    case "product": {
      if (!wc.product) return;
      fireWhenSdkReady((sdk) =>
        sdk.track({
          event: "product_viewed",
          properties: { ...wc.product, currency: wc.currency },
        }),
      );
      return;
    }
    case "cart": {
      fireWhenSdkReady((sdk) =>
        sdk.track({
          event: "cart_viewed",
          properties: { cart: wc.cart, currency: wc.currency },
        }),
      );
      return;
    }
    case "checkout": {
      fireWhenSdkReady((sdk) =>
        sdk.track({
          event: "checkout_started",
          properties: { cart: wc.cart, currency: wc.currency },
        }),
      );
      return;
    }
  }
})();

// ---------- Contact Form 7 ----------

interface CF7Detail {
  contactFormId: number;
  contactFormTitle?: string;
  inputs: Array<{ name: string; value: string }>;
}

document.addEventListener("wpcf7mailsent", ((event: CustomEvent<CF7Detail>) => {
  const detail = event.detail;
  if (!detail) return;

  const inputs = detail.inputs || [];
  const emailInput = inputs.find((i) => i.name.includes("email") || i.name === "your-email");
  const nameInput = inputs.find((i) => i.name.includes("name") || i.name === "your-name");

  const properties: Record<string, unknown> = {
    form_id: detail.contactFormId,
    form_plugin: "contact_form_7",
    form_title: detail.contactFormTitle || undefined,
  };

  if (emailInput?.value) {
    properties.email = emailInput.value;
    window.segmentflow?.identify({ traits: { email: emailInput.value } });
  }

  if (nameInput?.value) {
    properties.name = nameInput.value;
  }

  window.segmentflow?.track({
    event: "form_submission",
    properties,
  });
}) as EventListener);

// ---------- Elementor Pro Forms ----------

const $ = window.jQuery;
if ($) {
  $(document).ajaxComplete(
    (
      _event: unknown,
      xhr: { status: number; responseJSON?: unknown; responseText?: string },
      settings: { data?: string },
    ) => {
      if (!settings.data) return;
      if (
        typeof settings.data !== "string" ||
        !settings.data.includes("action=elementor_pro_forms_send_form")
      ) {
        return;
      }

      const formData: Record<string, string> = {};
      try {
        const params = new URLSearchParams(settings.data);
        for (const [key, value] of params) {
          formData[key] = value;
        }
      } catch {
        return;
      }

      let responseOk = false;
      try {
        const json =
          typeof xhr.responseJSON === "object"
            ? (xhr.responseJSON as Record<string, unknown>)
            : (JSON.parse(xhr.responseText || "{}") as Record<string, unknown>);
        responseOk = json.success === true;
      } catch {
        responseOk = xhr.status >= 200 && xhr.status < 300;
      }

      if (!responseOk) return;

      const emailField = Object.entries(formData).find(
        ([key, val]) =>
          (key.startsWith("form_fields[") && val.includes("@")) || key.includes("email"),
      );

      const properties: Record<string, unknown> = {
        form_id: formData["form_id"] || formData["post_id"] || undefined,
        form_plugin: "elementor_pro",
      };

      if (emailField?.[1]) {
        properties.email = emailField[1];
        window.segmentflow?.identify({ traits: { email: emailField[1] } });
      }

      window.segmentflow?.track({
        event: "form_submission",
        properties,
      });
    },
  );
}
