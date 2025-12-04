/**
 * Settings Page Interactions
 */
document.addEventListener("DOMContentLoaded", function () {
  const selectors = {
    radios: document.querySelectorAll(".wph-mode-selector"),
    smtpRows: document.querySelectorAll(".wph-smtp-row"),
  };

  /**
   * Deduplicate identical admin notices to avoid double rendering.
   */
  (function dedupeNotices() {
    const notices = document.querySelectorAll(".notice");
    const seen = new Set();
    notices.forEach((n) => {
      const text = n.textContent.trim();
      const key = n.className + "::" + text;
      if (seen.has(key)) {
        n.parentNode && n.parentNode.removeChild(n);
      } else {
        seen.add(key);
      }
    });
  })();

  /**
   * Toggles visibility of SMTP configuration rows based on selected mode.
   * Uses standard WP 'hidden' class logic, but applied to the row (TR).
   */
  function toggleSmtpFields() {
    const selected = document.querySelector(".wph-mode-selector:checked");

    // Safety check if element exists
    if (!selected) return;

    const isSmtp = selected.value === "smtp_override";

    selectors.smtpRows.forEach(function (row) {
      if (isSmtp) {
        // Remove the WP 'hidden' class to show
        row.classList.remove("hidden");
        // Ensure display style doesn't override class
        row.style.display = "";
      } else {
        // Add the WP 'hidden' class to hide
        row.classList.add("hidden");
      }
    });
  }

  // Initialize listeners
  if (selectors.radios.length > 0) {
    selectors.radios.forEach(function (radio) {
      radio.addEventListener("change", toggleSmtpFields);
    });

    // Run once on load to ensure state matches PHP output
    toggleSmtpFields();
  }

  /**
   * Cleans the URL of test-related query parameters after page load
   * to prevent the notice from persisting on subsequent refreshes.
   */
  function cleanUrlAfterTest() {
    const url = new URL(window.location.href);
    // Do nothing if the test param isn't present
    if (!url.searchParams.has("wphaven_connect_test")) {
      return;
    }

    url.searchParams.delete("wphaven_connect_test");
    url.searchParams.delete("wphaven_connect_message");

    // Replace the current history state with the cleaned URL
    history.replaceState(null, "", url.toString());
  }

  // Clean the URL on page load
  cleanUrlAfterTest();

  /**
   * Toggle visibility of suppress notice extra strings field row
   * when the suppress notices checkbox changes.
   */
  function setupSuppressNoticesToggle() {
    const checkbox = document.getElementById(
      "wph_suppress_notices_checkbox"
    );
    const fieldRow = document.querySelector(".wph-suppress-notice-extra-strings-row");

    if (!checkbox || !fieldRow) {
      return;
    }

    checkbox.addEventListener("change", function () {
      fieldRow.classList.toggle("hidden", !this.checked);
    });
  }

  setupSuppressNoticesToggle();
});
