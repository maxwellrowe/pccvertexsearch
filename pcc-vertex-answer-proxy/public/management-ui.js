(() => {
  if (window.PccAdminUI) { window.dispatchEvent(new Event('pcc-admin-ready')); return; }
  window.PccAdminUI = {
    bind(root, initialize) {
      if (!root || root.dataset.ajaxBound) return;
      const isReport = root.id === 'pcc-report';
      const history = root._reportHistory || [];
      const current = root._reportParams || (isReport ? new URLSearchParams(new FormData(root.querySelector('form'))) : new URLSearchParams());
      if (isReport && history.length) {
        const previous = document.createElement('button');
        previous.type = 'button'; previous.className = 'btn btn-outline-primary btn-sm mb-3';
        previous.textContent = 'Previous batch';
        previous.addEventListener('click', () => load(history[history.length - 1], 'previous'));
        root.prepend(previous);
      }
      let busy = false;
      async function load(params, navigation) {
        if (busy) return;
        busy = true;
        root.setAttribute('aria-busy', 'true');
        const status = document.createElement('p');
        status.className = 'alert alert-info'; status.setAttribute('role', 'status');
        status.tabIndex = -1;
        status.textContent = 'Loading…'; root.prepend(status);
        const endpoint = new URL(root.dataset.adminEndpoint, window.location.href);
        try {
          if (endpoint.origin !== window.location.origin) throw new Error('The dashboard endpoint must be on the same site.');
          const response = await fetch(endpoint, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            headers: {'X-PCC-Admin-Token': root.dataset.adminToken}, body: params
          });
          if (response.status === 403) throw new Error('Your access or form token has expired. Reopen the protected page to sign in again.');
          if (!response.ok) throw new Error('The request could not be completed. For a save, check the list before trying again.');
          const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
          const replacement = doc.getElementById(root.id);
          if (!replacement) throw new Error('The server did not return the dashboard. Your login may need to be renewed.');
          replacement.querySelectorAll('script').forEach(script => script.remove());
          if (isReport) {
            replacement._reportHistory = navigation === 'next' ? [...history, current] : navigation === 'previous' ? history.slice(0, -1) : [];
            replacement._reportParams = new URLSearchParams(params);
          }
          root.querySelectorAll('table').forEach(table => {
            if (window.DataTable?.isDataTable(table)) new window.DataTable(table).destroy();
            else if (window.jQuery?.fn?.dataTable?.isDataTable(table)) window.jQuery(table).DataTable().destroy();
          });
          root.replaceWith(replacement);
          initialize();
          const focus = replacement.querySelector('[role="alert"], [role="status"], h2');
          if (focus) { focus.tabIndex = -1; focus.focus({preventScroll: true}); }
        } catch (error) {
          status.className = 'alert alert-danger'; status.setAttribute('role', 'alert');
          status.textContent = error.message || 'Unable to complete the request.';
          status.focus({preventScroll: true});
        } finally { busy = false; root.removeAttribute('aria-busy'); }
      }
      root.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        event.preventDefault();
        const params = new URLSearchParams(new FormData(form));
        if (event.submitter?.name) params.set(event.submitter.name, event.submitter.value);
        load(params, 'reset');
      });
      root.addEventListener('click', event => {
        const link = event.target.closest('a');
        if (!link || !root.contains(link)) return;
        const href = link.getAttribute('href');
        if (!href?.startsWith('?')) return;
        event.preventDefault();
        load(new URLSearchParams(href.slice(1)), isReport ? 'next' : 'reset');
      });
      root.dataset.ajaxBound = '1';
    }
  };
  window.dispatchEvent(new Event('pcc-admin-ready'));
})();
