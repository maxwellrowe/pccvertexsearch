(() => {
  const init = () => {
    const root = document.querySelector('#pcc-promotions');
    if (!root) return;
    if (!window.PccAdminUI) {
      if (!root._waitingForAdmin) {
        root._waitingForAdmin = true;
        const stopSubmission = event => {
          event.preventDefault();
          let warning = root.querySelector('[data-admin-load-warning]');
          if (!warning) {
            warning = document.createElement('p');
            warning.dataset.adminLoadWarning = '1';
            warning.className = 'alert alert-danger';
            warning.setAttribute('role', 'alert');
            warning.textContent = 'The AJAX script has not loaded. Check that management-ui.js is published and allowed by this page.';
            root.prepend(warning);
          }
        };
        root.addEventListener('submit', stopSubmission);
        window.addEventListener('pcc-admin-ready', () => {
          root.removeEventListener('submit', stopSubmission);
          root.querySelector('[data-admin-load-warning]')?.remove();
          init();
        }, {once: true});
      }
      return;
    }
    window.PccAdminUI.bind(root, init);
    const table = document.querySelector('#pcc-promotions-table');
    if (!table || table.dataset.pccInitialized) return;
    table.dataset.pccInitialized = '1';
    const options = {pageLength: 25, order: [[3, 'asc']], columnDefs: [{targets: 6, orderable: false, searchable: false}]};
    if (window.DataTable) new DataTable(table, options);
    else if (window.jQuery?.fn?.DataTable) window.jQuery(table).DataTable(options);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
