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
  var syncBtn = container.querySelector(".wphaven-sync-new");
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
    if (syncBtn) {
      syncBtn.textContent = fmt(i18n.syncFrom, targetName());
    }
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
    if (syncBtn) {
      syncBtn.disabled = busy;
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

  // --- Sync new: find content on the target this site doesn't have yet -----

  function pullNew(contentId) {
    var body = new FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    body.append("direction", "pull");
    body.append("target", targetSelect.value);
    body.append("content_id", contentId);
    body.append("post_type", cfg.postType);
    body.append("preview", 0);
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (r) {
      return r.json();
    });
  }

  function closeModal(modal) {
    modal.parentNode.removeChild(modal);
  }

  function openSyncModal(items) {
    var overlay = document.createElement("div");
    overlay.style.cssText =
      "position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;display:flex;align-items:center;justify-content:center;";

    var box = document.createElement("div");
    box.style.cssText =
      "background:#fff;border-radius:4px;max-width:600px;width:90%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 4px 20px rgba(0,0,0,0.3);";

    var header = document.createElement("div");
    header.style.cssText = "padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;";
    var title = document.createElement("h2");
    title.style.cssText = "margin:0;font-size:16px;";
    title.textContent = fmt(i18n.modalTitle, targetName());
    header.appendChild(title);
    box.appendChild(header);

    var selectAllLabel = document.createElement("label");
    selectAllLabel.style.cssText = "display:block;padding:10px 20px;border-bottom:1px solid #eee;";
    var selectAllCb = document.createElement("input");
    selectAllCb.type = "checkbox";
    selectAllCb.checked = true;
    selectAllLabel.appendChild(selectAllCb);
    selectAllLabel.appendChild(document.createTextNode(" " + i18n.selectAll));
    box.appendChild(selectAllLabel);

    var list = document.createElement("div");
    list.style.cssText = "overflow-y:auto;padding:6px 20px;flex:1;";

    var rowCbs = [];
    items.forEach(function (item) {
      var row = document.createElement("label");
      row.style.cssText = "display:block;padding:6px 0;border-bottom:1px solid #f0f0f0;";
      var cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = true;
      cb.value = item.content_id;
      rowCbs.push(cb);
      row.appendChild(cb);
      var hint = item.adopt_id ? fmt(i18n.willAdopt, item.adopt_id) : i18n.willCreate;
      row.appendChild(document.createTextNode(" " + (item.title || item.slug || item.content_id) + " — " + hint));
      list.appendChild(row);
    });
    box.appendChild(list);

    selectAllCb.addEventListener("change", function () {
      rowCbs.forEach(function (cb) {
        cb.checked = selectAllCb.checked;
      });
    });

    var footer = document.createElement("div");
    footer.style.cssText = "padding:14px 20px;border-top:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;gap:10px;";
    var footerStatus = document.createElement("span");
    footerStatus.className = "description";
    footerStatus.style.cssText = "flex:1;";
    var cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.className = "button";
    cancelBtn.textContent = i18n.cancel;
    var pullBtnModal = document.createElement("button");
    pullBtnModal.type = "button";
    pullBtnModal.className = "button button-primary";

    var updatePullLabel = function () {
      var checked = rowCbs.filter(function (cb) {
        return cb.checked;
      }).length;
      pullBtnModal.textContent = fmt(i18n.pullSelected, checked);
      pullBtnModal.disabled = !checked;
    };
    rowCbs.forEach(function (cb) {
      cb.addEventListener("change", updatePullLabel);
    });
    updatePullLabel();

    footer.appendChild(footerStatus);
    footer.appendChild(cancelBtn);
    footer.appendChild(pullBtnModal);
    box.appendChild(footer);

    cancelBtn.addEventListener("click", function () {
      closeModal(overlay);
    });

    pullBtnModal.addEventListener("click", function () {
      var selected = rowCbs.filter(function (cb) {
        return cb.checked;
      });
      if (!selected.length) {
        return;
      }

      cancelBtn.disabled = true;
      pullBtnModal.disabled = true;
      selectAllCb.disabled = true;

      var done = 0;
      var ok = 0;
      var failed = 0;
      var chain = Promise.resolve();

      selected.forEach(function (cb) {
        chain = chain
          .then(function () {
            footerStatus.textContent = fmt(i18n.pullingNew, done + 1, selected.length);
            return pullNew(cb.value);
          })
          .then(function (res) {
            done++;
            if (res && res.success) {
              ok++;
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
        footerStatus.textContent = fmt(i18n.doneNew, ok, failed);
        cancelBtn.textContent = i18n.cancel;
        cancelBtn.disabled = false;
        cancelBtn.addEventListener("click", function () {
          window.location.reload();
        });
      });
    });

    overlay.appendChild(box);
    document.body.appendChild(overlay);
  }

  function scanNew() {
    if (!syncBtn) {
      return;
    }
    setBusy(true);
    status(fmt(i18n.scanning, targetName()));

    var body = new FormData();
    body.append("action", cfg.scanAction);
    body.append("nonce", cfg.nonce);
    body.append("post_type", cfg.postType);
    body.append("target", targetSelect.value);

    fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body })
      .then(function (r) {
        return r.json();
      })
      .then(function (res) {
        setBusy(false);
        if (!res || !res.success) {
          status((res && res.data && res.data.message) || i18n.error);
          return;
        }

        var data = res.data;
        if (!data.items || !data.items.length) {
          status(fmt(i18n.noneNew, targetName()));
          return;
        }

        var message = fmt(i18n.foundNew, data.items.length, targetName());
        if (data.truncated) {
          message += fmt(i18n.truncated, data.scanned);
        }
        status(message);
        openSyncModal(data.items);
      })
      .catch(function (err) {
        setBusy(false);
        status(err.message || i18n.error);
      });
  }

  targetSelect.addEventListener("change", relabel);
  pushBtn.addEventListener("click", function () {
    run("push");
  });
  pullBtn.addEventListener("click", function () {
    run("pull");
  });
  if (syncBtn) {
    syncBtn.addEventListener("click", scanNew);
  }

  relabel();
})();
