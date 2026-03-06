<?php
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($scriptDir === '' || $scriptDir === '.') {
  $scriptDir = '/';
}
$scriptDir = rtrim($scriptDir, '/');
$apiEndpoint = ($scriptDir === '' ? '' : $scriptDir) . '/api/answer.php';

// Prefer a concrete endpoint based on what exists under DOCUMENT_ROOT.
$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($documentRoot !== '') {
  if (is_file($documentRoot . '/api/answer.php')) {
    $apiEndpoint = '/api/answer.php';
  } elseif (is_file($documentRoot . '/public/api/answer.php')) {
    $apiEndpoint = '/public/api/answer.php';
  }
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PCC Vertex Answer (API Prototype)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/bootstrap.min.css" rel="stylesheet">
  <link href="./css/bootstrap-override.css" rel="stylesheet">
  <link href="./css/legacy.css" rel="stylesheet">
  <link href="./css/utilities.css" rel="stylesheet">
  <link href="./css/components.css" rel="stylesheet">
  <link href="./css/pcc-custom.css" rel="stylesheet">
  <style>
    .small-muted { font-size: .9rem; color: #6c757d; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .answer { white-space: pre-wrap; }
    .answer p:last-child { margin-bottom: 0; }
    .answer code { background: #f8f9fa; padding: 0 .2rem; border-radius: .25rem; }
    .citation-anchors a { text-decoration: none; margin-right: .25rem; }
    .reference-hit { background-color: #fff3cd; transition: background-color .6s ease; }
    .pcc-launch-icon-wrap {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 9999px;
      background: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      animation: pccIconBounceJiggle 2.5s ease-in-out infinite;
      pointer-events: none;
    }
    .pcc-launch-icon {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 9999px;
      display: block;
      object-fit: cover;
    }
    @keyframes pccIconBounceJiggle {
      0%, 40% { transform: translateY(0) rotate(0deg); }
      48% { transform: translateY(-6px) rotate(-5deg); }
      54% { transform: translateY(0) rotate(4deg); }
      60% { transform: translateY(-3px) rotate(-3deg); }
      66% { transform: translateY(0) rotate(2deg); }
      72% { transform: translateY(-1px) rotate(-1deg); }
      78%, 100% { transform: translateY(0) rotate(0deg); }
    }
    .pcc-modal-title {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
    }
    .pcc-modal-title-icon-wrap {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 9999px;
      background: #f1f3f5;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
    }
    .pcc-modal-title-icon {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 9999px;
      object-fit: cover;
      display: block;
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <div class="mb-3">
          <h1 class="h3 mb-1">PCC Answer (Vertex Engine API)</h1>
          <div class="small-muted">Bootstrap 5.3 + PHP proxy to Discovery Engine Answer API</div>
        </div>

        <div class="card shadow-sm mb-3">
          <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <span class="pcc-launch-icon-wrap" aria-hidden="true">
              <img class="pcc-launch-icon" src="/images/lance-profile.png" alt="" />
            </span>
            <button id="openAskModalBtn" class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#askModal">Ask a Question</button>
            <button id="newChatBtn" class="btn btn-outline-secondary" type="button">New chat</button>
            <div id="status" class="small-muted ms-md-2"></div>
          </div>
        </div>

      </div>
    </div>
</div>

<div class="modal fade" id="askModal" tabindex="-1" aria-labelledby="askModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 pcc-modal-title" id="askModalLabel">
          <span class="pcc-modal-title-icon-wrap" aria-hidden="true">
            <img class="pcc-modal-title-icon" src="/images/lance-profile.png" alt="" />
          </span>
          <span>Ask Lance O'Lot a Question</span>
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 align-items-end">
          <div class="flex-grow-1">
            <label class="form-label" for="q">What would you like to ask?</label>
            <input id="q" class="form-control" type="text" placeholder="e.g., how do I get started at PCC?" />
          </div>
          <button id="modalAskBtn" class="btn btn-primary" type="button">Ask</button>
        </div>
        <div id="modalStatus" class="small-muted mt-2"></div>
        <div id="error" class="alert alert-danger d-none mt-3"></div>

        <div id="result" class="card shadow-sm d-none">
          <div class="card-body">
            <div id="questionPill" class="d-none mb-2 p-1 border rounded-3 bg-light text-dark small"></div>
            <div class="border border-light-subtle rounded-3 p-2 mb-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0">Answer</h2>
                <a id="metaLink" class="small d-none" href="#searchWrap">View Search Results</a>
              </div>
              <div id="answer" class="answer"></div>
            </div>
            <div id="answerCitations" class="citation-anchors small d-none mb-2"></div>

            <div id="citationsWrap" class="d-none">
              <h3 class="h6">Citations</h3>
              <ol id="citations" class="small"></ol>
            </div>

            <div id="referencesWrap" class="d-none mt-3">
              <details>
                <summary class="h6 mb-2">References</summary>
                <ol id="references" class="small mb-0"></ol>
              </details>
            </div>

            <div id="searchWrap" class="d-none mt-3">
              <h3 class="h6">Search Results</h3>
              <ul id="searchResults" class="list-group list-group-flush small"></ul>
            </div>

            <div id="followUpsWrap" class="d-none mt-3">
              <h3 class="h6">Follow-up questions</h3>
              <div id="followUps" class="d-flex flex-wrap gap-2"></div>
            </div>

            <!-- Debug payload is kept in DOM for inspection but hidden from UI. -->
            <div class="d-none" aria-hidden="true">
              <pre id="debug" class="mono small mt-2 mb-0"></pre>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.2.6/dist/purify.min.js"></script>
<script>
const $ = (sel) => document.querySelector(sel);
const SESSION_KEY = "pcc_vertex_session";
const API_ENDPOINT = <?= json_encode($apiEndpoint, JSON_UNESCAPED_SLASHES) ?>;
let referenceLookup = new Map();
let activePopovers = [];

function setLoading(isLoading) {
  $("#modalAskBtn").disabled = isLoading;
  $("#openAskModalBtn").disabled = isLoading;
  const loadingHtml = '<span class="d-inline-flex align-items-center gap-1"><svg width="12" height="12" viewBox="0 0 50 50" aria-hidden="true"><circle cx="25" cy="25" r="20" fill="none" stroke="#be1e2d" stroke-width="5" stroke-linecap="round" stroke-dasharray="31.4 31.4"><animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.9s" repeatCount="indefinite"/></circle></svg><span>Thinking… (Lance is trotting to the sources)</span></span>';
  $("#status").innerHTML = isLoading ? loadingHtml : "";
  $("#modalStatus").innerHTML = isLoading ? loadingHtml : "";
}

function showError(msg) {
  $("#error").textContent = msg;
  $("#error").classList.remove("d-none");
}

function clearError() {
  $("#error").classList.add("d-none");
  $("#error").textContent = "";
}

function renderAnswerText(text) {
  const answerEl = $("#answer");
  const raw = String(text || "");
  if (!raw) {
    answerEl.textContent = "(No answer returned.)";
    return;
  }

  try {
    const html = marked.parse(raw, { breaks: true, gfm: true });
    answerEl.innerHTML = DOMPurify.sanitize(html);
    answerEl.style.whiteSpace = "normal";
  } catch (e) {
    answerEl.textContent = raw;
    answerEl.style.whiteSpace = "pre-wrap";
  }
}

function renderQuestionPill(question) {
  const q = String(question || "").trim();
  const pill = $("#questionPill");
  if (!q) {
    pill.textContent = "";
    pill.classList.add("d-none");
    return;
  }
  pill.innerHTML = "";
  const label = document.createElement("span");
  label.className = "fw-semibold";
  label.textContent = "Question:";
  pill.appendChild(label);
  pill.appendChild(document.createTextNode(` ${q}`));
  pill.classList.remove("d-none");
}

function resetPopovers() {
  activePopovers.forEach((p) => p.dispose());
  activePopovers = [];
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, (m) => ({
    "&":"&amp;","<":"&lt;",">":"&gt;", "\"":"&quot;","'":"&#039;"
  }[m]));
}

function renderCitations(citations) {
  const wrap = $("#citationsWrap");
  const list = $("#citations");
  list.innerHTML = "";

  if (!Array.isArray(citations) || citations.length === 0) {
    wrap.classList.add("d-none");
    return;
  }

  citations.forEach((c) => {
    const li = document.createElement("li");
    const title = c?.title || c?.uri || "Source";
    const uri = c?.uri || "";
    li.innerHTML = uri
      ? `<a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>`
      : escapeHtml(title);
    list.appendChild(li);
  });

  wrap.classList.remove("d-none");
}

function renderReferences(references) {
  const wrap = $("#referencesWrap");
  const list = $("#references");
  referenceLookup = new Map();
  list.innerHTML = "";

  if (!Array.isArray(references) || references.length === 0) {
    wrap.classList.add("d-none");
    return;
  }

  references.forEach((r, i) => {
    const n = i + 1;
    const li = document.createElement("li");
    li.id = `ref-${n}`;
    const title = r?.title || r?.uri || "Reference";
    const uri = r?.uri || "";
    const snippet = r?.snippet ? `<div class="text-muted">${escapeHtml(r.snippet)}</div>` : "";
    const previewBtn = r?.snippet
      ? ` <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1 ref-preview" data-bs-toggle="popover" data-bs-trigger="focus hover" data-bs-html="true" data-bs-content="${escapeHtml(r.snippet)}">Preview</button>`
      : "";
    li.innerHTML = uri
      ? `<span class="badge text-bg-light border me-1">[${n}]</span><a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>${previewBtn}${snippet}`
      : `${escapeHtml(title)}${snippet}`;
    list.appendChild(li);

    const key = `${uri}|${String(title).toLowerCase()}`;
    if (uri || title) {
      referenceLookup.set(key, n);
    }
    if (uri) {
      referenceLookup.set(`${uri}|`, n);
    }
  });

  wrap.classList.remove("d-none");
  list.querySelectorAll(".ref-preview").forEach((btn) => {
    activePopovers.push(new bootstrap.Popover(btn, { container: "body" }));
  });
}

function renderSearchResults(results) {
  const wrap = $("#searchWrap");
  const list = $("#searchResults");
  list.innerHTML = "";

  if (!Array.isArray(results) || results.length === 0) {
    wrap.classList.add("d-none");
    return;
  }

  results.forEach((r) => {
    const item = document.createElement("li");
    item.className = "list-group-item px-0";
    const title = r?.title || r?.uri || "Result";
    const uri = r?.uri || "";
    const description = r?.description || r?.snippet || "";
    const urlLine = uri ? `<div class="small text-dark">${escapeHtml(uri)}</div>` : "";
    const snippet = description ? `<div class="text-muted mt-1">${escapeHtml(description)}</div>` : "";
    item.innerHTML = uri
      ? `<a href="${escapeHtml(uri)}" target="_blank" rel="noopener">${escapeHtml(title)}</a>${urlLine}${snippet}`
      : `<span>${escapeHtml(title)}</span>${snippet}`;
    list.appendChild(item);
  });

  wrap.classList.remove("d-none");
}

function renderFollowUps(questions) {
  const wrap = $("#followUpsWrap");
  const box = $("#followUps");
  box.innerHTML = "";

  if (!Array.isArray(questions) || questions.length === 0) {
    wrap.classList.add("d-none");
    return;
  }

  questions.forEach((question) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "btn btn-outline-secondary btn-sm";
    btn.textContent = question;
    btn.addEventListener("click", () => {
      $("#q").value = question;
      ask(question);
    });
    box.appendChild(btn);
  });

  wrap.classList.remove("d-none");
}

function findReferenceNumber(citation) {
  const title = citation?.title || citation?.uri || "Source";
  const uri = citation?.uri || "";
  const byExact = referenceLookup.get(`${uri}|${String(title).toLowerCase()}`);
  if (byExact) return byExact;
  const byUri = referenceLookup.get(`${uri}|`);
  if (byUri) return byUri;
  return null;
}

function renderAnswerCitations(citations) {
  const box = $("#answerCitations");
  box.innerHTML = "";

  if (!Array.isArray(citations) || citations.length === 0) {
    box.classList.add("d-none");
    return;
  }

  const seen = new Set();
  citations.forEach((c, i) => {
    const refNum = findReferenceNumber(c);
    if (refNum && !seen.has(refNum)) {
      const a = document.createElement("a");
      a.href = `#ref-${refNum}`;
      a.className = "badge text-bg-light border";
      a.textContent = `[${refNum}]`;
      a.title = c?.title || "Reference";
      a.addEventListener("click", () => {
        const target = document.getElementById(`ref-${refNum}`);
        if (!target) return;
        target.classList.add("reference-hit");
        setTimeout(() => target.classList.remove("reference-hit"), 900);
      });
      box.appendChild(a);
      seen.add(refNum);
    } else if (!refNum) {
      const uri = c?.uri || "";
      const a = document.createElement("a");
      a.className = "badge text-bg-light border";
      a.textContent = `[${i + 1}]`;
      a.title = c?.title || "Source";
      if (uri) {
        a.href = uri;
        a.target = "_blank";
        a.rel = "noopener";
      }
      box.appendChild(a);
    }
  });

  box.classList.remove("d-none");
}

async function ask(prefillQuestion = null) {
  clearError();
  resetPopovers();
  $("#result").classList.add("d-none");
  $("#debug").textContent = "";
  $("#metaLink").classList.add("d-none");
  $("#questionPill").textContent = "";
  $("#questionPill").classList.add("d-none");
  $("#answerCitations").classList.add("d-none");
  $("#answerCitations").innerHTML = "";

  const q = String(prefillQuestion || $("#q").value).trim();
  if (!q) return showError("Please enter a question.");

  setLoading(true);

  try {
    const send = async (sid) => {
      const res = await fetch(API_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8" },
        body: new URLSearchParams({ q, sid }),
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
      $("#debug").textContent = JSON.stringify(data, null, 2);
      throw new Error(data?.error || `Request failed (${res.status})`);
    }

    renderQuestionPill(q);
    renderAnswerText(data.answer);
    renderCitations(data.citations || []);
    renderReferences(data.references || []);
    renderAnswerCitations(data.citations || []);
    renderSearchResults(data.search_results || []);
    renderFollowUps(data.related_questions || []);
    if (Array.isArray(data.search_results) && data.search_results.length > 0) {
      $("#metaLink").classList.remove("d-none");
    }
    $("#debug").textContent = JSON.stringify(data, null, 2);

    if (data?.meta?.session) {
      localStorage.setItem(SESSION_KEY, data.meta.session);
    }
    $("#q").value = "";
    $("#q").focus();
    $("#result").classList.remove("d-none");
  } catch (e) {
    showError(e?.message || "Something went wrong.");
  } finally {
    setLoading(false);
  }
}

$("#modalAskBtn").addEventListener("click", () => ask());
$("#q").addEventListener("keydown", (e) => {
  if (e.key === "Enter") ask();
});
$("#newChatBtn").addEventListener("click", () => {
  localStorage.removeItem(SESSION_KEY);
  $("#status").textContent = "Started a new chat session.";
  $("#modalStatus").textContent = "";
});
</script>
</body>
</html>
