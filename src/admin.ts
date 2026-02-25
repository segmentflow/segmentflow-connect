/**
 * Segmentflow Connect Admin JS
 *
 * Handles the settings page UI interactions:
 * - Connect button behavior (redirect to Segmentflow dashboard)
 * - Connection status polling after auth return
 * - Disconnect confirmation and AJAX call
 *
 * Loaded via wp_enqueue_script() on the Segmentflow settings page.
 * Compiled to IIFE bundle by tsdown.
 *
 * @package Segmentflow_Connect
 */

interface SegmentflowAdminConfig {
  connectUrl: string;
  isConnected: boolean;
  nonce: string;
  ajaxUrl: string;
  apiHost: string;
  pollToken: string;
}

declare const segmentflowAdmin: SegmentflowAdminConfig;

/** Polling configuration. */
const POLL_INTERVAL_MS = 2500;
const MAX_POLL_ATTEMPTS = 12; // 30 seconds total

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

  // Check if we just returned from the auth flow with a poll token.
  if (segmentflowAdmin.pollToken) {
    handleAuthReturn(segmentflowAdmin.pollToken);
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
 *
 * Polls the Segmentflow API for connection status using the poll token.
 * On success, saves the write key via WordPress AJAX and reloads the page.
 * Shows a connecting spinner during polling and an error message on timeout.
 */
async function handleAuthReturn(pollToken: string): Promise<void> {
  if (!pollToken) return;

  // Clean up URL parameters immediately.
  const cleanUrl = new URL(window.location.href);
  cleanUrl.searchParams.delete("connected");
  cleanUrl.searchParams.delete("poll_token");
  history.replaceState({}, "", cleanUrl.toString());

  // Show connecting state in the UI.
  showConnectingState();

  let attempts = 0;

  while (attempts < MAX_POLL_ATTEMPTS) {
    attempts++;

    try {
      const response = await fetch(
        `${segmentflowAdmin.apiHost}/api/v1/integrations/connect/status`,
        {
          method: "GET",
          headers: {
            "X-Poll-Token": pollToken,
            Accept: "application/json",
          },
        },
      );

      if (!response.ok) {
        // 404/410 -- token expired or already consumed.
        if (response.status === 404 || response.status === 410) {
          showConnectionError("Connection token expired. Please try again.");
          return;
        }
        // Other server errors -- keep polling.
        await sleep(POLL_INTERVAL_MS);
        continue;
      }

      const data: { connected?: boolean; write_key?: string; organization_name?: string } =
        await response.json();

      if (data.connected && data.write_key) {
        // Save the write key via WordPress AJAX.
        await saveConnection(data.write_key, data.organization_name ?? "");
        window.location.reload();
        return;
      }
    } catch {
      // Network error -- keep polling.
    }

    await sleep(POLL_INTERVAL_MS);
  }

  // Timed out after all attempts.
  showConnectionError("Connection timed out. Please try connecting again.");
}

/**
 * Save the connection data to WordPress via AJAX.
 */
async function saveConnection(writeKey: string, organizationName: string): Promise<void> {
  const formData = new FormData();
  formData.append("action", "segmentflow_save_connection");
  formData.append("nonce", segmentflowAdmin.nonce);
  formData.append("write_key", writeKey);
  formData.append("organization_name", organizationName);

  const response = await fetch(segmentflowAdmin.ajaxUrl, {
    method: "POST",
    body: formData,
  });

  const data: { success: boolean; data?: { message?: string } } = await response.json();
  if (!data.success) {
    throw new Error(data.data?.message ?? "Failed to save connection.");
  }
}

/**
 * Show the connecting/polling state in the admin UI.
 */
function showConnectingState(): void {
  const connectBtn = document.getElementById("segmentflow-connect");
  if (connectBtn) {
    connectBtn.classList.add("segmentflow-connect-btn--loading");
    connectBtn.textContent = "Connecting\u2026";
    connectBtn.setAttribute("aria-disabled", "true");
    (connectBtn as HTMLAnchorElement).style.pointerEvents = "none";
  }

  // Replace the disconnected status banner with a connecting banner.
  const disconnectedBanner = document.querySelector(".segmentflow-connection-status--disconnected");
  if (disconnectedBanner) {
    disconnectedBanner.className =
      "segmentflow-connection-status segmentflow-connection-status--connecting";
    disconnectedBanner.innerHTML =
      '<span class="dashicons dashicons-update segmentflow-spin"></span>' +
      "<strong>Connecting to Segmentflow\u2026</strong>";
  }
}

/**
 * Show a connection error message in the admin UI.
 */
function showConnectionError(message: string): void {
  const banner = document.querySelector(".segmentflow-connection-status--connecting");
  if (banner) {
    banner.className = "segmentflow-connection-status segmentflow-connection-status--disconnected";
    banner.innerHTML =
      '<span class="dashicons dashicons-warning"></span>' +
      `<strong>${escapeHtml(message)}</strong>`;
  }

  const connectBtn = document.getElementById("segmentflow-connect");
  if (connectBtn) {
    connectBtn.classList.remove("segmentflow-connect-btn--loading");
    connectBtn.textContent = "Try Again";
    connectBtn.removeAttribute("aria-disabled");
    (connectBtn as HTMLAnchorElement).style.pointerEvents = "";
  }
}

/**
 * Escape HTML entities to prevent XSS in dynamic UI messages.
 */
function escapeHtml(text: string): string {
  const el = document.createElement("span");
  el.textContent = text;
  return el.innerHTML;
}

/**
 * Promise-based sleep utility.
 */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// Initialize when DOM is ready.
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
} else {
  init();
}
