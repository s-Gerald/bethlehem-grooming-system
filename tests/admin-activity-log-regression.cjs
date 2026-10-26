const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const postcss = require('postcss');
const source = fs.readFileSync('scripts/components/admin-activity-log.js', 'utf8');
const defer = () => { let resolve, reject; const promise = new Promise((yes, no) => {resolve = yes; reject = no;}); return {promise, resolve, reject}; };

async function run() {
  const listeners = new Map(); let focused, calls = [];
  const c = vm.createContext({Intl, Date, console, API: {}, window: {
    addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name),
  }, document: {activeElement: null}});
  vm.runInContext(source, c);
  const ui = c.adminActivityLog();
  ui.$nextTick = fn => fn();
  const focusable = () => ({attributes: new Map(), setAttribute(name, value) {this.attributes.set(name, value);},
    removeAttribute(name) {this.attributes.delete(name);}, getAttribute(name) {return this.attributes.get(name);},
    focus(options) {focused = this; this.focusOptions = options;}, getClientRects: () => [1]});
  const close = focusable();
  ui.$refs = {content: {inert: false}, drawerClose: close, drawer: {querySelectorAll: () => [close]}};
  ui.$root = {closest: () => null, querySelectorAll: () => [close, trigger]};
  ui.resetFilters();
  assert.equal(ui.filters.area, 'all');
  assert.equal((Date.parse(ui.filters.to) - Date.parse(ui.filters.from)) / 86400000, 29);
  const first = defer(), next = defer();
  c.API.getAdminActivityLog = params => {calls.push(params); return calls.length === 1 ? first.promise : next.promise;};
  const old = ui.load(); ui.filters.search = 'Shampoo'; const current = ui.changeFilters();
  next.resolve({data: [{id: 2}], current_page: 1, last_page: 3, total: 60}); await current;
  first.resolve({data: [{id: 1}], current_page: 1, last_page: 1, total: 1}); await old;
  assert.equal(ui.rows[0].id, 2); assert.equal(ui.total, 60); assert.equal(calls[1].search, 'Shampoo');
  c.API.getAdminActivityLog = async () => {throw new Error('private database details');};
  await ui.load(2); assert.equal(ui.error, "Couldn't load activity."); assert.equal(ui.loading, false);
  const details = defer(); c.API.getAdminActivityDetails = () => details.promise;
  const trigger = {...focusable(), isConnected: true};
  const opening = ui.openDetails({id: 2}, trigger);
  assert.equal(ui.drawerOpen, true); assert.equal(ui.$refs.content.inert, true); assert.equal(focused, close);
  c.document.activeElement = close;
  let prevented = false; ui.trapFocus({key: 'Tab', shiftKey: true, preventDefault: () => {prevented = true;}});
  assert.equal(prevented, true);
  ui.closeDetails(); details.resolve({id: 2}); await opening;
  assert.equal(ui.detail, null, 'A response arriving after close must not reopen or repopulate the drawer');
  assert.equal(ui.$refs.content.inert, false); assert.equal(focused, trigger);
  await ui.openDetails({id: 2}, trigger, {detail: 1});
  assert.equal(close.getAttribute('data-pointer-focus'), 'true', 'Programmatic pointer focus must suppress the inherited keyboard outline');
  ui.closeDetails();
  assert.equal(trigger.getAttribute('data-pointer-focus'), 'true');
  ui.setInteractionMode(true);
  assert.equal(trigger.getAttribute('data-pointer-focus'), undefined, 'Keyboard input restores focus-visible styling');
  await ui.openDetails({id: 2}, trigger, {detail: 0});
  assert.equal(close.getAttribute('data-pointer-focus'), undefined);
  ui.closeDetails(); assert.equal(trigger.getAttribute('data-pointer-focus'), undefined);
  assert.equal(trigger.focusOptions.preventScroll, true);
  await ui.openDetails({id: 2}, trigger, {detail: 0, pointerType: 'touch'});
  assert.equal(close.getAttribute('data-pointer-focus'), 'true', 'Touch activation is treated as pointer input even with zero click detail');
  ui.closeDetails();
  assert.equal(ui.changeLabel('booking_date'), 'Visit day');
  assert.equal(ui.changeValue(false), 'No');
  assert.equal(ui.changeValue('checked_in', 'status'), 'Checked in');
  const css = postcss.parse(fs.readFileSync('css/components/admin-activity.css', 'utf8'));
  const variables = new Map(), indicators = new Map();
  css.walkRules(rule => {
    if (rule.selector === '.activity-dot') rule.walkDecls(decl => variables.set(decl.prop, decl.value));
    for (const area of ['grooming', 'clinic', 'system']) {
      if (rule.selector === `.activity-dot[data-activity-area="${area}"]`) rule.walkDecls('background-color', decl => indicators.set(area, decl.value));
    }
    if (rule.selector === '.activity-dot') rule.walkDecls('background-color', decl => indicators.set('shared', decl.value));
  });
  for (const [area, color] of Object.entries({grooming: '#247FC0', clinic: '#15947D', system: '#7861A8', shared: '#7B8796'})) {
    const token = indicators.get(area).match(/^var\((.+)\)$/)[1];
    assert.equal(variables.get(token), color);
  }
  assert.equal(variables.get('width'), '8px'); assert.equal(variables.get('height'), '8px');
  const staleOptions = defer();
  c.API.getAdminActivityOptions = () => staleOptions.promise;
  const optionsRequest = ui.loadOptions();
  ui._optionsRequest++;
  staleOptions.resolve({staff: [{actor_id: 1}], type_groups: [{label: 'Prior session', options: []}]});
  await optionsRequest;
  assert.equal(ui.staff.length, 0, 'Invalidated sessions must discard pending staff-filter identities');
  ui.destroy(); assert.equal(listeners.size, 0);
  console.log('Activity filters, pagination state, stale response protection, safe errors and accessible drawer lifecycle passed');
}
run().catch(error => {console.error(error); process.exitCode = 1;});
