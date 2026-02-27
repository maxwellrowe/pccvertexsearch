// public/embed.js
// Usage: <script src="https://YOURDOMAIN/embed.js" data-endpoint="https://YOURDOMAIN/api/answer.php"></script>
(() => {
  const script = document.currentScript;
  const endpoint = script?.dataset?.endpoint;
  if (!endpoint) return;

  const SESSION_KEY = "pcc_vertex_embed_session";
  const WIDGET_ID = "pcc-ask-widget";

  if (document.getElementById(WIDGET_ID)) return;

  const style = document.createElement("style");
  style.textContent = `
    .pccw-launch {
      position: fixed;
      right: 20px;
      bottom: 20px;
      z-index: 2147483000;
      border: 0;
      border-radius: 999px;
      background: #0d6efd;
      color: #fff;
      font: 600 14px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      padding: 12px 16px;
      cursor: pointer;
      box-shadow: 0 10px 28px rgba(0, 0, 0, .2);
    }
    .pccw-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, .52);
      z-index: 2147483001;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 16px;
      box-sizing: border-box;
    }
    .pccw-overlay.open { display: flex; }
    .pccw-modal {
      width: min(1100px, 100%);
      max-height: 92vh;
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 16px 38px rgba(0, 0, 0, .28);
      display: flex;
      flex-direction: column;
      overflow: hidden;
      font: 14px/1.45 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
      color: #1f2937;
    }
    .pccw-head, .pccw-foot {
      padding: 12px 14px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }
    .pccw-foot {
      border-top: 1px solid #e5e7eb;
      border-bottom: 0;
      justify-content: flex-end;
    }
    .pccw-title { font-weight: 700; }
    .pccw-close {
      border: 0;
      background: transparent;
      font-size: 22px;
      line-height: 1;
      cursor: pointer;
      color: #6b7280;
    }
    .pccw-body { padding: 14px; overflow: auto; }
    .pccw-row { margin-bottom: 10px; }
    .pccw-label { display: block; margin-bottom: 6px; font-weight: 600; }
    .pccw-input {
      width: 100%;
      border: 1px solid #d1d5db;
      border-radius: 10px;
      padding: 10px;
      box-sizing: border-box;
      font: inherit;
    }
    .pccw-btn {
      border: 0;
      border-radius: 8px;
      padding: 10px 14px;
      cursor: pointer;
      font: 600 14px/1.1 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    }
    .pccw-btn.primary { background: #0d6efd; color: #fff; }
    .pccw-btn.subtle { background: #f3f4f6; color: #111827; }
    .pccw-status { color: #6b7280; font-size: 13px; min-height: 18px; }
    .pccw-error {
      display: none;
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
      border-radius: 8px;
      padding: 8px 10px;
    }
    .pccw-error.show { display: block; }
    .pccw-result { display: none; border-top: 1px solid #e5e7eb; padding-top: 12px; margin-top: 8px; }
    .pccw-result.show { display: block; }
    .pccw-meta { color: #6b7280; font-size: 12px; margin-left: 8px; }
    .pccw-answer { margin: 8px 0; white-space: pre-wrap; }
    .pccw-answer p:last-child { margin-bottom: 0; }
    .pccw-anchors a {
      text-decoration: none;
      display: inline-block;
      margin-right: 6px;
      margin-bottom: 6px;
      border: 1px solid #d1d5db;
      border-radius: 8px;
      padding: 2px 8px;
      color: #1f2937;
      font-size: 12px;
      background: #f9fafb;
    }
    .pccw-sec { margin-top: 12px; }
    .pccw-sec h4 { margin: 0 0 6px; font-size: 14px; }
    .pccw-list { margin: 0; padding-left: 18px; }
    .pccw-list li { margin-bottom: 6px; }
    .pccw-muted { color: #6b7280; }
    .pccw-followups { display: flex; gap: 8px; flex-wrap: wrap; }
    .pccw-pill {
      border: 1px solid #d1d5db;
      border-radius: 999px;
      background: #f9fafb;
      color: #111827;
      padding: 6px 10px;
      cursor: pointer;
      font: 500 13px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    }
    .pccw-details > summary { cursor: pointer; font-weight: 600; margin-bottom: 6px; }
    .pccw-highlight { background: #fff3cd; transition: background-color .7s ease; }
    @media (max-width: 768px) {
      .pccw-overlay { padding: 0; }
      .pccw-modal { width: 100%; max-height: 100vh; height: 100vh; border-radius: 0; }
      .pccw-launch { right: 12px; bottom: 12px; }
    }
  `;
  document.head.appendChild(style);

  const root = document.createElement("div");
  root.id = WIDGET_ID;
  root.innerHTML = `
    <button type="button" class="pccw-launch" aria-haspopup="dialog" aria-controls="pccw-overlay">Ask a Question</button>
    <div id="pccw-overlay" class="pccw-overlay" role="dialog" aria-modal="true" aria-labelledby="pccw-title">
      <div class="pccw-modal">
        <div class="pccw-head">
          <div id="pccw-title" class="pccw-title">Ask Lance O'Lot a Question</div>
          <button type="button" class="pccw-close" aria-label="Close">×</button>
        </div>
        <div class="pccw-body">
          <div class="pccw-row">
            <label class="pccw-label" for="pccw-q">What would you like to ask?</label>
            <input id="pccw-q" class="pccw-input" type="text" placeholder="e.g., how do I get started at PCC?" />
          </div>
          <div id="pccw-status" class="pccw-status"></div>
          <div id="pccw-error" class="pccw-error pccw-row"></div>

          <div id="pccw-result" class="pccw-result">
            <div><strong>Answer</strong><span id="pccw-meta" class="pccw-meta"></span></div>
            <div id="pccw-answer" class="pccw-answer"></div>
            <div id="pccw-anchors" class="pccw-anchors" style="display:none;"></div>

            <div id="pccw-cites-wrap" class="pccw-sec" style="display:none;">
              <h4>Citations</h4>
              <ol id="pccw-cites" class="pccw-list"></ol>
            </div>

            <div id="pccw-refs-wrap" class="pccw-sec" style="display:none;">
              <details class="pccw-details">
                <summary>References</summary>
                <ol id="pccw-refs" class="pccw-list"></ol>
              </details>
            </div>

            <div id="pccw-search-wrap" class="pccw-sec" style="display:none;">
              <h4>Search Results</h4>
              <ul id="pccw-search" class="pccw-list"></ul>
            </div>

            <div id="pccw-fu-wrap" class="pccw-sec" style="display:none;">
              <h4>Follow-up questions</h4>
              <div id="pccw-fu" class="pccw-followups"></div>
            </div>
          </div>
        </div>
        <div class="pccw-foot">
          <button type="button" id="pccw-new" class="pccw-btn subtle">New chat</button>
          <button type="button" id="pccw-ask" class="pccw-btn primary">Ask</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(root);

  const el = {
    launch: root.querySelector(".pccw-launch"),
    overlay: root.querySelector("#pccw-overlay"),
    close: root.querySelector(".pccw-close"),
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

  let referenceLookup = new Map();

  function open() {
    el.overlay.classList.add("open");
    setTimeout(() => el.q.focus(), 0);
  }

  function close() {
    el.overlay.classList.remove("open");
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
      el.error.classList.remove("show");
      el.error.textContent = "";
      return;
    }
    el.error.textContent = msg;
    el.error.classList.add("show");
  }

  function setLoading(on) {
    el.ask.disabled = on;
    el.launch.disabled = on;
    el.status.textContent = on ? "Thinking… (Lance is trotting to the sources)" : "";
  }

  function resetForAsk() {
    setError("");
    el.result.classList.remove("show");
    el.meta.textContent = "";
    el.answer.textContent = "";
    el.anchors.innerHTML = "";
    el.anchors.style.display = "none";

    el.cites.innerHTML = "";
    el.citesWrap.style.display = "none";

    el.refs.innerHTML = "";
    el.refsWrap.style.display = "none";

    el.search.innerHTML = "";
    el.searchWrap.style.display = "none";

    el.fu.innerHTML = "";
    el.fuWrap.style.display = "none";

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
        el.answer.style.whiteSpace = "normal";
        return;
      } catch (e) {}
    }

    el.answer.innerHTML = escapeHtml(text).replace(/\n/g, "<br>");
    el.answer.style.whiteSpace = "normal";
  }

  function renderCitations(citations) {
    if (!Array.isArray(citations) || citations.length === 0) {
      el.citesWrap.style.display = "none";
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
    el.citesWrap.style.display = "block";
  }

  function renderReferences(references) {
    if (!Array.isArray(references) || references.length === 0) {
      el.refsWrap.style.display = "none";
      return;
    }

    references.forEach((r, i) => {
      const n = i + 1;
      const li = document.createElement("li");
      li.id = `pccw-ref-${n}`;
      const title = r?.title || r?.uri || "Reference";
      const uri = r?.uri || "";
      const snippet = r?.snippet ? `<div class="pccw-muted">${escapeHtml(r.snippet)}</div>` : "";
      li.innerHTML = uri
        ? `<span>[${n}]</span> <a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>${snippet}`
        : `<span>[${n}]</span> ${escapeHtml(title)}${snippet}`;
      el.refs.appendChild(li);

      const key = `${uri}|${String(title).toLowerCase()}`;
      if (uri || title) referenceLookup.set(key, n);
      if (uri) referenceLookup.set(`${uri}|`, n);
    });

    el.refsWrap.style.display = "block";
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
      el.anchors.style.display = "none";
      return;
    }

    const seen = new Set();
    citations.forEach((c, i) => {
      const refNum = findReferenceNumber(c);
      const a = document.createElement("a");
      a.textContent = `[${refNum || (i + 1)}]`;
      a.title = c?.title || "Reference";

      if (refNum) {
        a.href = `#pccw-ref-${refNum}`;
        if (!seen.has(refNum)) {
          a.addEventListener("click", () => {
            const target = document.getElementById(`pccw-ref-${refNum}`);
            if (!target) return;
            target.classList.add("pccw-highlight");
            setTimeout(() => target.classList.remove("pccw-highlight"), 900);
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

    if (el.anchors.children.length) el.anchors.style.display = "block";
  }

  function renderSearch(results) {
    if (!Array.isArray(results) || results.length === 0) {
      el.searchWrap.style.display = "none";
      return;
    }

    results.forEach((r) => {
      const li = document.createElement("li");
      const title = r?.title || r?.uri || "Result";
      const uri = r?.uri || "";
      const description = r?.description || r?.snippet || "";
      const urlLine = uri ? `<div class="pccw-muted" style="font-size:12px;">${escapeHtml(uri)}</div>` : "";
      li.innerHTML = uri
        ? `<a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>${urlLine}${description ? `<div class="pccw-muted">${escapeHtml(description)}</div>` : ""}`
        : `${escapeHtml(title)}${description ? `<div class="pccw-muted">${escapeHtml(description)}</div>` : ""}`;
      el.search.appendChild(li);
    });

    el.searchWrap.style.display = "block";
  }

  function renderFollowUps(items) {
    if (!Array.isArray(items) || items.length === 0) {
      el.fuWrap.style.display = "none";
      return;
    }

    items.forEach((rq) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "pccw-pill";
      btn.textContent = rq;
      btn.addEventListener("click", () => {
        el.q.value = rq;
        ask(rq);
      });
      el.fu.appendChild(btn);
    });

    el.fuWrap.style.display = "block";
  }

  async function ask(prefillQuestion = "") {
    const text = String(prefillQuestion || el.q.value).trim();
    if (!text) {
      setError("Please enter a question.");
      return;
    }

    open();
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

      el.meta.textContent = data?.meta ? ` ${data.meta.elapsed_ms ?? "?"}ms • ${data.meta.cache ?? "?"}` : "";
      el.result.classList.add("show");
      el.q.value = "";
      el.q.focus();
      el.status.textContent = "";
    } catch (e) {
      setError(e?.message || "Something went wrong.");
    } finally {
      setLoading(false);
    }
  }

  el.launch.addEventListener("click", open);
  el.close.addEventListener("click", close);
  el.overlay.addEventListener("click", (e) => {
    if (e.target === el.overlay) close();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && el.overlay.classList.contains("open")) close();
  });

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
