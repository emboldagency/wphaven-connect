/**
 * Uploads tab — additive, chunked media sync.
 *
 * Plan once (diff the two file manifests), then loop a batch step that transfers
 * a byte-budget at a time until the server reports done. Large files stream in
 * ranges; small files batch together. Additive only — nothing is ever deleted —
 * so a native confirm is enough (no typed phrase).
 */
(function () {
  "use strict";

  var cfg = window.wphavenUploadsSync || {};
  var i18n = cfg.i18n || {};

  var actionButtons = Array.prototype.slice.call(document.querySelectorAll(".wphaven-uploads-action"));
  var overwrite = document.getElementById("wphaven-uploads-overwrite");
  var targetSelect = document.getElementById("wphaven-uploads-target");
  var progressBox = document.querySelector(".wphaven-uploads-progress");
  var progressBar = document.querySelector(".wphaven-uploads-progress-bar");
  var progressLabel = document.querySelector(".wphaven-uploads-progress-label");
  var logBox = document.querySelector(".wphaven-uploads-log");

  if (!actionButtons.length || !targetSelect) {
    return;
  }

  function target() {
    return targetSelect.value;
  }

  function targetName() {
    return targetSelect.options[targetSelect.selectedIndex].text;
  }

  function relabel() {
    actionButtons.forEach(function (btn) {
      btn.textContent = fmt(btn.dataset.direction === "pull" ? i18n.pullFrom : i18n.pushTo, targetName());
    });
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

  function setBusy(busy) {
    actionButtons.forEach(function (btn) {
      btn.disabled = busy;
    });
    if (overwrite) {
      overwrite.disabled = busy;
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

  function run(direction) {
    var confirmMsg = direction === "pull"
      ? fmt(i18n.confirmPull, targetName())
      : fmt(i18n.confirmPush, targetName());
    if (!window.confirm(confirmMsg)) {
      return;
    }

    setBusy(true);
    if (logBox) {
      logBox.textContent = "";
    }
    setProgress(0, i18n.planning);

    step({ phase: "plan", direction: direction, overwrite: overwrite && overwrite.checked ? 1 : 0 })
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || i18n.error);
        }
        var total = res.data.total;
        if (!total) {
          setProgress(1, i18n.nothing);
          return null;
        }

        var token = res.data.token;
        var cursor = { index: 0, offset: 0 };

        function loop() {
          return step({
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
            setProgress(data.total ? data.index / data.total : 1, fmt(i18n.working, data.index, data.total));
            if (data.done) {
              setProgress(1, fmt(i18n.done, total));
              return;
            }
            cursor = { index: data.index, offset: data.offset };
            return loop();
          });
        }

        return loop();
      })
      .catch(function (err) {
        log(err.message || i18n.error);
      })
      .then(function () {
        setBusy(false);
      });
  }

  actionButtons.forEach(function (btn) {
    btn.addEventListener("click", function () {
      run(btn.dataset.direction);
    });
  });

  targetSelect.addEventListener("change", relabel);
  relabel();
})();
