/**
 * Seed WooCommerce sample data for local development.
 *
 * Idempotent — safe to run on every `wp-env start` via lifecycleScripts.
 * Checks if data already exists before creating to avoid duplicates.
 *
 * Seeds:
 * - WooCommerce sample products (24 from bundled CSV)
 * - 4 test customers with realistic billing/shipping info
 * - 8 test orders across different statuses (completed, processing, pending, refunded, on-hold)
 * - 1 guest order (no customer account)
 * - Pretty permalinks (required for WC REST API)
 * - WooCommerce pages (shop, cart, checkout, my-account)
 *
 * Usage:
 *   node scripts/seed-wc-data.mjs          # Run directly
 *   pnpm env:seed                           # Via npm script
 *   (auto-runs on `pnpm env:start` via lifecycleScripts.afterStart)
 *
 * @package Segmentflow_Connect
 */

import { execSync } from "node:child_process";

// ============================================================================
// Helpers
// ============================================================================

/**
 * Run a WP-CLI command inside the wp-env container.
 *
 * @param {string} command - The WP-CLI command (without `wp` prefix).
 * @param {object} [options] - Options.
 * @param {boolean} [options.ignoreError] - If true, return empty string on failure instead of throwing.
 * @returns {string} Command stdout trimmed.
 */
function wp(command, { ignoreError = false } = {}) {
  try {
    const result = execSync(`npx @wordpress/env run cli wp ${command}`, {
      encoding: "utf-8",
      stdio: ["pipe", "pipe", "pipe"],
    });
    return result.trim();
  } catch (error) {
    if (ignoreError) {
      return "";
    }
    // Print stderr for debugging.
    if (error.stderr) {
      console.error(error.stderr.toString().trim());
    }
    throw error;
  }
}

/**
 * Log a step with a prefix.
 *
 * @param {string} message - The message to log.
 */
function log(message) {
  console.log(`[seed] ${message}`);
}

// ============================================================================
// Seed: Permalinks + WooCommerce Pages
// ============================================================================

function seedPermalinks() {
  log("Setting pretty permalinks...");
  wp('rewrite structure "/%postname%/" --hard', { ignoreError: true });
  wp("rewrite flush --hard", { ignoreError: true });
}

function seedWooCommercePages() {
  log("Ensuring WooCommerce pages exist...");
  wp("wc tool run install_pages --user=1", { ignoreError: true });
}

// ============================================================================
// Seed: Products
// ============================================================================

function seedProducts() {
  const count = wp("wc product list --user=1 --format=count", {
    ignoreError: true,
  });
  const productCount = parseInt(count, 10) || 0;

  if (productCount > 0) {
    log(`Products already seeded (${productCount} found). Skipping.`);
    return;
  }

  log("Importing WooCommerce sample products...");

  // WooCommerce bundles sample data inside the plugin.
  const csvPath =
    "/var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.csv";

  // The CSV importer is a WP-CLI command added by WooCommerce.
  // Fall back to the WordPress XML importer if the CSV command isn't available.
  try {
    wp(`wc product_csv_importer "${csvPath}" --user=1`);
    log("Sample products imported via CSV importer.");
  } catch {
    log(
      "CSV importer not available, trying WordPress XML importer...",
    );
    try {
      wp("plugin install wordpress-importer --activate", {
        ignoreError: true,
      });
      const xmlPath =
        "/var/www/html/wp-content/plugins/woocommerce/sample-data/sample_products.xml";
      wp(`import "${xmlPath}" --authors=create --user=1`);
      log("Sample products imported via XML importer.");
    } catch {
      log(
        "WARNING: Could not auto-import products. Import manually via WooCommerce > Products > Import.",
      );
    }
  }
}

// ============================================================================
// Seed: Customers
// ============================================================================

/** @type {Array<{email: string, first: string, last: string, username: string, address1: string, city: string, state: string, postcode: string, phone: string}>} */
const CUSTOMERS = [
  {
    email: "alice@example.com",
    first: "Alice",
    last: "Smith",
    username: "alice",
    address1: "123 Main St",
    city: "New York",
    state: "NY",
    postcode: "10001",
    phone: "555-0100",
  },
  {
    email: "bob@example.com",
    first: "Bob",
    last: "Johnson",
    username: "bob",
    address1: "456 Oak Ave",
    city: "Los Angeles",
    state: "CA",
    postcode: "90001",
    phone: "555-0200",
  },
  {
    email: "carol@example.com",
    first: "Carol",
    last: "Williams",
    username: "carol",
    address1: "789 Pine Rd",
    city: "Chicago",
    state: "IL",
    postcode: "60601",
    phone: "555-0300",
  },
  {
    email: "david@example.com",
    first: "David",
    last: "Brown",
    username: "david",
    address1: "321 Elm Blvd",
    city: "Houston",
    state: "TX",
    postcode: "77001",
    phone: "555-0400",
  },
];

