/**
 * Segmentflow WooCommerce Admin JS
 *
 * Handles the settings page UI interactions:
 * - Connect button behavior (redirect to Segmentflow dashboard)
 * - Connection status polling after auth return
 * - Disconnect confirmation
 *
 * Loaded via wp_enqueue_script() on the WooCommerce > Settings > Segmentflow tab.
 * Compiled to IIFE bundle by tsdown, available as window.SegmentflowAdmin.
 *
 * @package Segmentflow_WooCommerce
 */

interface SegmentflowAdminConfig {
  connectUrl: string;
  isConnected: boolean;
  nonce: string;
  ajaxUrl: string;
}

declare const segmentflowAdmin: SegmentflowAdminConfig;

/**
 * Initialize the admin page interactions.
 */
function init(): void {
  const connectButton = document.getElementById("segmentflow-connect");
  const disconnectButton = document.getElementById("segmentflow-disconnect");

  if (connectButton) {
    connectButton.addEventListener("click", handleConnect);
  }

  if (disconnectButton) {
    disconnectButton.addEventListener("click", handleDisconnect);
  }

  // Check if we just returned from the auth flow.
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get("connected") === "1") {
    handleAuthReturn(urlParams.get("poll_token") ?? "");
  }
}

/**
 * Handle the connect button click.
 * Redirects the user to the Segmentflow dashboard to initiate the auth flow.
 */
function handleConnect(event: Event): void {
  event.preventDefault();
  window.location.href = segmentflowAdmin.connectUrl;
}

/**
 * Handle the disconnect button click.
 * Shows a confirmation dialog before disconnecting.
 */
function handleDisconnect(event: Event): void {
  event.preventDefault();

  if (!confirm("Are you sure you want to disconnect from Segmentflow?")) {
    return;
  }

  // TODO: Implement AJAX disconnect call.
  // POST to admin-ajax.php with action=segmentflow_disconnect and nonce.
}

/**
 * Handle the return from the auth flow.
 * Polls the Segmentflow API for connection status and write key.
 */
function handleAuthReturn(_pollToken: string): void {
  // TODO: Implement polling for write key.
  // Poll GET /integrations/woocommerce/status with the poll token.
  // On success, reload the page to show connected state.
}

// Initialize when DOM is ready.
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
} else {
  init();
}
