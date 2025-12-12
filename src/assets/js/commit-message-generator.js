/**
 * WordPress Plugin & Theme Update Commit Message Generator
 * Integrated plugin version - retrieves data via AJAX instead of DOM parsing
 */

(function () {
  "use strict";

  console.log("[Commit Gen] Script loaded, waiting for Alpine...");

  // Wait for Alpine.js to be ready
  const initializeApp = () => {
    if (typeof Alpine === "undefined") {
      console.warn("[Commit Gen] Alpine not ready, retrying...");
      setTimeout(initializeApp, 100);
      return;
    }

    console.log("[Commit Gen] Alpine ready, initializing...");
    const page = wphavenCommitGen.page;

    // Initialize Alpine store
    Alpine.store("wp_puc_gen", {
      contextMenu: {
        active: false,
        top: 0,
        left: 0,
        selectedSlug: null,
      },
      page,
      items: [],
      loading: true,
      error: null,

      toggleContextMenu() {
        Alpine.store("wp_puc_gen").contextMenu.active =
          !Alpine.store("wp_puc_gen").contextMenu.active;
      },

      copyCommitMessage(stageAll = false) {
        const itemSlug = this.contextMenu.selectedSlug;
        const itemData = this.items.find((item) => item.slug === itemSlug);

        if (itemData) {
          const message = stageAll
            ? itemData.commitMessages.all
            : itemData.commitMessages.item;

          // Use modern Clipboard API with fallback
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard
              .writeText(message)
              .then(() => {
                this.showNotification("Commit message copied!");
              })
              .catch(() => {
                fallbackCopy(message);
              });
          } else {
            fallbackCopy(message);
          }
        }
        this.toggleContextMenu();
      },

      showNotification(message) {
        // Create a simple notification (can be enhanced)
        const notification = document.createElement("div");
        notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: #46b450;
                    color: white;
                    padding: 12px 20px;
                    border-radius: 4px;
                    z-index: 10000;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                `;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
          notification.remove();
        }, 3000);
      },
    });

    // Fetch items via AJAX
    loadItems();
    generateElements();
    document.addEventListener("contextmenu", handleRightClick);

    console.log("[Commit Gen] Initialization complete");
  };

  // Initialize when DOM is ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeApp);
  } else {
    initializeApp();
  }

  /**
   * Fallback for clipboard copy (for older browsers or when modern API fails)
   */
  function fallbackCopy(text) {
    const textarea = document.createElement("textarea");
    textarea.value = text;
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand("copy");
      Alpine.store("wp_puc_gen").showNotification("Commit message copied!");
    } catch (err) {
      console.error("Failed to copy:", err);
    }
    document.body.removeChild(textarea);
  }

  /**
   * Load items via AJAX instead of parsing DOM
   */
  function loadItems() {
    const store = Alpine.store("wp_puc_gen");

    console.log("[Commit Gen] Loading items for page:", store.page);

    fetch(wphavenCommitGen.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
      },
      body: new URLSearchParams({
        action: "wphaven_get_update_items",
        nonce: wphavenCommitGen.nonce,
        page: store.page,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        console.log("[Commit Gen] AJAX response:", data);
        if (data.success && data.data.items) {
          console.log("[Commit Gen] Found items:", data.data.items.length);
          populateItems(data.data.items);
          store.loading = false;
        } else {
          console.warn("[Commit Gen] No items or failed response:", data);
          store.error = data.data?.message || "Failed to load items";
          store.loading = false;
        }
      })
      .catch((error) => {
        console.error("[Commit Gen] Error loading items:", error);
        store.error = "Network error loading items";
        store.loading = false;
      });
  }

  /**
   * Populate items with commit message data
   */
  function populateItems(itemsData) {
    const store = Alpine.store("wp_puc_gen");

    itemsData.forEach((item) => {
      if (itemIsValid(item)) {
        const { slug, versions, path, type } = item;

        // Dynamic prefix and path based on type
        const prefix = type === "theme" ? "THEME" : "PLUGIN";
        const baseDir = type === "theme" ? "themes" : "plugins";

        // Theme paths are usually just the slug, Plugins are folder/file
        const folderName = type === "theme" ? slug : path.split("/")[0];
        const gitPath = `wp-content/${baseDir}/${folderName}`;

        const commitCmd = `git commit -m "${prefix}: Update ${slug} from ${versions.current} to ${versions.new}"`;

        item.commitMessages = {
          item: `git add ${gitPath};${commitCmd}`,
          all: `git add *;${commitCmd}`,
        };

        store.items.push(item);
      }
    });
  }

  /**
   * Validate item data
   */
  function itemIsValid(data) {
    if (!data) return false;

    // Check basics
    if (!data.slug || typeof data.slug !== "string" || data.slug.trim() === "")
      return false;
    if (!data.path || typeof data.path !== "string" || data.path.trim() === "")
      return false;
    if (!data.type || typeof data.type !== "string") return false;

    // Check versions
    if (!data.versions || typeof data.versions !== "object") return false;
    const { current, new: newVersion } = data.versions;

    if (
      !current ||
      typeof current !== "string" ||
      current.trim() === "" ||
      !newVersion ||
      typeof newVersion !== "string" ||
      newVersion.trim() === ""
    ) {
      return false;
    }

    return true;
  }

  /**
   * Generate context menu elements
   */
  function generateElements() {
    const contextMenuId = "wp-puc-gen-context-menu";
    const contextMenuSelector = `#${contextMenuId}`;
    const contextMenuItemSelector = `${contextMenuSelector} .context-menu-item`;
    const contextMenuItemHoverSelector = `${contextMenuSelector} .context-menu-item:hover`;

    const contextMenuCSS = {
      position: "fixed",
      "background-color": "#fff",
      border: "1px solid #ccc",
      "box-shadow": "0 2px 8px rgba(0,0,0,0.15)",
      padding: "5px 0", // Changed padding
      "z-index": "10000",
      "border-radius": "4px",
      "min-width": "200px",
    };

    const contextMenuItemCSS = {
      cursor: "pointer",
      padding: "8px 12px",
      "user-select": "none",
      "font-size": "13px",
      "font-family":
        '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    };

    const contextMenuItemHoverCSS = {
      "background-color": "#f5f5f5",
    };

    const style = document.createElement("style");
    style.id = `${contextMenuId}_style`;
    style.textContent =
      generateCSSRule(contextMenuSelector, contextMenuCSS) +
      generateCSSRule(contextMenuItemSelector, contextMenuItemCSS) +
      generateCSSRule(contextMenuItemHoverSelector, contextMenuItemHoverCSS);

    document.head.appendChild(style);

    const contextMenu = document.createElement("div");
    contextMenu.id = contextMenuId;
    contextMenu.setAttribute("x-data", "");
    contextMenu.setAttribute("x-show", "$store.wp_puc_gen.contextMenu.active");
    contextMenu.setAttribute("x-transition:enter.duration.100", "");
    contextMenu.setAttribute("x-transition:leave.duration.75", "");
    contextMenu.setAttribute(
      "x-on:click.away",
      "$store.wp_puc_gen.toggleContextMenu"
    );
    contextMenu.setAttribute(
      "x-on:keydown.escape.window",
      "$store.wp_puc_gen.toggleContextMenu"
    );
    contextMenu.setAttribute(
      ":style",
      "{top: $store.wp_puc_gen.contextMenu.top, left: $store.wp_puc_gen.contextMenu.left}"
    );

    document.body.appendChild(contextMenu);

    const contextMenuOptions = [
      {
        text: "Copy Commit Message (Stage Item)",
        command: "$store.wp_puc_gen.copyCommitMessage()",
      },
      {
        text: "Copy Commit Message (Stage All)",
        command: "$store.wp_puc_gen.copyCommitMessage(true)",
      },
    ];

    contextMenuOptions.forEach((option) => {
      const contextMenuItem = document.createElement("div");
      contextMenuItem.classList.add("context-menu-item");
      contextMenuItem.textContent = option.text;
      contextMenuItem.setAttribute("x-on:click", option.command);
      contextMenu.appendChild(contextMenuItem);
    });

    // Add System Menu Hint
    const systemMenuHint = document.createElement("div");
    systemMenuHint.style.cssText = `
            border-top: 1px solid #eee;
            padding: 8px 12px;
            color: #888;
            font-size: 11px;
            font-style: italic;
            background: #fafafa;
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 4px;
        `;
    systemMenuHint.textContent = "Hold Shift + Right Click for System Menu";
    contextMenu.appendChild(systemMenuHint);
  }

  /**
   * Generate a CSS rule string
   */
  function generateCSSRule(selector, styles) {
    let css = `${selector} {`;
    for (const [key, value] of Object.entries(styles)) {
      css += `${key}: ${value};`;
    }
    css += "}";
    return css;
  }

  /**
   * Handle right-click context menu trigger
   * Identify the item that was right-clicked
   */
  function handleRightClick(event) {
    // Standard Behavior: If Shift is key held, bypass custom menu and show system menu
    if (event.shiftKey) {
      return;
    }

    const store = Alpine.store("wp_puc_gen");

    if (store.loading || store.error) {
      console.log(
        "[Commit Gen] Skipping right-click: loading=" +
          store.loading +
          ", error=" +
          store.error
      );
      return;
    }

    let targetElement = event.target;
    let itemSlug = null;

    // For plugins.php: Look for data-slug or data-plugin on the row
    let row = targetElement.closest("[data-slug], [data-plugin]");
    if (row) {
      itemSlug = row.dataset.slug || row.dataset.plugin?.split("/")[0];
    }

    // For themes.php: data-slug is on the .theme div
    if (!itemSlug) {
      let themeDiv = targetElement.closest("[data-slug]");
      if (themeDiv) {
        itemSlug = themeDiv.dataset.slug;
      }
    }

    // For update-core.php: Try to find checkbox value (gravitysmtp/gravitysmtp.php or twentytwentyfive)
    if (!itemSlug) {
      let tr = targetElement.closest("tr");
      let checkbox = tr?.querySelector(
        'input[type="checkbox"][name="checked[]"]'
      );
      if (checkbox && checkbox.value) {
        // Extract slug from plugin path (gravitysmtp/gravitysmtp.php -> gravitysmtp)
        // or use theme name directly (twentytwentyfive)
        const value = checkbox.value;
        itemSlug = value.includes("/") ? value.split("/")[0] : value;
      }
    }

    console.log(
      "[Commit Gen] Right-click detected. Found slug:",
      itemSlug,
      "Store items:",
      store.items.length
    );

    // If we found a slug, verify it exists in our items
    if (itemSlug && store.items.some((item) => item.slug === itemSlug)) {
      console.log(
        "[Commit Gen] Slug matched with store item, showing context menu"
      );
      event.preventDefault();

      const newContext = {
        selectedSlug: itemSlug,
        top: `${event.clientY}px`,
        left: `${event.clientX}px`,
      };

      Object.assign(store.contextMenu, newContext);
      store.toggleContextMenu();
    } else {
      console.log("[Commit Gen] Slug not found in items");
    }
  }
})();
