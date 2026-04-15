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
