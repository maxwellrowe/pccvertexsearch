/* Shared search presentation for the modal and inline views. */
const pccSearchAssetBase = new URL('.', document.currentScript.src);
window.PccSearchUI = {
  externalResultIcon(uri) {
    try {
      const url = new URL(uri, location.href);
      if (!['http:', 'https:'].includes(url.protocol) || ['pasadena.edu', 'www.pasadena.edu'].includes(url.hostname)) return '';
      return ' <i class="fa-solid fa-arrow-up-right-from-square ms-1" aria-hidden="true"></i><span class="visually-hidden"> (external site, opens in a new tab)</span>';
    } catch (_) { return ''; }
  },
  splitAnswer(raw) {
    // Normalize generated/cached footer variants to one canonical help block.
    const marker = /(?:<strong\b[^>]*>\s*|\*\*)?Need additional help\?/i.exec(raw);
    if (!marker) return { bodyRaw: raw, helpRaw: '' };
    const bodyRaw = raw.slice(0, marker.index).replace(/(?:\s|<hr\b[^>]*>|<p\b[^>]*>|[-*_]{3,})+$/gi, '').trimEnd();
    return { bodyRaw, helpRaw: '<hr class="my-2"><strong>Need additional help?</strong> <a href="https://pasadena.edu/student-services/get-help/index.php" target="_blank" rel="noopener noreferrer">Visit Help Centers &amp; Support</a>' };
  },
  progress(host) {
    let timer, cleanup;
    const stop = () => { clearInterval(timer); clearTimeout(cleanup); };
    return {
      start() {
        stop();
        host.innerHTML = '<div><div role="status" class="visually-hidden">Loading results…</div><div class="w-100" style="min-width:60px"><div style="height:36px;position:relative;margin-right:24px" aria-hidden="true"><span data-lance-marker style="position:absolute;left:0%;width:24px;height:36px;overflow:hidden"><img data-lance-image alt="" style="position:absolute;width:96px;max-width:none;height:36px;left:-36px"></span></div><div class="progress" data-lance-progress role="progressbar" aria-label="Estimated progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="height:5px"><div class="progress-bar" data-lance-fill style="width:0%"></div></div></div></div><div class="small text-muted mt-1 text-end" data-progress-note hidden></div>';
        host.querySelector('[data-lance-image]').src = new URL('images/lance-trotting-silhouette.svg', pccSearchAssetBase).href;
        const marker = host.querySelector('[data-lance-marker]');
        // Interpolate between updates instead of jumping by whole percentages.
        const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        marker.style.transition = reducedMotion ? 'none' : 'left 180ms linear';
        host.querySelector('[data-lance-fill]').style.transition = reducedMotion ? 'none' : 'width 180ms linear';
        const start = performance.now();
        const tick = () => {
          const seconds = (performance.now() - start) / 1000;
          const value = seconds <= 6 ? seconds * 15 : 90 + 5 * (1 - Math.exp(-(seconds - 6) / 10));
          host.querySelector('[data-lance-progress]').setAttribute('aria-valuenow', Math.round(value));
          host.querySelector('[data-lance-marker]').style.left = value + '%';
          host.querySelector('[data-lance-fill]').style.width = value + '%';
          if (seconds > 8) {
            const note = host.querySelector('[data-progress-note]');
            note.hidden = false;
            note.textContent = 'Taking a little longer…';
          }
        };
        timer = setInterval(tick, 150);
      },
      finish(success) {
        stop();
        if (!success) { host.replaceChildren(); return; }
        const bar = host.querySelector('[data-lance-marker]');
        if (!bar) return;
        bar.style.left = '100%';
        host.querySelector('[data-lance-fill]').style.width = '100%';
        host.querySelector('[data-lance-progress]').setAttribute('aria-valuenow', '100');
        host.querySelector('[role="status"]').textContent = 'Results ready.';
        cleanup = setTimeout(() => host.replaceChildren(), 500);
      }
    };
  },
  preview(body, limit = 450) {
    // Clone sanitized DOM, preserving paragraphs/lists/links without slicing HTML.
    const preview = document.createElement('div');
    let remaining = limit;
    let truncated = false;
    const copy = (source, target) => {
      for (const child of source.childNodes) {
        if (remaining <= 0) { truncated = true; break; }
        if (child.nodeType === 3) {
          const text = child.textContent;
          let part = text.slice(0, remaining);
          if (text.length > remaining) {
            part = part.replace(/\s+\S*$/, '') + '…';
            truncated = true;
          }
          target.appendChild(document.createTextNode(part));
          remaining -= Math.min(text.length, remaining);
        } else if (child.nodeType === 1) {
          const clone = child.cloneNode(false);
          clone.removeAttribute('id');
          target.appendChild(clone);
          copy(child, clone);
        }
      }
    };
    copy(body, preview);
    if (truncated && !preview.textContent.endsWith('…')) {
      preview.appendChild(document.createTextNode('…'));
    }
    return preview;
  },
  answer(body, references, help, feedback) {
    // Hide the full DOM while collapsed, so offscreen links are not focusable.
    body.hidden = false;

    const extras = [references, help, feedback].filter(Boolean);
    extras.forEach(node => { node.hidden = false; });
    body.parentElement.querySelectorAll('[data-answer-preview]').forEach(node => node.remove());
    const text = body.innerText || body.textContent || '';
    const normalized = text.replace(/\s+/g, ' ').trim();
    // Even short answers need an expander when supplemental sections exist.
    if (normalized.length <= 450 && !extras.length) return;
    const preview = this.preview(body);
    preview.dataset.answerPreview = '1';
    if (normalized.length > 450) {
      preview.style.position = 'relative';
      const fade = document.createElement('div');
      fade.setAttribute('aria-hidden', 'true');
      fade.dataset.answerFade = '1';
      fade.style.cssText = 'position:absolute;inset:65% 0 0;pointer-events:none;background:linear-gradient(to bottom,rgba(255,255,255,0),#fff)';
      preview.appendChild(fade);
    }
    const button = document.createElement('button');
    button.dataset.answerPreview = '1';
    button.type = 'button';
    button.className = 'btn btn-yellow btn-sm py-1 px-2 rounded';
    button.textContent = 'Read more';
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', [body, ...extras].map(node => node.id).filter(Boolean).join(' '));
    body.before(preview);
    // References and feedback precede the toggle; help follows it when expanded.
    let last = body;
    for (const extra of [references, feedback].filter(Boolean)) {
      last.after(extra);
      last = extra;
    }
    last.after(button);
    if (help) button.after(help);
    extras.forEach(node => { node.hidden = true; });
    body.hidden = true;
    let expansionAnimation;
    button.addEventListener('click', () => {
      const container = body.parentElement;
      const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      const canAnimate = !reducedMotion && typeof container.animate === 'function';
      // Read the current visual height before cancelling an interrupted animation.
      const fromHeight = canAnimate ? container.getBoundingClientRect().height : 0;
      expansionAnimation?.cancel();
      const expanded = button.getAttribute('aria-expanded') !== 'true';
      body.hidden = !expanded;
      extras.forEach(node => { node.hidden = !expanded; });
      preview.hidden = expanded;
      button.setAttribute('aria-expanded', String(expanded));
      button.textContent = expanded ? 'Read less' : 'Read more';
      if (canAnimate) {
        const toHeight = container.getBoundingClientRect().height;
        expansionAnimation = container.animate([
          { height: fromHeight + 'px', overflow: 'hidden' },
          { height: toHeight + 'px', overflow: 'hidden' }
        ], { duration: 320, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' });
        // No fill mode or fixed height: content remains responsive afterwards.
      }
    });
  }
};