/**
 * Create test customers. Skips any that already exist (by email).
 *
 * @returns {Map<string, string>} Map of email → customer ID.
 */
function seedCustomers() {
  log("Seeding test customers...");

  /** @type {Map<string, string>} */
  const customerIds = new Map();

  for (const c of CUSTOMERS) {
    // Check if customer already exists by email.
    const existing = wp(
      `wc customer list --email="${c.email}" --user=1 --format=ids`,
      { ignoreError: true },
    );

    if (existing) {
      const id = existing.split(" ")[0];
      log(`  Customer ${c.email} already exists (ID: ${id}). Skipping.`);
      customerIds.set(c.email, id);
      continue;
    }

    const billing = JSON.stringify({
      first_name: c.first,
      last_name: c.last,
      address_1: c.address1,
      city: c.city,
      state: c.state,
      postcode: c.postcode,
      country: "US",
      email: c.email,
      phone: c.phone,
    });

    const shipping = JSON.stringify({
      first_name: c.first,
      last_name: c.last,
      address_1: c.address1,
      city: c.city,
      state: c.state,
      postcode: c.postcode,
      country: "US",
    });

    try {
      const output = wp(
        `wc customer create --email="${c.email}" --first_name="${c.first}" --last_name="${c.last}" --username="${c.username}" --password="password123" --billing='${billing}' --shipping='${shipping}' --user=1 --porcelain`,
        { ignoreError: true },
      );

      // --porcelain returns just the ID.
      const id = output.match(/\d+/)?.[0];
      if (id) {
        log(`  Created customer ${c.email} (ID: ${id}).`);
        customerIds.set(c.email, id);
      } else {
        log(`  WARNING: Could not parse customer ID for ${c.email}.`);
      }
    } catch {
      log(`  WARNING: Failed to create customer ${c.email}.`);
    }
  }

  return customerIds;
}

// ============================================================================
// Seed: Orders
// ============================================================================

/**
 * Get product IDs from the store for use in order line items.
 *
 * @returns {string[]} Array of product IDs.
 */
function getProductIds() {
  const output = wp("wc product list --user=1 --format=ids", {
    ignoreError: true,
  });
  if (!output) return [];
  return output.split(" ").filter(Boolean);
}

/**
 * @typedef {object} OrderDef
 * @property {string} customerEmail - Customer email (or "guest" for guest orders).
 * @property {string} status - WooCommerce order status.
 * @property {boolean} setPaid - Whether to mark as paid.
 * @property {number[]} productIndices - Indices into the product ID array.
 * @property {number[]} quantities - Quantity for each line item.
 */

/**
 * Create test orders. Idempotent — checks existing order count.
 *
 * @param {Map<string, string>} customerIds - Map of email → customer ID.
 */
