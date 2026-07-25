/**
 * Refresh tab — one-click Database + Uploads transfer.
 *
 * No backend of its own: it drives the existing wphaven_db_transfer flow for
 * every table (begin → chunk… → finalize) and then the wphaven_uploads_sync
 * flow (plan → batch…), in the chosen direction. A dynamic typed phrase
 * ("I am pushing to <env>" / "I am pulling from <env>") is required in either
 * direction before the buttons enable. It never touches code.
 */
(function () {
  "use strict";

  var cfg = window.wphavenRefresh || {};
  var i18n = cfg.i18n || {};

  var targetSelect = document.getElementById("wphaven-refresh-target");
  var confirmInput = document.getElementById("wphaven-refresh-confirm");
  var actionButtons = Array.prototype.slice.call(document.querySelectorAll(".wphaven-refresh-action"));
  var progressBox = document.querySelector(".wphaven-refresh-progress");
  var progressBar = document.querySelector(".wphaven-refresh-progress-bar");
  var progressLabel = document.querySelector(".wphaven-refresh-progress-label");
  var logBox = document.querySelector(".wphaven-refresh-log");

  if (!targetSelect || !confirmInput || !actionButtons.length) {
    return;
  }

  function fmt(template) {
    var args = Array.prototype.slice.call(arguments, 1);
    var auto = 0;
    return String(template).replace(/%(?:(\d+)\$)?s/g, function (match, position) {
      var index = position ? parseInt(position, 10) - 1 : auto++;
      return args[index] !== undefined ? args[index] : "";
    });
  }

  function targetName() {
    return targetSelect.options[targetSelect.selectedIndex].text;
  }

  function requiredPhrase(direction) {
    return fmt(direction === "pull" ? i18n.pullPhrase : i18n.pushPhrase, targetName());
  }

  function sync(busy) {
    actionButtons.forEach(function (btn) {
      var direction = btn.dataset.direction;
      btn.textContent = fmt(direction === "pull" ? i18n.pullFrom : i18n.pushTo, targetName());
      btn.disabled = busy || confirmInput.value.trim() !== requiredPhrase(direction);
    });
    confirmInput.disabled = busy;
    targetSelect.disabled = busy;
  }

  function log(line) {
    if (logBox) {
      logBox.textContent += line + "\n";
      logBox.scrollTop = logBox.scrollHeight;
    }
  }

  function setProgress(fraction, label) {
    if (progressBox) {
      progressBox.style.display = "block";
    }
    if (progressBar) {
      progressBar.style.width = Math.max(0, Math.min(1, fraction || 0)) * 100 + "%";
    }
    if (progressLabel) {
      progressLabel.textContent = label || "";
    }
  }

  /** POST one unit of work to a reused ajax action. */
  function post(action, nonce, direction, params) {
    var body = new FormData();
    body.append("action", action);
    body.append("nonce", nonce);
    body.append("target", targetSelect.value);
    body.append("direction", direction);
    Object.keys(params).forEach(function (key) {
      body.append(key, params[key]);
    });
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (res) {
      return res.json();
    });
  }

  function db(direction, params) {
    return post(cfg.db.action, cfg.db.nonce, direction, params);
  }

  function uploads(direction, params) {
    return post(cfg.uploads.action, cfg.uploads.nonce, direction, params);
  }

  // --- Database: begin -> chunk... -> finalize per table --------------------

  function transferTable(direction, base) {
    return db(direction, { phase: "begin", base: base, offset: 0 }).then(function (res) {
      if (!res || !res.success) {
        throw new Error((res && res.data && res.data.message) || i18n.error);
      }
      var data = res.data;

      function loop() {
        if (data.done) {
          return Promise.resolve();
        }
        return db(direction, { phase: data.phase, base: base, offset: data.offset }).then(function (r) {
          if (!r || !r.success) {
            throw new Error((r && r.data && r.data.message) || i18n.error);
          }
          data = r.data;
          return loop();
        });
      }

      return loop();
    });
  }

  function runDatabase(direction) {
    var tables = cfg.tables || [];
    var chain = Promise.resolve();
    tables.forEach(function (base, i) {
      chain = chain
        .then(function () {
          setProgress((i / (tables.length || 1)) * 0.5, fmt(i18n.dbPhase, base, i + 1, tables.length));
          return transferTable(direction, base);
        })
        .catch(function (err) {
          log(fmt(i18n.tableFail, base, err.message || i18n.error));
        });
    });
    return chain;
  }

  // --- Uploads: plan -> batch... --------------------------------------------

  function runUploads(direction) {
    return uploads(direction, { phase: "plan", overwrite: 1, register: 0 }).then(function (res) {
      if (!res || !res.success) {
        throw new Error((res && res.data && res.data.message) || i18n.error);
      }
      var total = res.data.total;
      if (!total) {
        return;
      }
      var token = res.data.token;
      var cursor = { index: 0, offset: 0 };

      function loop() {
        return uploads(direction, {
          phase: "batch",
          token: token,
          fileIndex: cursor.index,
          fileOffset: cursor.offset,
        }).then(function (r) {
          if (!r || !r.success) {
            throw new Error((r && r.data && r.data.message) || i18n.error);
          }
          var data = r.data;
          if (data.warning) {
            log(fmt(i18n.warn, data.path || "", data.warning));
          }
          setProgress(0.5 + (data.total ? data.index / data.total : 1) * 0.5, fmt(i18n.uploadsPhase, data.index, data.total));
          if (data.done) {
            return;
          }
          cursor = { index: data.index, offset: data.offset };
          return loop();
        });
      }

      return loop();
    });
  }

  function run(direction) {
    if (confirmInput.value.trim() !== requiredPhrase(direction)) {
      return;
    }

    sync(true);
    if (logBox) {
      logBox.textContent = "";
    }
    setProgress(0, "");

    runDatabase(direction)
      .then(function () {
        return runUploads(direction);
      })
      .then(function () {
        setProgress(1, i18n.allDone);
        if (direction === "pull") {
          window.setTimeout(function () {
            window.location.reload();
          }, 1200);
        }
      })
      .catch(function (err) {
        log(err.message || i18n.error);
      })
      .then(function () {
        confirmInput.value = "";
        sync(false);
      });
  }

  // --- Wire up --------------------------------------------------------------

  confirmInput.addEventListener("input", function () {
    sync(false);
  });
  targetSelect.addEventListener("change", function () {
    sync(false);
  });
  actionButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      run(btn.dataset.direction);
    });
  });

  sync(false);
})();
