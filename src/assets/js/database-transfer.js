/**
 * Database Transfer tab — chunked, browser-orchestrated table transfer to a
 * chosen environment.
 *
 * For each selected table the script loops begin → chunk… → finalize, one
 * admin-ajax unit per request, advancing an offset until the server says done.
 * The typed confirmation phrase is required only when pushing to the
 * environment labeled "production"; everything else uses a plain confirm.
 */
(function () {
  "use strict";

  var cfg = window.wphavenDbTransfer || {};
  var i18n = cfg.i18n || {};

  var confirmInput = document.getElementById("wphaven-db-confirm");
  var targetSelect = document.getElementById("wphaven-db-target");
  var selectAll = document.querySelector(".wphaven-db-select-all");
  var actionButtons = Array.prototype.slice.call(document.querySelectorAll(".wphaven-db-action"));
  var progressBox = document.querySelector(".wphaven-db-progress");
  var progressBar = document.querySelector(".wphaven-db-progress-bar");
  var progressLabel = document.querySelector(".wphaven-db-progress-label");
  var logBox = document.querySelector(".wphaven-db-log");

  if (!actionButtons.length || !targetSelect) {
    return;
  }

  function target() {
    return targetSelect.value;
  }

  function targetName() {
    return targetSelect.options[targetSelect.selectedIndex].text;
  }

  function selectedTables() {
    return Array.prototype.slice
      .call(document.querySelectorAll(".wphaven-db-table:checked"))
      .map(function (cb) {
        return cb.value;
      });
  }

  /** A typed phrase is required only to push to production. */
  function needsPhrase(direction) {
    return direction === "push" && target() === cfg.productionLabel;
  }

  function phraseOk() {
    return confirmInput && confirmInput.value.trim() === cfg.pushPhrase;
  }

  function fmt(template) {
    var args = Array.prototype.slice.call(arguments, 1);
    var auto = 0;
    return String(template).replace(/%(?:(\d+)\$)?s/g, function (match, position) {
      var index = position ? parseInt(position, 10) - 1 : auto++;
      return args[index] !== undefined ? args[index] : "";
    });
  }

  /** Relabel buttons for the current target and enable/disable per phrase rule. */
  function sync(busy) {
    actionButtons.forEach(function (btn) {
      var direction = btn.dataset.direction;
      btn.textContent = fmt(direction === "pull" ? i18n.pullFrom : i18n.pushTo, targetName());
      var blocked = busy || (needsPhrase(direction) && !phraseOk());
      btn.disabled = blocked;
    });
    if (confirmInput) {
      confirmInput.disabled = busy;
    }
    if (selectAll) {
      selectAll.disabled = busy;
    }
  }

  function log(line) {
    if (logBox) {
      logBox.textContent += line + "\n";
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

  function step(params) {
    var body = new FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    body.append("target", target());
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

  /** Run begin → chunk… → finalize for a single table. */
  function transferTable(direction, base, index, count) {
    return step({ direction: direction, phase: "begin", base: base, offset: 0 }).then(function (res) {
      if (!res || !res.success) {
        throw new Error((res && res.data && res.data.message) || i18n.error);
      }
      var data = res.data;
      var total = data.total != null ? data.total : null;

      function loop() {
        if (data.done) {
          return Promise.resolve();
        }
        return step({ direction: direction, phase: data.phase, base: base, offset: data.offset }).then(function (r) {
          if (!r || !r.success) {
            throw new Error((r && r.data && r.data.message) || i18n.error);
          }
          data = r.data;
          if (data.total != null) {
            total = data.total;
          }
          var rows = total != null ? data.offset + "/" + total : data.offset + "";
          setProgress(
            (index + (total ? Math.min(1, data.offset / total) : 0.5)) / count,
            fmt("Table %1$s/%2$s: ", index + 1, count) + fmt(i18n.working, base, rows + " rows")
          );
          return loop();
        });
      }

      return loop();
    });
  }

  function run(direction) {
    var tables = selectedTables();
    if (!tables.length) {
      window.alert(i18n.noTables);
      return;
    }
    if (needsPhrase(direction) && !phraseOk()) {
      return; // Button should be disabled anyway.
    }
    var confirmMsg = direction === "pull"
      ? fmt(i18n.confirmPull, targetName())
      : fmt(i18n.confirmPush, targetName());
    if (!window.confirm(confirmMsg)) {
      return;
    }

    sync(true);
    if (logBox) {
      logBox.textContent = "";
    }
    setProgress(0, "");

    var chain = Promise.resolve();
    tables.forEach(function (base, i) {
      chain = chain
        .then(function () {
          return transferTable(direction, base, i, tables.length);
        })
        .then(function () {
          log(fmt(i18n.tableDone, base));
        })
        .catch(function (err) {
          log(fmt(i18n.tableFail, base, err.message || i18n.error));
        });
    });

    chain.then(function () {
      setProgress(1, i18n.allDone);
      if (confirmInput) {
        confirmInput.value = "";
      }
      sync(false);
    });
  }

  // --- Wire up --------------------------------------------------------------

  if (confirmInput) {
    confirmInput.addEventListener("input", function () {
      sync(false);
    });
  }
  targetSelect.addEventListener("change", function () {
    sync(false);
  });

  if (selectAll) {
    selectAll.addEventListener("change", function () {
      document.querySelectorAll(".wphaven-db-table").forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  }

  actionButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      run(btn.dataset.direction);
    });
  });

  sync(false);
})();
