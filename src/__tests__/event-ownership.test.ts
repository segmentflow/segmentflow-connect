/**
 * Event-ownership tests for the storefront bootstrap.
 *
 * After the identified cutover, this repository must not emit browser
 * WooCommerce browse events or browser form submissions. Those belong
 * to the shared SDK adapter and PHP server hooks respectively.
 */

import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const root = join(dirname(fileURLToPath(import.meta.url)), "..", "..");
const storefrontSource = readFileSync(join(root, "src/storefront.ts"), "utf8");
const serverEventsSource = readFileSync(
  join(root, "includes/class-segmentflow-server-events.php"),
  "utf8",
);
const wcServerEventsSource = readFileSync(
  join(root, "integrations/woocommerce/class-segmentflow-wc-server-events.php"),
  "utf8",
);
const trackingSource = readFileSync(join(root, "includes/class-segmentflow-tracking.php"), "utf8");

describe("storefront event ownership", () => {
  it("does not emit WooCommerce browse events", () => {
    expect(storefrontSource).not.toMatch(/product_viewed/);
    expect(storefrontSource).not.toMatch(/cart_viewed/);
    expect(storefrontSource).not.toMatch(/checkout_started/);
  });

  it("does not register browser CF7 or Elementor emission listeners", () => {
    expect(storefrontSource).not.toMatch(/wpcf7mailsent/);
    expect(storefrontSource).not.toMatch(/elementor_pro_forms_send_form/);
    expect(storefrontSource).not.toMatch(/form_submission/);
  });

  it("keeps consent bootstrap and UTM capture", () => {
    expect(storefrontSource).toMatch(/installConsentGate/);
    expect(storefrontSource).toMatch(/sf_utm/);
  });
});

describe("PHP event ownership", () => {
  it("registers PHP form hooks exactly once each", () => {
    expect(serverEventsSource.match(/add_action\(\s*'wpcf7_mail_sent'/g)?.length).toBe(1);
    expect(
      serverEventsSource.match(/add_action\(\s*'elementor_pro\/forms\/new_record'/g)?.length,
    ).toBe(1);
    expect(serverEventsSource).toMatch(/form_submission/);
  });

  it("registers server cart mutation hooks exactly once each", () => {
    expect(wcServerEventsSource.match(/add_action\(\s*'woocommerce_add_to_cart'/g)?.length).toBe(1);
    expect(
      wcServerEventsSource.match(/add_action\(\s*'woocommerce_cart_item_removed'/g)?.length,
    ).toBe(1);
  });

  it("keeps shared SDK bootstrap and WooCommerce context injection path", () => {
    expect(trackingSource).toMatch(/cdn\.cloud\.segmentflow\.ai\/v1\/sdk\.js/);
    expect(trackingSource).toMatch(/segmentflow_tracking_context/);
    expect(trackingSource).toMatch(/__sf_config/);
  });
});
