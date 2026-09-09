/* Shared by the embedded widget and standalone chat. No persistent visitor ID. */
window.PccAnalytics = {
  create(endpoint) {
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
          page_url: page() };
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
