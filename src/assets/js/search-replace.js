/**
 * Search & Replace tab — serialized-safe find/replace across selected tables,
 * one table per request, with a dry-run mode that only counts.
 */
(function () {
  "use strict";

  var cfg = window.wphavenSearchReplace || {};
  var i18n = cfg.i18n || {};

  var searchInput = document.getElementById("wphaven-sr-search");
  var replaceInput = document.getElementById("wphaven-sr-replace");
  var selectAll = document.querySelector(".wphaven-sr-select-all");
  var actionButtons = Array.prototype.slice.call(document.querySelectorAll(".wphaven-sr-action"));
  var progressBox = document.querySelector(".wphaven-sr-progress");
  var progressBar = document.querySelector(".wphaven-sr-progress-bar");
  var progressLabel = document.querySelector(".wphaven-sr-progress-label");
  var logBox = document.querySelector(".wphaven-sr-log");

  if (!searchInput || !actionButtons.length) {
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

  function selectedTables() {
    return Array.prototype.slice
      .call(document.querySelectorAll(".wphaven-sr-table:checked"))
      .map(function (cb) {
        return cb.value;
      });
  }

  function setBusy(busy) {
    actionButtons.forEach(function (btn) {
      btn.disabled = busy;
    });
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

  function step(base, dry) {
    var body = new FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    body.append("search", searchInput.value);
    body.append("replace", replaceInput ? replaceInput.value : "");
    body.append("base", base);
    body.append("dry", dry ? 1 : 0);
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (res) {
      return res.json();
    });
  }

  function run(mode) {
    var dry = mode === "dry";
    if (!searchInput.value) {
      window.alert(i18n.noSearch);
      return;
    }
    var tables = selectedTables();
    if (!tables.length) {
      window.alert(i18n.noTables);
      return;
    }
    if (!dry && !window.confirm(fmt(i18n.confirm, searchInput.value, replaceInput ? replaceInput.value : ""))) {
      return;
    }

    setBusy(true);
    if (logBox) {
      logBox.textContent = "";
    }
    setProgress(0, "");

    var totals = { rows: 0, replacements: 0 };
    var chain = Promise.resolve();

    tables.forEach(function (base, i) {
      chain = chain
        .then(function () {
          setProgress(i / tables.length, fmt(i18n.working, base, i + 1, tables.length));
          return step(base, dry);
        })
        .then(function (res) {
          if (!res || !res.success) {
            throw new Error((res && res.data && res.data.message) || i18n.error);
          }
          totals.rows += res.data.rows;
          totals.replacements += res.data.replacements;
          if (res.data.replacements > 0) {
            log(fmt(i18n.tableResult, base, res.data.replacements, res.data.rows));
          }
        })
        .catch(function (err) {
          log(fmt(i18n.tableFail, base, err.message || i18n.error));
        });
    });

    chain.then(function () {
      setProgress(1, fmt(dry ? i18n.dryDone : i18n.liveDone, totals.replacements, totals.rows));
      setBusy(false);
    });
  }

  if (selectAll) {
    selectAll.addEventListener("change", function () {
      document.querySelectorAll(".wphaven-sr-table").forEach(function (cb) {
        cb.checked = selectAll.checked;
      });
    });
  }

  actionButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      run(btn.dataset.mode);
    });
  });
})();