function seedOrders(customerIds) {
  // Check if orders already exist.
  const count = wp("wc shop_order list --user=1 --format=count", {
    ignoreError: true,
  });
  const orderCount = parseInt(count, 10) || 0;

  if (orderCount > 0) {
    log(`Orders already seeded (${orderCount} found). Skipping.`);
    return;
  }

  const productIds = getProductIds();
  if (productIds.length === 0) {
    log("WARNING: No products found. Skipping order seeding.");
    return;
  }

  log("Seeding test orders...");

  /** @type {OrderDef[]} */
  const orders = [
    // Alice: 3 orders (completed, processing, refunded)
    {
      customerEmail: "alice@example.com",
      status: "completed",
      setPaid: true,
      productIndices: [0, 1],
      quantities: [2, 1],
    },
    {
      customerEmail: "alice@example.com",
      status: "processing",
      setPaid: true,
      productIndices: [2],
      quantities: [1],
    },
    {
      customerEmail: "alice@example.com",
      status: "refunded",
      setPaid: true,
      productIndices: [3],
      quantities: [1],
    },
    // Bob: 2 orders (completed, pending)
    {
      customerEmail: "bob@example.com",
      status: "completed",
      setPaid: true,
      productIndices: [0, 4],
      quantities: [1, 3],
    },
    {
      customerEmail: "bob@example.com",
      status: "pending",
      setPaid: false,
      productIndices: [1],
      quantities: [2],
    },
    // Carol: 2 orders (completed, on-hold)
    {
      customerEmail: "carol@example.com",
      status: "completed",
      setPaid: true,
      productIndices: [2, 3],
      quantities: [1, 1],
    },
    {
      customerEmail: "carol@example.com",
      status: "on-hold",
      setPaid: false,
      productIndices: [0],
      quantities: [1],
    },
    // Guest order (David's info but no customer account)
    {
      customerEmail: "guest",
      status: "completed",
      setPaid: true,
      productIndices: [1, 2],
      quantities: [1, 2],
    },
  ];

  for (const order of orders) {
    const lineItems = order.productIndices.map((idx, i) => ({
      product_id: parseInt(productIds[idx % productIds.length], 10),
      quantity: order.quantities[i] || 1,
    }));

    const isGuest = order.customerEmail === "guest";
    const customer = isGuest
      ? CUSTOMERS[3] // David's info for guest order
      : CUSTOMERS.find((c) => c.email === order.customerEmail);

    if (!customer) continue;

    const billing = JSON.stringify({
      first_name: customer.first,
      last_name: customer.last,
      address_1: customer.address1,
      city: customer.city,
      state: customer.state,
      postcode: customer.postcode,
      country: "US",
      email: customer.email,
      phone: customer.phone,
    });

    const customerId = isGuest
      ? "0"
      : (customerIds.get(order.customerEmail) || "0");

    const lineItemsJson = JSON.stringify(lineItems);

    try {
      const output = wp(
        `wc shop_order create --customer_id=${customerId} --status=${order.status} --set_paid=${order.setPaid} --line_items='${lineItemsJson}' --billing='${billing}' --user=1 --porcelain`,
        { ignoreError: true },
      );

      const id = output.match(/\d+/)?.[0];
      if (id) {
        const label = isGuest
          ? `guest (${customer.email})`
          : customer.email;
        log(
          `  Created order #${id} for ${label} (status: ${order.status}).`,
        );
      }
    } catch {
      log(
        `  WARNING: Failed to create order for ${order.customerEmail}.`,
      );
    }
  }
}

// ============================================================================
// Seed: Payment Gateways
// ============================================================================

function seedPaymentGateways() {
  log("Enabling test payment gateways...");

  // Cash on Delivery — orders go straight to "Processing" status.
  const codSettings = JSON.stringify({
    enabled: "yes",
    title: "Cash on delivery",
    description: "Pay with cash upon delivery.",
    instructions: "Pay with cash upon delivery.",
    enable_for_methods: [],
    enable_for_virtual: "yes",
  });

  wp(`option update woocommerce_cod_settings '${codSettings}' --format=json`, {
    ignoreError: true,
  });
  log("  Enabled: Cash on Delivery (COD).");

  // Direct Bank Transfer (BACS) — orders go to "On hold" status.
  const bacsSettings = JSON.stringify({
    enabled: "yes",
    title: "Direct bank transfer",
    description: "Make your payment directly into our bank account.",
    instructions:
      "Make your payment directly into our bank account. Your order will not be shipped until the funds have cleared.",
    accounts: [],
  });

  wp(
    `option update woocommerce_bacs_settings '${bacsSettings}' --format=json`,
    { ignoreError: true },
  );
  log("  Enabled: Direct Bank Transfer (BACS).");

  // Flush the payment gateway transients so WC picks up the changes.
  wp("transient delete wc_payment_gateways", { ignoreError: true });
}

// ============================================================================
// Main
// ============================================================================

function main() {
  console.log("");
  log("=== Seeding WooCommerce development data ===");
  console.log("");

  seedPermalinks();
  seedWooCommercePages();
  seedPaymentGateways();
  seedProducts();
  const customerIds = seedCustomers();
  seedOrders(customerIds);

  console.log("");
  log("=== Seeding complete ===");
  log("");
  log("Test accounts (password: password123):");
  for (const c of CUSTOMERS) {
    log(`  ${c.email} — ${c.first} ${c.last}`);
  }
  log("");
  log("Email logs: WP Admin > Tools > WP Mail Log");
  console.log("");
}

main();
