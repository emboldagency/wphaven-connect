/**
 * Database Transfer tab — chunked, browser-orchestrated table transfer.
 *
 * The whole operation is driven from here: for each selected table the script
 * loops begin → chunk… → finalize, calling one admin-ajax unit of work per
 * request and advancing an offset until the server reports done. This keeps any
 * single request small (no timeouts) and gives live progress. The destructive
 * action buttons stay disabled until the exact confirmation phrase is typed.
 */
(function () {
  "use strict";

  var cfg = window.wphavenDbTransfer || {};
  var i18n = cfg.i18n || {};

  var confirmInput = document.getElementById("wphaven-db-confirm");
  var selectAll = document.querySelector(".wphaven-db-select-all");
  var actionButtons = Array.prototype.slice.call(document.querySelectorAll(".wphaven-db-action"));
  var progressBox = document.querySelector(".wphaven-db-progress");
  var progressBar = document.querySelector(".wphaven-db-progress-bar");
  var progressLabel = document.querySelector(".wphaven-db-progress-label");
  var logBox = document.querySelector(".wphaven-db-log");

  if (!confirmInput || !actionButtons.length) {
    return;
  }

  function selectedTables() {
    return Array.prototype.slice
      .call(document.querySelectorAll(".wphaven-db-table:checked"))
      .map(function (cb) {
        return cb.value;
      });
  }

  function phraseFor(direction) {
    return direction === "pull" ? cfg.pullPhrase : cfg.pushPhrase;
  }

  /** Enable each button only when the typed phrase matches its direction. */
  function syncButtons() {
    var typed = confirmInput.value.trim();
    actionButtons.forEach(function (btn) {
      btn.disabled = typed !== phraseFor(btn.dataset.direction);
    });
  }

  function setBusy(busy) {
    actionButtons.forEach(function (btn) {
      btn.disabled = busy || confirmInput.value.trim() !== phraseFor(btn.dataset.direction);
    });
    confirmInput.disabled = busy;
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

  /** Minimal sprintf supporting %s (sequential) and %1$s (positional). */
  function fmt(template) {
    var args = Array.prototype.slice.call(arguments, 1);
    var auto = 0;
    return String(template).replace(/%(?:(\d+)\$)?s/g, function (match, position) {
      var index = position ? parseInt(position, 10) - 1 : auto++;
      return args[index] !== undefined ? args[index] : "";
    });
  }

  /** POST one unit of work to admin-ajax. */
  function step(params) {
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

  /** Run all selected tables sequentially. */
  function run(direction) {
    var tables = selectedTables();
    if (!tables.length) {
      window.alert(i18n.noTables);
      return;
    }
    if (confirmInput.value.trim() !== phraseFor(direction)) {
      return; // Button should be disabled anyway.
    }
    if (!window.confirm(direction === "pull" ? i18n.confirmPull : i18n.confirmPush)) {
      return;
    }

    setBusy(true);
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
      setBusy(false);
      confirmInput.value = "";
      syncButtons();
    });
  }

  // --- Wire up --------------------------------------------------------------

  confirmInput.addEventListener("input", syncButtons);

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

  syncButtons();
})();
