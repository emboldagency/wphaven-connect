/**
 * Content Transfer — "Send to / Update from Production".
 *
 * One file drives both editors. In Gutenberg it registers a PluginPostStatusInfo
 * panel (no JSX — plain wp.element calls, since the plugin has no JS build step).
 * In the Classic editor the buttons are printed by PHP (post_submitbox_start) and
 * this script wires up their click handlers.
 *
 * Each action previews first (a dry run that returns a summary/diff), asks the
 * user to confirm, then commits. Conflicts (production changed more recently) are
 * surfaced in the confirm step and require an explicit overwrite.
 */
(function () {
  "use strict";

  var cfg = window.wphavenContentTransfer || {};
  var i18n = cfg.i18n || {};

  /** Minimal sprintf supporting %s. */
  function fmt(template, value) {
    return String(template).replace("%s", value !== undefined ? value : "");
  }

  /**
   * POST to admin-ajax and resolve with the parsed JSON response.
   */
  function request(params) {
    var body = new FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    Object.keys(params).forEach(function (key) {
      body.append(key, params[key]);
    });

    return fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: body,
    }).then(function (res) {
      return res.json();
    });
  }

  /**
   * Build a human summary from a preview/diff payload.
   */
  function summarize(diff, direction) {
    var lines = [];
    if (diff.is_new) {
      lines.push("Creates a new " + (direction === "pull" ? "local" : "remote") + " item (as a draft).");
    } else {
      lines.push("Updates an existing item (#" + (diff.target_id || "?") + ").");
      if (diff.changed_meta && diff.changed_meta.length) {
        lines.push(diff.changed_meta.length + " custom field(s) will change.");
      }
    }
    if (diff.media_count) {
      lines.push(diff.media_count + " image(s) referenced.");
    }
    if (diff.terms && diff.terms.length) {
      lines.push(diff.terms.length + " term(s) will be assigned.");
    }
    return lines.join("\n");
  }

  /**
   * Report a successful transfer. A pull overwrote the local content, so the
   * editor is now showing stale data — reload to reveal it.
   */
  function onSuccess(direction, report) {
    if (direction === "pull") {
      report(i18n.pulled);
      window.setTimeout(function () {
        window.location.reload();
      }, 900);
    } else {
      report(i18n.sent);
    }
  }

  /**
   * Run the full preview → confirm → commit flow for one direction.
   *
   * @param {string} direction "push" or "pull"
   * @param {number} postId
   * @param {function(string)} report status callback
   */
  function runFlow(direction, postId, target, targetName, report) {
    if (!postId) {
      report(i18n.error + " (no post id)");
      return;
    }
    if (!target) {
      report(i18n.error);
      return;
    }

    report(i18n.working);

    request({ direction: direction, post_id: postId, target: target, preview: 1 })
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || i18n.error);
        }

        var diff = res.data;
        var intro = fmt(direction === "pull" ? i18n.confirmPull : i18n.confirmSend, targetName);
        var message = intro + "\n\n" + summarize(diff, direction);
        var overwriteConflict = false;

        if (diff.conflict) {
          message += "\n\n⚠ " + i18n.conflict;
          overwriteConflict = true;
        }

        if (!window.confirm(message)) {
          report("");
          return null;
        }

        report(i18n.working);
        return request({
          direction: direction,
          post_id: postId,
          target: target,
          preview: 0,
          overwrite_conflict: overwriteConflict ? 1 : 0,
        });
      })
      .then(function (res) {
        if (res === null) {
          return;
        }
        if (!res || !res.success) {
          var msg = (res && res.data && res.data.message) || i18n.error;
          // A conflict returned from commit means confirm-and-retry.
          if (res && res.data && res.data.code === "wphaven_transfer_conflict") {
            if (window.confirm(i18n.conflict)) {
              report(i18n.working);
              request({
                direction: direction,
                post_id: postId,
                target: target,
                preview: 0,
                overwrite_conflict: 1,
              }).then(function (r) {
                if (r && r.success) {
                  onSuccess(direction, report);
                } else {
                  report((r && r.data && r.data.message) || i18n.error);
                }
              });
              return;
            }
            report("");
            return;
          }
          throw new Error(msg);
        }
        onSuccess(direction, report);
      })
      .catch(function (err) {
        report(err.message || i18n.error);
      });
  }

  /**
   * Resolve the current post id from the block editor store, falling back to the
   * localized value (Classic editor).
   */
  function currentPostId() {
    if (window.wp && wp.data && wp.data.select("core/editor")) {
      var id = wp.data.select("core/editor").getCurrentPostId();
      if (id) {
        return id;
      }
    }
    return cfg.postId || 0;
  }

  /**
   * Warn (Gutenberg only) if there are unsaved edits, since the transfer reads
   * the saved post from the database.
   */
  function hasUnsavedEdits() {
    if (window.wp && wp.data && wp.data.select("core/editor")) {
      return wp.data.select("core/editor").isEditedPostDirty();
    }
    return false;
  }

  // --- Classic editor -------------------------------------------------------

  function initClassic() {
    var container = document.querySelector(".wphaven-content-transfer");
    if (!container) {
      return;
    }
    var status = container.querySelector(".wphaven-transfer-status");
    var report = function (text) {
      if (status) {
        status.textContent = text;
      }
    };

    var targetSelect = container.querySelector(".wphaven-content-target");
    var target = function () {
      return targetSelect ? targetSelect.value : (cfg.environments && cfg.environments[0]) || "";
    };
    var targetName = function () {
      return targetSelect ? targetSelect.options[targetSelect.selectedIndex].text : target();
    };

    var send = container.querySelector(".wphaven-send-to-production");
    var pull = container.querySelector(".wphaven-update-from-production");
    if (send) {
      send.addEventListener("click", function () {
        runFlow("push", currentPostId(), target(), targetName(), report);
      });
    }
    if (pull) {
      pull.addEventListener("click", function () {
        runFlow("pull", currentPostId(), target(), targetName(), report);
      });
    }
  }

  // --- Gutenberg ------------------------------------------------------------

  function initGutenberg() {
    var el = wp.element.createElement;
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginPostStatusInfo = wp.editPost.PluginPostStatusInfo;
    var Button = wp.components.Button;
    var useState = wp.element.useState;

    var environments = cfg.environments || [];

    var Panel = function () {
      var statePair = useState("");
      var statusText = statePair[0];
      var setStatus = statePair[1];
      var targetPair = useState(environments[0] || "");
      var target = targetPair[0];
      var setTarget = targetPair[1];

      var trigger = function (direction) {
        if (hasUnsavedEdits()) {
          setStatus("Save your changes first.");
          return;
        }
        runFlow(direction, currentPostId(), target, target, setStatus);
      };

      return el(
        PluginPostStatusInfo,
        { className: "wphaven-content-transfer" },
        el(
          "div",
          {
            style: {
              display: "flex",
              flexDirection: "column",
              alignItems: "stretch",
              gap: "8px",
              width: "100%",
            },
          },
          environments.length > 1
            ? el(
                "select",
                {
                  value: target,
                  style: { width: "100%" },
                  onChange: function (e) {
                    setTarget(e.target.value);
                  },
                },
                environments.map(function (label) {
                  return el("option", { value: label, key: label }, label);
                })
              )
            : null,
          el(
            Button,
            {
              variant: "secondary",
              isBusy: statusText === i18n.working,
              style: { justifyContent: "center" },
              onClick: function () {
                trigger("push");
              },
            },
            i18n.sendTitle
          ),
          el(
            Button,
            {
              variant: "secondary",
              style: { justifyContent: "center" },
              onClick: function () {
                trigger("pull");
              },
            },
            i18n.pullTitle
          ),
          statusText ? el("p", { className: "description", style: { margin: 0 } }, statusText) : null
        )
      );
    };

    registerPlugin("wphaven-content-transfer", { render: Panel });
  }

  if (window.wp && wp.plugins && wp.editPost && wp.element) {
    initGutenberg();
  } else if (document.readyState !== "loading") {
    initClassic();
  } else {
    document.addEventListener("DOMContentLoaded", initClassic);
  }
})();
