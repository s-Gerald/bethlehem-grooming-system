const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const listeners = new Map();
const context = {
  window: {},
  document: { querySelector: () => ({ addEventListener(type, handler, capture) {
    assert.equal(capture, true);
    listeners.set(type, handler);
  } }) },
  setTimeout: () => {},
  alert: () => { throw new Error('Unexpected success alert'); },
  confirm: () => { throw new Error('Unexpected confirmation'); },
};
vm.createContext(context);
vm.runInContext(read('scripts/components/inventory-money-input.js'), context);

function input(value = '') {
  return { value, selectionStart: value.length, matches: () => true,
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; } };
}
function event(type, target, data = {}) {
  const e = { target, type, prevented: false, preventDefault() { this.prevented = true; }, ...data };
  listeners.get(type)(e);
  return e;
}
const price = input('100.25');
for (const key of ['a', 'b', 'e', 'E', '+', '-']) assert.equal(event('keydown', price, { key }).prevented, true);
for (const key of ['0', '9', '.', 'Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Tab'])
  assert.equal(event('keydown', price, { key }).prevented, false);
for (const modifier of ['ctrlKey', 'metaKey']) for (const key of ['a', 'c', 'v'])
  assert.equal(event('keydown', price, { key, [modifier]: true }).prevented, false);
for (const data of ['e', 'a100.25', '1E3']) assert.equal(event('beforeinput', price, { data }).prevented, true);
assert.equal(event('beforeinput', price, { data: '.25' }).prevented, false);
for (const text of ['a100.25b', '1e3', '₱100', 'abc']) {
  assert.equal(event('paste', price, { clipboardData: { getData: () => text } }).prevented, true);
  assert.equal(price.value, '100.25');
}
assert.equal(event('paste', price, { clipboardData: { getData: () => '125.50' } }).prevented, false);
const composed = input('12ab.345');
composed.selectionStart = 4;
event('input', composed);
assert.equal(composed.value, '12.345'); // Existing validation still rejects excessive precision.
assert.equal(composed.selectionStart, 2);
const unrelated = input('abc'); unrelated.matches = () => false;
event('input', unrelated); assert.equal(unrelated.value, 'abc');
const readonly = input('abc'); readonly.readOnly = true;
event('input', readonly); assert.equal(readonly.value, 'abc');

// Every editable monetary input, including lazy sections, uses the same handler.
for (const [section, models] of [
  ['products', ['form.unit_cost', 'form.selling_price', 'range.price']],
  ['stock-in', ['createForm.unit_cost', 'createForm.selling_price', 'range.price', 'unitCost']],
  ['sell-product', ['amountTendered']],
]) {
  const html = read(`pages/admin/inventory/sections/${section}.html`);
  for (const model of models) {
    const tag = [...html.matchAll(/<input\b[^>]*>/g)].map(match => match[0]).find(tag => tag.includes(`x-model="${model}"`));
    assert.ok(tag?.includes('data-money-input'), model);
  }
}
for (const file of ['pages/admin/inventory/inventory.html', 'pages/admin/inventory/sections/products.html']) {
  const html = read(file);
  assert.match(html, /<admin-loader\b/);
}

vm.runInContext(read('scripts/components/product-form.js'), context);
context.ProductForm = context.window.ProductForm;
vm.runInContext(read('scripts/components/admin-inventory-items.js'), context);
async function testSave(editing) {
  const page = context.adminInventoryItems();
  const calls = [];
  page.form = context.ProductForm.empty({ item_id: editing ? 1 : null, item_name: 'Test', category: 'food',
    unit: 'pc', unit_cost: '1.25', selling_price: '2.50', reorder_level: '0' });
  page.modal.open = true;
  context.InventoryAPI = { createItem: async () => calls.push('create'), updateItem: async () => calls.push('update') };
  page.load = async () => { assert.equal(page.modal.open, false); calls.push('refresh'); };
  page.showToast = message => { assert.equal(page.modal.open, false); calls.push(message); };
  await page.submitForm();
  assert.deepEqual(calls, [editing ? 'update' : 'create', 'refresh',
    editing ? 'Product updated successfully.' : 'Product added successfully.']);
  context.InventoryAPI.createItem = context.InventoryAPI.updateItem = async () => { throw { errors: { item_name: ['Save rejected'] } }; };
  page.modal.open = true; calls.length = 0;
  await page.submitForm();
  assert.equal(page.modal.open, true); assert.equal(page.modal.error, 'Save rejected'); assert.equal(calls.length, 0);
  page.confirmDeactivate({ item_id: 1 }); assert.equal(page.deactivateModal.open, true);
  assert.match(read('pages/admin/inventory/sections/products.html'), /Deactivate Product\?/);
}
(async () => {
  await testSave(false); await testSave(true);
  console.log('Inventory money editing, field coverage, dashboard loader reuse, toast ordering and failure/destructive feedback passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
