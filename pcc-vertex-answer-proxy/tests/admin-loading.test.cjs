const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
for (const file of ['dashboard.js', 'promotions.js']) {
  test(`${file}: waits for late AJAX helper and blocks native submits meanwhile`, () => {
    const handlers = new Map();
    const events = new Map();
    let bound = 0;
    const root = {
      addEventListener: (type, fn) => handlers.set(type, fn),
      removeEventListener: type => handlers.delete(type),
      querySelector: () => null,
      prepend() {}
    };
    const window = {addEventListener: (type, fn) => events.set(type, fn)};
    const document = {
      readyState: 'complete',
      querySelector: selector => selector.endsWith('-table') ? null : root,
      querySelectorAll: () => [],
      createElement: () => ({dataset: {}, setAttribute() {}})
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public', file), 'utf8'), {window, document});
    assert.equal(bound, 0);
    let prevented = false;
    handlers.get('submit')({preventDefault() {prevented = true;}});
    assert.equal(prevented, true);
    window.PccAdminUI = {bind: () => bound++};
    events.get('pcc-admin-ready')();
    assert.equal(bound, 1);
    assert.equal(handlers.has('submit'), false);
  });
}
