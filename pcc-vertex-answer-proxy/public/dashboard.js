(() => {
  function initialize() {
    document.querySelectorAll('#pcc-report .pcc-report-table').forEach((table, index) => {
      const options = {pageLength: 25, order: [[0, 'desc']], lengthMenu: [10, 25, 50, 100]};
      const originalRows = Array.from(table.tBodies[0].rows);
      let api;
      if (window.DataTable) api = new window.DataTable(table, options);
      else if (window.jQuery?.fn?.DataTable) api = window.jQuery(table).DataTable(options);
      const toolbar = document.createElement('div');
      toolbar.className = 'd-flex flex-wrap align-items-center gap-3 mb-3';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn-outline-primary btn-sm';
      button.textContent = 'Export CSV';
      const note = document.createElement('span');
      note.className = 'small text-muted';
      note.textContent = 'Exports matching rows across all table pages in this loaded batch, including full answers and IDs.';
      toolbar.append(button, note);
      table.closest('.card-body').prepend(toolbar);
      button.addEventListener('click', () => {
        const nodes = api ? api.rows({search: 'applied', order: 'applied', page: 'all'}).nodes().toArray() : originalRows;
        const rows = nodes.map(node => JSON.parse(node.dataset.export));
        const columns = [...new Set(originalRows.flatMap(node => Object.keys(JSON.parse(node.dataset.export))))];
        const cell = value => {
          let text = Array.isArray(value) ? value.join(' | ') : String(value ?? '');
          // Prevent spreadsheet applications from evaluating user-supplied formulas.
          if (/^[\s]*[=+@-]|^[\t\r\n]/.test(text)) text = "'" + text;
          return '"' + text.replace(/"/g, '""') + '"';
        };
        const csv = [columns.map(cell).join(','), ...rows.map(row => columns.map(key => cell(row[key])).join(','))].join('\r\n');
        const url = URL.createObjectURL(new Blob(['\uFEFF', csv], {type: 'text/csv;charset=utf-8;'}));
        const link = document.createElement('a');
        link.href = url;
        link.download = `pcc-${index === 0 ? 'questions' : 'feedback'}-${document.querySelector('#pcc-report-from').value}-${document.querySelector('#pcc-report-to').value}.csv`;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
  else initialize();
})();
