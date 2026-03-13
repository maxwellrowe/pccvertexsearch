// public/embed.js
// Usage: <script src="https://YOURDOMAIN/embed.js" data-endpoint="https://YOURDOMAIN/api/answer.php"></script>
(() => {
  const script = document.currentScript;
  const endpoint = script?.dataset?.endpoint;
  if (!endpoint) return;

  const SESSION_KEY = "pcc_vertex_embed_session";
  const ROOT_ID = "pcc-vertex-widget";
  const MODAL_ID = "pccVertexWidgetModal";
  const WIDGET_STYLE_ID = "pcc-vertex-widget-styles";

  if (document.getElementById(ROOT_ID)) return;

  if (!document.getElementById(WIDGET_STYLE_ID)) {
    const style = document.createElement("style");
    style.id = WIDGET_STYLE_ID;
    style.textContent = `
      .pccw-launch-icon-wrap {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 9999px;
        background: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        animation: pccwIconBounceJiggle 2.5s ease-in-out infinite;
        pointer-events: none;
      }
      .pccw-launch-icon {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 9999px;
        display: block;
        object-fit: cover;
      }
      @keyframes pccwIconBounceJiggle {
        0%, 40% { transform: translateY(0) rotate(0deg); }
        48% { transform: translateY(-6px) rotate(-5deg); }
        54% { transform: translateY(0) rotate(4deg); }
        60% { transform: translateY(-3px) rotate(-3deg); }
        66% { transform: translateY(0) rotate(2deg); }
        72% { transform: translateY(-1px) rotate(-1deg); }
        78%, 100% { transform: translateY(0) rotate(0deg); }
      }
      .pccw-modal-title {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
      }
      .pccw-modal-title-icon-wrap {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 9999px;
        background: #f1f3f5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
      }
      .pccw-modal-title-icon {
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 9999px;
        object-fit: cover;
        display: block;
      }
    `;
    document.head.appendChild(style);
  }

  const root = document.createElement("div");
  root.id = ROOT_ID;
  root.innerHTML = `
    <div class="position-fixed bottom-0 end-0 m-4 d-inline-flex align-items-center gap-2">
      <span class="pccw-launch-icon-wrap" aria-hidden="true">
        <img class="pccw-launch-icon" src="/images/lance-profile.png" alt="" />
      </span>
      <button id="pccw-launch" type="button" class="btn btn-primary shadow" data-bs-toggle="modal" data-bs-target="#${MODAL_ID}">Ask a Question</button>
    </div>

    <div class="modal fade" id="${MODAL_ID}" tabindex="-1" aria-labelledby="${MODAL_ID}Label" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5 pccw-modal-title" id="${MODAL_ID}Label">
              <span class="pccw-modal-title-icon-wrap" aria-hidden="true">
                <img class="pccw-modal-title-icon" src="/images/lance-profile.png" alt="" />
              </span>
              <span>Ask Lance O'Lot a Question</span>
            </h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="pccw-ask-controls" class="d-flex gap-2 align-items-end">
              <div id="pccw-q-wrap" class="flex-grow-1">
                <label id="pccw-q-label" class="form-label" for="pccw-q">What would you like to ask?</label>
                <input id="pccw-q" class="form-control" type="text" placeholder="e.g., how do I get started at PCC?" />
              </div>
              <button type="button" id="pccw-ask" class="btn btn-primary">Ask</button>
            </div>
            <div id="pccw-status" class="small text-muted mt-2"></div>
            <div id="pccw-error" class="alert alert-danger d-none mt-3"></div>

            <div id="pccw-result" class="card shadow-sm d-none mt-3">
              <div class="card-body">
                <div id="pccw-question-pill" class="d-none mb-2 p-1 border rounded-3 bg-light text-dark small"></div>
                <div class="border border-light-subtle rounded-3 p-2 mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Answer</h3>
                    <a id="pccw-meta-link" class="small d-none" href="#pccw-search-wrap">View Search Results</a>
                  </div>
                  <div id="pccw-answer">
                    <div id="pccw-answer-body"></div>
                    <div id="pccw-ask-another-wrap" class="d-none mt-3">
                      <button type="button" id="pccw-ask-another" class="btn btn-outline-primary">Ask Another Question</button>
                    </div>
                    <div id="pccw-answer-help" class="mt-3"></div>
                  </div>
                </div>
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
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(root);

  const el = {
    launch: root.querySelector("#pccw-launch"),
    modal: root.querySelector(`#${MODAL_ID}`),
    q: root.querySelector("#pccw-q"),
    ask: root.querySelector("#pccw-ask"),
    qWrap: root.querySelector("#pccw-q-wrap"),
    qLabel: root.querySelector("#pccw-q-label"),
    askControls: root.querySelector("#pccw-ask-controls"),
    askAnotherWrap: root.querySelector("#pccw-ask-another-wrap"),
    askAnother: root.querySelector("#pccw-ask-another"),
    newChat: root.querySelector("#pccw-new"),
    status: root.querySelector("#pccw-status"),
    error: root.querySelector("#pccw-error"),
    result: root.querySelector("#pccw-result"),
    questionPill: root.querySelector("#pccw-question-pill"),
    metaLink: root.querySelector("#pccw-meta-link"),
    answer: root.querySelector("#pccw-answer"),
    answerBody: root.querySelector("#pccw-answer-body"),
    answerHelp: root.querySelector("#pccw-answer-help"),
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
  let widgetModal = null;
  let referenceLookup = new Map();
  let markdownLoadPromise = null;

  function setAskControlsVisible(isVisible) {
    el.askControls.classList.toggle("d-none", !isVisible);
    el.qWrap.hidden = !isVisible;
    el.qLabel.hidden = !isVisible;
    el.q.hidden = !isVisible;
    el.ask.hidden = !isVisible;
    el.askAnotherWrap.classList.toggle("d-none", isVisible);
  }

  function focusQuestionInput() {
    el.q.focus({ preventScroll: true });
    el.q.scrollIntoView({ behavior: "smooth", block: "center" });
  }

  function loadBootstrapBundle() {
    return new Promise((resolve, reject) => {
      if (window.bootstrap?.Modal) {
        resolve();
        return;
      }

      const existing = document.querySelector('script[data-pcc-bootstrap-loader="1"]');
      if (existing) {
        existing.addEventListener("load", () => resolve(), { once: true });
        existing.addEventListener("error", () => reject(new Error("Failed to load Bootstrap JS.")), { once: true });
        return;
      }

      const src = script?.dataset?.bootstrapJs || "https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js";
      const tag = document.createElement("script");
      tag.src = src;
      tag.async = true;
      tag.dataset.pccBootstrapLoader = "1";
      tag.onload = () => resolve();
      tag.onerror = () => reject(new Error(`Failed to load Bootstrap JS from ${src}`));
      document.head.appendChild(tag);
    });
  }

  function loadScriptOnce(dataAttr, src) {
    return new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[data-${dataAttr}="1"]`);
      if (existing) {
        // If a matching loader is already present, avoid duplicate injection.
        resolve();
        return;
      }

      const tag = document.createElement("script");
      tag.src = src;
      tag.async = true;
      tag.dataset[dataAttr] = "1";
      tag.onload = () => resolve();
      tag.onerror = () => reject(new Error(`Failed to load ${src}`));
      document.head.appendChild(tag);
    });
  }

  async function ensureMarkdownReady() {
    if (window.marked && window.DOMPurify) {
      return;
    }
    if (!markdownLoadPromise) {
      const markedSrc = script?.dataset?.markedJs || "https://cdn.jsdelivr.net/npm/marked/marked.min.js";
      const purifySrc = script?.dataset?.dompurifyJs || "https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js";
      markdownLoadPromise = Promise.all([
        loadScriptOnce("pccMarkedLoader", markedSrc),
        loadScriptOnce("pccDompurifyLoader", purifySrc),
      ]).catch(() => {});
    }
    await markdownLoadPromise;
  }

  async function ensureModalReady() {
    if (widgetModal) {
      return widgetModal;
    }
    if (!window.bootstrap?.Modal) {
      await loadBootstrapBundle();
    }
    if (!window.bootstrap?.Modal) {
      throw new Error("Bootstrap Modal JS is required for embed.js");
    }
    widgetModal = window.bootstrap.Modal.getOrCreateInstance(el.modal);
    return widgetModal;
  }

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
    const loadingHtml = '<span class="d-inline-flex align-items-center gap-1"><svg width="12" height="12" viewBox="0 0 50 50" aria-hidden="true"><circle cx="25" cy="25" r="20" fill="none" stroke="#be1e2d" stroke-width="5" stroke-linecap="round" stroke-dasharray="31.4 31.4"><animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.9s" repeatCount="indefinite"/></circle></svg><span>Thinking… (Lance is trotting to the sources)</span></span>';
    el.status.innerHTML = on ? loadingHtml : "";
  }

  function resetForAsk() {
    setError("");
    el.result.classList.add("d-none");
    el.questionPill.textContent = "";
    el.questionPill.classList.add("d-none");
    el.metaLink.classList.add("d-none");
    el.answerBody.textContent = "";
    el.answerHelp.innerHTML = "";

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
      el.answerBody.textContent = "(No answer returned.)";
      el.answerHelp.innerHTML = "";
      return;
    }

    const ctaMatch = text.match(/<hr class="my-2"\s*\/>\s*<strong>Need additional help\?<\/strong>[\s\S]*$/i);
    const bodyRaw = ctaMatch ? text.slice(0, ctaMatch.index).trimEnd() : text;
    const helpRaw = ctaMatch ? ctaMatch[0] : "";

    if (window.marked && window.DOMPurify) {
      try {
        const html = window.marked.parse(bodyRaw, { breaks: true, gfm: true });
        el.answerBody.innerHTML = window.DOMPurify.sanitize(html);
        el.answerHelp.innerHTML = helpRaw ? window.DOMPurify.sanitize(helpRaw) : "";
        return;
      } catch (e) {}
    }

    el.answerBody.innerHTML = escapeHtml(bodyRaw).replace(/\n/g, "<br>");
    el.answerHelp.innerHTML = helpRaw || "";
  }

  function renderQuestionPill(question) {
    const q = String(question || "").trim();
    if (!q) {
      el.questionPill.textContent = "";
      el.questionPill.classList.add("d-none");
      return;
    }
    el.questionPill.innerHTML = "";
    const label = document.createElement("span");
    label.className = "fw-semibold";
    label.textContent = "Question:";
    el.questionPill.appendChild(label);
    el.questionPill.appendChild(document.createTextNode(` ${q}`));
    el.questionPill.classList.remove("d-none");
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

    try {
      await ensureModalReady();
    } catch (e) {
      setError(e?.message || "Unable to initialize modal.");
      return;
    }
    await ensureMarkdownReady();
    widgetModal.show();
    setAskControlsVisible(true);
    resetForAsk();
    setLoading(true);

    try {
      const send = async (sid) => {
        const res = await fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
          body: new URLSearchParams({ q: text, sid, page_url: window.location.href }),
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

      renderQuestionPill(text);
      renderAnswer(data.answer);
      renderCitations(data.citations || []);
      renderReferences(data.references || []);
      renderAnswerAnchors(data.citations || []);
      renderSearch(data.search_results || []);
      renderFollowUps(data.related_questions || []);

      if (data?.meta?.session) {
        localStorage.setItem(SESSION_KEY, data.meta.session);
      }

      if (Array.isArray(data.search_results) && data.search_results.length > 0) {
        el.metaLink.classList.remove("d-none");
      }
      el.result.classList.remove("d-none");
      el.q.value = "";
      setAskControlsVisible(false);
      el.status.textContent = "";
    } catch (e) {
      setError(e?.message || "Something went wrong.");
    } finally {
      setLoading(false);
    }
  }

  el.ask.addEventListener("click", () => ask());
  el.launch.addEventListener("click", async (e) => {
    if (window.bootstrap?.Modal) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    try {
      await ensureModalReady();
      widgetModal.show();
    } catch (err) {
      console.error(err);
      setError("Bootstrap modal JavaScript could not be loaded.");
    }
  });
  el.q.addEventListener("keydown", (e) => {
    if (e.key === "Enter") ask();
  });
  el.newChat.addEventListener("click", () => {
    localStorage.removeItem(SESSION_KEY);
    setError("");
    el.status.textContent = "Started a new chat session.";
    setAskControlsVisible(true);
  });
  el.askAnother.addEventListener("click", () => {
    setAskControlsVisible(true);
    focusQuestionInput();
  });

  // Preload modal support in background when possible.
  ensureModalReady().catch(() => {});
  ensureMarkdownReady().catch(() => {});
})();
