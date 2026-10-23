/* Shared by the embedded widget and standalone chat. No persistent visitor ID. */
window.PccAnalytics = {
  create(endpoint) {
    const loggingEnabled = new URLSearchParams(location.search).get('logging') !== '0';
    const key = 'pcc_analytics_v2';
    let memory;
    const uuid = () => crypto.randomUUID();
    const save = state => {
      memory = state;
      try { localStorage.setItem(key, JSON.stringify(state)); } catch (_) {}
    };
    const state = () => {
      let s = memory;
      try { s = JSON.parse(localStorage.getItem(key)) || s; } catch (_) {}
      const now = Date.now();
      if (!s || now - s.active >= 30 * 60 * 1000 || s.day !== new Date(now).toDateString()) {
        s = { session_id: uuid(), conversation_id: uuid(), active: now,
          day: new Date(now).toDateString(), turn: 0, previous: '', vertex: '' };
      }
      s.active = now;
      save(s);
      return s;
    };
    const page = () => location.origin + location.pathname;
    return {
      loggingEnabled,
      begin(suggested = false, question = '') {
        const s = state();
        const words = text => new Set(text.toLowerCase().match(/[\p{L}\p{N}]+/gu) || []);
        const a = words(question), b = words(s.lastQuestion || '');
        const overlap = [...a].filter(word => b.has(word)).length;
        const similar = b.size > 0 && overlap / new Set([...a, ...b]).size >= 0.6;
        const payload = { session_id: s.session_id, conversation_id: s.conversation_id,
          query_id: uuid(), previous_query_id: s.previous, turn_number: String(++s.turn),
          query_kind: suggested ? 'suggested_follow_up' : (s.previous ? 'subsequent_query' : 'initial'),
          rephrase_status: !suggested && similar ? 'suspected' : 'unknown',
          rephrase_reason: !suggested && similar ? 'token_overlap_at_least_0.6' : '',
          is_test: new URLSearchParams(location.search).get('pcc_test') === '1' ? '1' : '0',
          page_url: page(), logging: loggingEnabled ? '1' : '0' };
        s.previous = payload.query_id;
        s.lastQuestion = question;
        save(s);
        return { payload, sid: s.vertex };
      },
      complete(context, sid) {
        const s = state();
        if (s.conversation_id === context.conversation_id && s.turn === Number(context.turn_number)) {
          s.vertex = sid || ''; s.previous = context.query_id; save(s);
        }
      },
      newChat() {
        const s = state();
        Object.assign(s, { conversation_id: uuid(), turn: 0, previous: '', vertex: '', lastQuestion: '' });
        save(s);
      },
      async event(context, type, extra = {}, eventId = uuid()) {
        if (!loggingEnabled) return null;
        state();
        const payload = new URLSearchParams({ ...context, ...extra, event_type: type,
          event_id: eventId, page_url: page() });
        const res = await fetch(endpoint, { method: 'POST', body: payload, keepalive: true, credentials: 'omit' });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || 'Unable to save feedback.');
        return eventId;
      },
      watchHelp(root, getContext) {
        const onClick = e => {
          if (e.type === 'auxclick' && e.button !== 1) return;
          const link = e.target.closest('a[href]');
          const context = getContext();
          if (!link || !context?.answer_id) return;
          const url = new URL(link.href, location.href);
          if (url.hostname === 'pasadena.edu' && url.pathname === '/student-services/get-help/index.php') {
            this.event(context, 'escalation', { destination_url: url.origin + url.pathname,
              escalation_action: 'help_centers_support_click' }).catch(() => {});
          }
        };
        root.addEventListener('click', onClick);
        root.addEventListener('auxclick', onClick);
      }
    };
  }
};


// Temporary opt-in diagnostics: add ?timings=1 to the containing page URL.
window.PccTimings = {
  begin() {
    const enabled = new URLSearchParams(location.search).get('timings') === '1';
    const start = performance.now();
    const attempts = [];
    let ready = start;
    return {
      payload: enabled ? { debug_timings: '1' } : {},
      ready() { ready = performance.now(); },
      async send(run) {
        const before = performance.now();
        const result = await run();
        attempts.push({ ms: performance.now() - before, status: result.res.status, data: result.data });
        return result;
      },
      show(anchor) {
        if (!enabled) return;
        const total = performance.now() - start;
        const panel = document.createElement('details');
        panel.dataset.pccTimings = '1';
        panel.open = true;
        panel.style.cssText = 'margin:0 0 16px;padding:12px;border:1px solid #aaa;background:#f6f6f6;color:#222;font:13px/1.5 monospace;white-space:pre-wrap';
        const summary = document.createElement('summary');
        summary.textContent = `Request timing: ${(total / 1000).toFixed(2)} s (through DOM update)`;
        panel.appendChild(summary);
        const lines = [`Browser setup: ${(ready - start).toFixed(1)} ms`];
        attempts.forEach((a, i) => {
          lines.push(`Attempt ${i + 1}: ${a.ms.toFixed(1)} ms • HTTP ${a.status} • Cache ${a.data?.meta?.cache || 'n/a'} • Logging ${a.data?.meta?.logging || 'unknown'}`);
          const t = a.data?.meta?.timings;
          if (t) {
            lines.push(`  PHP total: ${t.server_total_ms.toFixed(1)} ms`);
            Object.entries(t.stages_ms).forEach(([name, ms]) => lines.push(`    ${name.replaceAll('_', ' ')}: ${ms.toFixed(1)} ms`));
            if (t.vertex_transfer) {
              const v = t.vertex_transfer;
              lines.push(`  Vertex breakdown (included above): ${v.total_ms.toFixed(1)} ms${v.complete ? '' : ' • incomplete transfer'}`);
              Object.entries(v.stages_ms).forEach(([name, ms]) => {
                lines.push(`    ${name}: ${ms === null ? 'not measured / not reached' : ms.toFixed(1) + ' ms'}`);
              });
              if (v.first_byte_from_start_ms !== null) {
                lines.push(`    First byte from request start (cumulative, not additional): ${v.first_byte_from_start_ms.toFixed(1)} ms`);
              }
            }
            lines.push(`  Outside measured PHP (approx.): ${Math.max(0, a.ms - t.server_total_ms).toFixed(1)} ms`);
          } else lines.push('  Server timing unavailable.');
        });
        lines.push(`Browser handling / DOM updates: ${Math.max(0, total - (ready - start) - attempts.reduce((sum, a) => sum + a.ms, 0)).toFixed(1)} ms`);
        lines.push('PHP stages sum to PHP total (approximately). Outside PHP includes transport, queueing, response transfer and JSON parsing. Vertex substeps are included in the Vertex stage, not additional time. Upload + first-byte wait includes network transit and upstream processing; it does not isolate search from generation. Paint time is excluded.');
        panel.appendChild(document.createTextNode('\n' + lines.join('\n')));
        anchor.before(panel);
      }
    };
  }
};
