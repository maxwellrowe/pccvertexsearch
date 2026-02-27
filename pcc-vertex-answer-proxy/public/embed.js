// public/embed.js
// Usage: <script src="https://YOURDOMAIN/embed.js" data-endpoint="https://YOURDOMAIN/api/answer.php"></script>
(() => {
  const script = document.currentScript;
  const endpoint = script?.dataset?.endpoint;
  if (!endpoint) return;

  const SESSION_KEY = "pcc_vertex_embed_session";
  const ROOT_ID = "pcc-vertex-widget";
  const MODAL_ID = "pccVertexWidgetModal";

  if (document.getElementById(ROOT_ID)) return;

  const root = document.createElement("div");
  root.id = ROOT_ID;
  root.innerHTML = `
    <button type="button" class="btn btn-primary position-fixed bottom-0 end-0 m-4 shadow" data-bs-toggle="modal" data-bs-target="#${MODAL_ID}">
      Ask a Question
    </button>

    <div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-labelledby="${MODAL_ID}Label" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5" id="${MODAL_ID}Label">Ask Lance O'Lot a Question</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <label class="form-label" for="pccw-q">What would you like to ask?</label>
            <input id="pccw-q" class="form-control" type="text" placeholder="e.g., how do I get started at PCC?" />
            <div id="pccw-status" class="small text-muted mt-2"></div>
            <div id="pccw-error" class="alert alert-danger d-none mt-3"></div>

            <div id="pccw-result" class="card shadow-sm d-none mt-3">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h3 class="h5 mb-0">Answer</h3>
                  <span id="pccw-meta" class="badge text-bg-secondary"></span>
                </div>

                <div id="pccw-answer" class="mb-3"></div>
                <div id="pccw-anchors" class="small d-none mb-2"></div>

                <div id="pccw-cites-wrap" class="d-none">
                  <h4 class="h6">Citations</h4>
                  <ol id="pccw-cites" class="small"></ol>
                </div>

                <div id="pccw-refs-wrap" class="d-none mt-3">
                  <details>
                    <summary class="h6 mb-2">References</summary>
                    <ol id="pccw-refs" class="small mb-0"></ol>
                  </details>
                </div>

                <div id="pccw-search-wrap" class="d-none mt-3">
                  <h4 class="h6">Search Results</h4>
                  <ul id="pccw-search" class="list-group list-group-flush small"></ul>
                </div>

                <div id="pccw-fu-wrap" class="d-none mt-3">
                  <h4 class="h6">Follow-up questions</h4>
                  <div id="pccw-fu" class="d-flex flex-wrap gap-2"></div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" id="pccw-new" class="btn btn-outline-secondary">New chat</button>
            <button type="button" id="pccw-ask" class="btn btn-primary">Ask</button>
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(root);

  const el = {
    modal: root.querySelector(`#${MODAL_ID}`),
    q: root.querySelector("#pccw-q"),
    ask: root.querySelector("#pccw-ask"),
    newChat: root.querySelector("#pccw-new"),
    status: root.querySelector("#pccw-status"),
    error: root.querySelector("#pccw-error"),
    result: root.querySelector("#pccw-result"),
    meta: root.querySelector("#pccw-meta"),
    answer: root.querySelector("#pccw-answer"),
    anchors: root.querySelector("#pccw-anchors"),
    citesWrap: root.querySelector("#pccw-cites-wrap"),
    cites: root.querySelector("#pccw-cites"),
    refsWrap: root.querySelector("#pccw-refs-wrap"),
    refs: root.querySelector("#pccw-refs"),
    searchWrap: root.querySelector("#pccw-search-wrap"),
    search: root.querySelector("#pccw-search"),
    fuWrap: root.querySelector("#pccw-fu-wrap"),
    fu: root.querySelector("#pccw-fu"),
  };

  if (!window.bootstrap?.Modal) {
    console.error("Bootstrap Modal JS is required for embed.js");
    return;
  }

  const widgetModal = window.bootstrap.Modal.getOrCreateInstance(el.modal);
  let referenceLookup = new Map();

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (m) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[m]));
  }

  function setError(msg) {
    if (!msg) {
      el.error.classList.add("d-none");
      el.error.textContent = "";
      return;
    }
    el.error.textContent = msg;
    el.error.classList.remove("d-none");
  }

  function setLoading(on) {
    el.ask.disabled = on;
    el.status.textContent = on ? "Thinking… (Lance is trotting to the sources)" : "";
  }

  function resetForAsk() {
    setError("");
    el.result.classList.add("d-none");
    el.meta.textContent = "";
    el.answer.textContent = "";

    el.anchors.innerHTML = "";
    el.anchors.classList.add("d-none");

    el.cites.innerHTML = "";
    el.citesWrap.classList.add("d-none");

    el.refs.innerHTML = "";
    el.refsWrap.classList.add("d-none");

    el.search.innerHTML = "";
    el.searchWrap.classList.add("d-none");

    el.fu.innerHTML = "";
    el.fuWrap.classList.add("d-none");

    referenceLookup = new Map();
  }

  function renderAnswer(raw) {
    const text = String(raw || "");
    if (!text) {
      el.answer.textContent = "(No answer returned.)";
      return;
    }

    if (window.marked && window.DOMPurify) {
      try {
        const html = window.marked.parse(text, { breaks: true, gfm: true });
        el.answer.innerHTML = window.DOMPurify.sanitize(html);
        return;
      } catch (e) {}
    }

    el.answer.innerHTML = escapeHtml(text).replace(/\n/g, "<br>");
  }

  function renderCitations(citations) {
    if (!Array.isArray(citations) || citations.length === 0) {
      el.citesWrap.classList.add("d-none");
      return;
    }

    citations.forEach((c) => {
      const li = document.createElement("li");
      const title = c?.title || c?.uri || "Source";
      const uri = c?.uri || "";
      li.innerHTML = uri
        ? `<a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>`
        : escapeHtml(title);
      el.cites.appendChild(li);
    });
    el.citesWrap.classList.remove("d-none");
  }

  function renderReferences(references) {
    if (!Array.isArray(references) || references.length === 0) {
      el.refsWrap.classList.add("d-none");
      return;
    }

    references.forEach((r, i) => {
      const n = i + 1;
      const li = document.createElement("li");
      li.id = `pccw-ref-${n}`;
      const title = r?.title || r?.uri || "Reference";
      const uri = r?.uri || "";
      const snippet = r?.snippet ? `<div class="text-muted">${escapeHtml(r.snippet)}</div>` : "";
      li.innerHTML = uri
        ? `<span class="badge text-bg-light border me-1">[${n}]</span><a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>${snippet}`
        : `<span class="badge text-bg-light border me-1">[${n}]</span>${escapeHtml(title)}${snippet}`;
      el.refs.appendChild(li);

      const key = `${uri}|${String(title).toLowerCase()}`;
      if (uri || title) referenceLookup.set(key, n);
      if (uri) referenceLookup.set(`${uri}|`, n);
    });

    el.refsWrap.classList.remove("d-none");
  }

  function findReferenceNumber(citation) {
    const title = citation?.title || citation?.uri || "Source";
    const uri = citation?.uri || "";
    return referenceLookup.get(`${uri}|${String(title).toLowerCase()}`)
      || referenceLookup.get(`${uri}|`)
      || null;
  }

  function renderAnswerAnchors(citations) {
    if (!Array.isArray(citations) || citations.length === 0) {
      el.anchors.classList.add("d-none");
      return;
    }

    const seen = new Set();
    citations.forEach((c, i) => {
      const refNum = findReferenceNumber(c);
      const a = document.createElement("a");
      a.className = "badge text-bg-light border text-decoration-none me-1";
      a.textContent = `[${refNum || (i + 1)}]`;
      a.title = c?.title || "Reference";

      if (refNum) {
        a.href = `#pccw-ref-${refNum}`;
        if (!seen.has(refNum)) {
          a.addEventListener("click", () => {
            const target = document.getElementById(`pccw-ref-${refNum}`);
            if (!target) return;
            target.classList.add("bg-warning-subtle");
            setTimeout(() => target.classList.remove("bg-warning-subtle"), 900);
          });
          el.anchors.appendChild(a);
          seen.add(refNum);
        }
      } else if (c?.uri) {
        a.href = c.uri;
        a.target = "_blank";
        a.rel = "noopener";
        el.anchors.appendChild(a);
      }
    });

    if (el.anchors.children.length) {
      el.anchors.classList.remove("d-none");
    }
  }

  function renderSearch(results) {
    if (!Array.isArray(results) || results.length === 0) {
      el.searchWrap.classList.add("d-none");
      return;
    }

    results.forEach((r) => {
      const li = document.createElement("li");
      li.className = "list-group-item px-0";
      const title = r?.title || r?.uri || "Result";
      const uri = r?.uri || "";
      const description = r?.description || r?.snippet || "";
      const urlLine = uri ? `<div class="small text-dark">${escapeHtml(uri)}</div>` : "";
      const descriptionLine = description ? `<div class="text-muted mt-1">${escapeHtml(description)}</div>` : "";
      li.innerHTML = uri
        ? `<a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>${urlLine}${descriptionLine}`
        : `${escapeHtml(title)}${descriptionLine}`;
      el.search.appendChild(li);
    });

    el.searchWrap.classList.remove("d-none");
  }

  function renderFollowUps(items) {
    if (!Array.isArray(items) || items.length === 0) {
      el.fuWrap.classList.add("d-none");
      return;
    }

    items.forEach((rq) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn btn-outline-secondary btn-sm rounded-pill";
      btn.textContent = rq;
      btn.addEventListener("click", () => {
        el.q.value = rq;
        ask(rq);
      });
      el.fu.appendChild(btn);
    });

    el.fuWrap.classList.remove("d-none");
  }

  async function ask(prefillQuestion = "") {
    const text = String(prefillQuestion || el.q.value).trim();
    if (!text) {
      setError("Please enter a question.");
      return;
    }

    widgetModal.show();
    resetForAsk();
    setLoading(true);

    try {
      const send = async (sid) => {
        const res = await fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body: new URLSearchParams({ q: text, sid }),
          credentials: "omit"
        });
        const data = await res.json().catch(() => ({}));
        return { res, data };
      };

      let sid = localStorage.getItem(SESSION_KEY) || "";
      let { res, data } = await send(sid);

      if (!res.ok && sid && (res.status === 400 || res.status === 404)) {
        localStorage.removeItem(SESSION_KEY);
        ({ res, data } = await send(""));
      }

      if (!res.ok) {
        throw new Error(data?.error || `Request failed (${res.status})`);
      }

      renderAnswer(data.answer);
      renderCitations(data.citations || []);
      renderReferences(data.references || []);
      renderAnswerAnchors(data.citations || []);
      renderSearch(data.search_results || []);
      renderFollowUps(data.related_questions || []);

      if (data?.meta?.session) {
        localStorage.setItem(SESSION_KEY, data.meta.session);
      }

      el.meta.textContent = `${data?.meta?.elapsed_ms ?? "?"}ms • ${data?.meta?.cache ?? "?"}`;
      el.result.classList.remove("d-none");
      el.q.value = "";
      el.q.focus();
      el.status.textContent = "";
    } catch (e) {
      setError(e?.message || "Something went wrong.");
    } finally {
      setLoading(false);
    }
  }

  el.ask.addEventListener("click", () => ask());
  el.q.addEventListener("keydown", (e) => {
    if (e.key === "Enter") ask();
  });
  el.newChat.addEventListener("click", () => {
    localStorage.removeItem(SESSION_KEY);
    setError("");
    el.status.textContent = "Started a new chat session.";
  });
})();
