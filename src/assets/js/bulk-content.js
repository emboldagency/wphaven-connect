/**
 * Bulk content transfer — push/pull the checked rows in a posts/pages/CPT list
 * table to or from a chosen environment. Reuses the per-post content-transfer
 * ajax action, looping it over the selected post IDs.
 */
(function () {
  "use strict";

  var cfg = window.wphavenBulkContent || {};
  var i18n = cfg.i18n || {};

  var container = document.querySelector(".wphaven-bulk-content");
  if (!container) {
    return;
  }

  var targetSelect = container.querySelector(".wphaven-bulk-target");
  var overwrite = container.querySelector(".wphaven-bulk-overwrite");
  var pushBtn = container.querySelector(".wphaven-bulk-push");
  var pullBtn = container.querySelector(".wphaven-bulk-pull");
  var statusEl = container.querySelector(".wphaven-bulk-status");

  if (!targetSelect || !pushBtn || !pullBtn) {
    return;
  }

  function fmt(template) {
    var args = Array.prototype.slice.call(arguments, 1);
    var auto = 0;
    return String(template).replace(/%(?:(\d+)\$)?s/g, function (m, pos) {
      var i = pos ? parseInt(pos, 10) - 1 : auto++;
      return args[i] !== undefined ? args[i] : "";
    });
  }

  function targetName() {
    return targetSelect.options[targetSelect.selectedIndex].text;
  }

  function relabel() {
    pushBtn.textContent = fmt(i18n.pushTo, targetName());
    pullBtn.textContent = fmt(i18n.pullFrom, targetName());
  }

  function checkedIds() {
    return Array.prototype.slice
      .call(document.querySelectorAll('#the-list input[name="post[]"]:checked'))
      .map(function (cb) {
        return cb.value;
      });
  }

  function setBusy(busy) {
    pushBtn.disabled = busy;
    pullBtn.disabled = busy;
    targetSelect.disabled = busy;
    if (overwrite) {
      overwrite.disabled = busy;
    }
  }

  function status(text) {
    if (statusEl) {
      statusEl.textContent = text;
    }
  }

  function transferOne(direction, postId) {
    var body = new FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    body.append("direction", direction);
    body.append("target", targetSelect.value);
    body.append("post_id", postId);
    body.append("preview", 0);
    body.append("overwrite_conflict", overwrite && overwrite.checked ? 1 : 0);
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (r) {
      return r.json();
    });
  }

  function run(direction) {
    var ids = checkedIds();
    if (!ids.length) {
      window.alert(i18n.none);
      return;
    }
    if (!window.confirm(fmt(direction === "pull" ? i18n.confirmPull : i18n.confirmPush, ids.length, targetName()))) {
      return;
    }

    setBusy(true);
    var done = 0;
    var ok = 0;
    var skipped = 0;
    var failed = 0;

    var chain = Promise.resolve();
    ids.forEach(function (id) {
      chain = chain
        .then(function () {
          status(fmt(i18n.working, done + 1, ids.length));
          return transferOne(direction, id);
        })
        .then(function (res) {
          done++;
          if (res && res.success) {
            ok++;
          } else if (res && res.data && (res.data.code === "wphaven_transfer_conflict" || res.data.code === "wphaven_no_link")) {
            skipped++;
          } else {
            failed++;
          }
        })
        .catch(function () {
          done++;
          failed++;
        });
    });

    chain.then(function () {
      status(fmt(i18n.done, ok, skipped, failed));
      setBusy(false);
    });
  }

  targetSelect.addEventListener("change", relabel);
  pushBtn.addEventListener("click", function () {
    run("push");
  });
  pullBtn.addEventListener("click", function () {
    run("pull");
  });

  relabel();
})();
