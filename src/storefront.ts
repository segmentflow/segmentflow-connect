/**
 * Segmentflow Connect — Storefront Form Tracking
 *
 * Listens for form submission events from Contact Form 7 and Elementor Pro,
 * and fires segmentflow.track("form_submitted", ...) for each.
 *
 * Loaded via wp_enqueue_script() on all frontend pages where form plugins
 * are active. Uses optional chaining on window.segmentflow in case the
 * SDK is blocked by an ad blocker.
 *
 * @package Segmentflow_Connect
 */

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
    event: "form_submitted",
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
        event: "form_submitted",
        properties,
      });
    },
  );
}
