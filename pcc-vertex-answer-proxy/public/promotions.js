(() => {
  const init = () => {
    const table = document.querySelector('#pcc-promotions-table');
    if (!table) return;
    const options = {pageLength: 25, order: [[3, 'asc']], columnDefs: [{targets: 6, orderable: false, searchable: false}]};
    if (window.DataTable) new DataTable(table, options);
    else if (window.jQuery?.fn?.DataTable) window.jQuery(table).DataTable(options);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once: true});
  else init();
})();
