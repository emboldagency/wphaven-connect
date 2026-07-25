/**
 * Compare tab — read-only divergence report against a chosen environment.
 *
 * Runs in two phases: stats (table row counts + uploads totals) render first,
 * then a heavier content pass (per-post-type divergence). Everything is built
 * with DOM nodes so table/type names are inserted as text, never HTML.
 */
(function () {
  "use strict";

  var cfg = window.wphavenCompare || {};
  var i18n = cfg.i18n || {};

  var targetSelect = document.getElementById("wphaven-compare-target");
  var runButton = document.querySelector(".wphaven-compare-run");
  var spinner = document.querySelector(".wphaven-compare-spinner");
  var statusEl = document.querySelector(".wphaven-compare-status");
  var resultsEl = document.querySelector(".wphaven-compare-results");

  if (!targetSelect || !runButton) {
    return;
  }

  function make(tag, props, children) {
    var node = document.createElement(tag);
    if (props) {
      Object.keys(props).forEach(function (k) {
        if (k === "style") {
          node.setAttribute("style", props[k]);
        } else if (k === "class") {
          node.className = props[k];
        } else {
          node[k] = props[k];
        }
      });
    }
    (children || []).forEach(function (c) {
      node.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
    });
    return node;
  }

  function num(n) {
    return (n == null ? 0 : n).toLocaleString();
  }

  function bytes(n) {
    n = n || 0;
    var units = ["B", "KB", "MB", "GB", "TB"];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) {
      n /= 1024;
      i++;
    }
    return (i === 0 ? n : n.toFixed(1)) + " " + units[i];
  }

  function busy(on, message) {
    runButton.disabled = on;
    targetSelect.disabled = on;
    if (spinner) {
      spinner.classList.toggle("is-active", on);
    }
    if (statusEl) {
      statusEl.textContent = message || "";
    }
  }

  function post(phase) {
    var body = new FormData();
    body.append("action", cfg.action);
    body.append("nonce", cfg.nonce);
    body.append("target", targetSelect.value);
    body.append("phase", phase);
    return fetch(cfg.ajaxUrl, { method: "POST", credentials: "same-origin", body: body }).then(function (r) {
      return r.json();
    });
  }

  function section(title) {
    return make("h3", { style: "margin:20px 0 6px;" }, [title]);
  }

  function headerRow(cells) {
    return make("tr", null, cells.map(function (c) {
      return make("th", { style: "text-align:left;" }, [c]);
    }));
  }

  function delta(node, value) {
    if (value !== 0) {
      node.style.color = "#b32d2e";
      node.style.fontWeight = "600";
    }
    return node;
  }

  function renderTables(tables) {
    resultsEl.appendChild(section(i18n.tables));
    var body = [headerRow(["", i18n.here, i18n.there, "Δ"])];
    tables.forEach(function (t) {
      var d = (t.here || 0) - (t.there || 0);
      body.push(make("tr", null, [
        make("td", null, [make("code", null, [t.base])]),
        make("td", null, [t.here == null ? "—" : num(t.here)]),
        make("td", null, [t.there == null ? "—" : num(t.there)]),
        delta(make("td", null, [(d > 0 ? "+" : "") + num(d)]), d),
      ]));
    });
    resultsEl.appendChild(make("table", { class: "widefat striped", style: "max-width:640px;" }, [make("tbody", null, body)]));
  }

  function renderUploads(uploads) {
    resultsEl.appendChild(section(i18n.uploads));
    var here = uploads.here || { files: 0, bytes: 0 };
    var there = uploads.there || { files: 0, bytes: 0 };
    var d = (here.files || 0) - (there.files || 0);
    var body = [
      headerRow(["", i18n.here, i18n.there, "Δ"]),
      make("tr", null, [
        make("td", null, [i18n.files]),
        make("td", null, [num(here.files) + " (" + bytes(here.bytes) + ")"]),
        make("td", null, [num(there.files) + " (" + bytes(there.bytes) + ")"]),
        delta(make("td", null, [(d > 0 ? "+" : "") + num(d)]), d),
      ]),
    ];
    resultsEl.appendChild(make("table", { class: "widefat striped", style: "max-width:640px;" }, [make("tbody", null, body)]));
  }

  function renderContent(content) {
    resultsEl.appendChild(section(i18n.content));

    var diverged = content.filter(function (row) {
      return row.differ || row.only_here || row.only_there;
    });
    if (!diverged.length) {
      resultsEl.appendChild(make("p", { class: "description" }, [i18n.identical]));
      return;
    }

    // Headline: "12 product, 5 post differ" style summary.
    var summary = diverged
      .filter(function (r) { return r.differ; })
      .map(function (r) { return num(r.differ) + " " + r.type; })
      .join(", ");
    if (summary) {
      resultsEl.appendChild(make("p", { style: "font-size:15px;margin:4px 0 10px;" }, [summary + " " + (i18n.differ || "differ").toLowerCase()]));
    }

    var body = [headerRow([i18n.type, i18n.here, i18n.there, i18n.differ, i18n.onlyHere, i18n.onlyThere])];
    content.forEach(function (r) {
      body.push(make("tr", null, [
        make("td", null, [make("code", null, [r.type])]),
        make("td", null, [num(r.here)]),
        make("td", null, [num(r.there)]),
        delta(make("td", null, [num(r.differ)]), r.differ),
        delta(make("td", null, [num(r.only_here)]), r.only_here),
        delta(make("td", null, [num(r.only_there)]), r.only_there),
      ]));
    });
    resultsEl.appendChild(make("table", { class: "widefat striped", style: "max-width:760px;" }, [make("tbody", null, body)]));
  }

  function run() {
    resultsEl.innerHTML = "";
    busy(true, i18n.comparing);

    post("stats")
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || i18n.error);
        }
        renderTables(res.data.tables || []);
        renderUploads(res.data.uploads || {});
        busy(true, i18n.analyzing);
        return post("content");
      })
      .then(function (res) {
        if (!res || !res.success) {
          throw new Error((res && res.data && res.data.message) || i18n.error);
        }
        renderContent(res.data.content || []);
        busy(false, "");
      })
      .catch(function (err) {
        busy(false, err.message || i18n.error);
      });
  }

  runButton.addEventListener("click", run);
})();
