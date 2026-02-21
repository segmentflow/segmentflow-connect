/**
 * Segmentflow Connect Admin JS
 *
 * Handles the settings page UI interactions:
 * - Connect button behavior (redirect to Segmentflow dashboard)
 * - Connection status polling after auth return
 * - Disconnect confirmation and AJAX call
 *
 * Loaded via wp_enqueue_script() on the Segmentflow settings page.
 * Compiled to IIFE bundle by tsdown, available as window.SegmentflowAdmin.
 *
 * @package Segmentflow_Connect
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
 * Shows a confirmation dialog before disconnecting via AJAX.
 */
function handleDisconnect(event: Event): void {
  event.preventDefault();

  if (!confirm("Are you sure you want to disconnect from Segmentflow?")) {
    return;
  }

  const formData = new FormData();
  formData.append("action", "segmentflow_disconnect");
  formData.append("nonce", segmentflowAdmin.nonce);

  fetch(segmentflowAdmin.ajaxUrl, {
    method: "POST",
    body: formData,
  })
    .then((response) => response.json())
    .then((data: { success: boolean }) => {
      if (data.success) {
        window.location.reload();
      }
    })
    .catch(() => {
      // Reload anyway -- the disconnect may have succeeded.
      window.location.reload();
    });
}

/**
 * Handle the return from the auth flow.
 * Polls the Segmentflow API for connection status and write key.
 */
function handleAuthReturn(_pollToken: string): void {
  // TODO: Implement polling for write key.
  // The server-side handle_return() in Segmentflow_Auth processes the poll token.
  // On success, the page reloads to show connected state.
}

// Initialize when DOM is ready.
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
} else {
  init();
}
