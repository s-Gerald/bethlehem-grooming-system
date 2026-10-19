const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
class Storage {
  values = new Map();
  getItem(key) { return this.values.get(key) ?? null; }
  setItem(key, value) { this.values.set(key, String(value)); }
  removeItem(key) { this.values.delete(key); }
}
function apiEnvironment() {
  const localStorage = new Storage(), sessionStorage = new Storage(), calls = [];
  let handler = () => ({ success: true });
  function page() {
    const context = vm.createContext({ localStorage, sessionStorage, URLSearchParams, URL, Date, console,
      setTimeout, clearTimeout, AbortController,
      document: { getElementById: () => null, querySelector: () => null, addEventListener() {} },
      window: { location: { protocol: 'http:', origin: 'http://127.0.0.1:8000', hostname: '127.0.0.1',
        port: '8000', pathname: '/pages/admin/dashboard.html', replace() {} }, addEventListener() {} },
      fetch: async (url, options) => { calls.push({ url, method: options.method }); const data = await handler(url, options);
        return { ok: true, status: 200, json: async () => data, headers: { get: () => null } }; },
    });
    vm.runInContext(read('scripts/api.js'), context);
    return context.API;
  }
  const API = page(); API.setAdminToken('1|local-admin');
  return { API, page, calls, localStorage, sessionStorage, respond: fn => { handler = fn; } };
}
async function sharedReads() {
  const e = apiEnvironment();
  const pending = deferred(); e.respond(() => pending.promise);
  const first = e.API.getAdminBookings('2026-10-18');
  const second = e.API.getAdminBookings('2026-10-18');
  assert.equal(e.calls.length, 1, 'Concurrent admin reads must share one network request');
  pending.resolve({ incomingList: [], version: 1 }); await Promise.all([first, second]);
  await e.API.getAdminBookings('2026-10-18');
  assert.equal(e.calls.length, 1, 'Fresh board data is reused');
  const nextPage = e.page();
  await nextPage.getAdminBookings('2026-10-18');
  assert.equal(e.calls.length, 1, 'Short-lived presentation data survives local page navigation');
  e.respond(() => ({ incomingList: [], version: 2 }));
  await nextPage.adminCheckIn(1);
  await nextPage.getAdminBookings('2026-10-18');
  assert.equal(e.calls.filter(c => c.method === 'GET').length, 2, 'Mutation invalidates board presentation');
  e.API.setAdminToken('2|other-admin');
  await e.API.getAdminBookings('2026-10-18');
  assert.equal(e.calls.filter(c => c.method === 'GET').length, 3, 'Account changes never reuse protected data');
}
async function mutationRace() {
  const e = apiEnvironment(), old = deferred();
  e.respond((url, options) => options.method === 'GET' ? old.promise : {});
  const pending = e.API.getAdminBookings('2026-10-18');
  await e.API.adminCheckIn(1);
  e.respond(() => ({ version: 2 }));
  const fresh = await e.API.getAdminBookings('2026-10-18');
  old.resolve({ version: 1 });
  await assert.rejects(pending, /changed|superseded/i);
  assert.equal(fresh.version, 2);
  assert.equal((await e.API.getAdminBookings('2026-10-18')).version, 2, 'Old reads cannot repopulate invalidated data');
}
async function lazySecurity() {
  let calls = 0;
  const c = vm.createContext({ URLSearchParams, document: {}, window: { location: { search: '' } },
    API: { getUserRole: () => 'admin', getAvailabilitySettings: async () => ({}),
      getAdminSecurityAccounts: async () => { calls++; return { admin: {}, staff: [] }; } } });
  vm.runInContext(read('scripts/components/admin-settings.js'), c);
  const ui = c.adminSettings(); ui.$nextTick = fn => fn();
  await ui.init(); assert.equal(calls, 0, 'General settings must not load Staff Accounts');
  await ui.selectSettingsTab('security'); assert.equal(calls, 1);
  await ui.selectSettingsTab('general'); await ui.selectSettingsTab('security');
  assert.equal(calls, 1, 'Previously loaded Security data is retained between tabs');
  const pending = deferred(); let refreshCalls = 0;
  c.API.getAdminSecurityAccounts = () => { refreshCalls++; return pending.promise; };
  const refresh = ui.loadSecurityAccounts();
  const duplicate = ui.loadSecurityAccounts();
  assert.equal(refreshCalls, 1, 'Overlapping Security refreshes share a request');
  assert.equal(ui.staffLoading, false, 'Background refresh retains staff rows');
  pending.resolve({ admin: {}, staff: [] }); await Promise.all([refresh, duplicate]);
}
async function boardRaces() {
  let epoch = 0;
  const requests = [], c = vm.createContext({ console, Date, Intl, URLSearchParams,
    window: {}, API: { loadAdminData: (key, load, render) => load().then(render), getAdminDataRevision: () => epoch, getAdminBookings: (date) => { const d = deferred(); requests.push({ date, ...d }); return d.promise; } } });
  vm.runInContext(read('scripts/components/admin-dashboard.js'), c);
  const ui = c.adminDashboard(); ui.refreshIcons = () => {}; ui.dispatchDashboardEvent = () => {};
  ui.selectedDate = '2026-10-18';
  const a = ui.loadAdminBookings(), duplicate = ui.loadAdminBookings();
  assert.equal(requests.length, 1, 'Board does not overlap identical reads');
  ui.selectedDate = '2026-10-19'; const b = ui.loadAdminBookings();
  ui.selectedDate = '2026-10-18'; const latest = ui.loadAdminBookings();
  requests.at(-1).resolve({ summary: { today: 3 }, incomingList: [] }); await latest;
  requests[1].resolve({ summary: { today: 2 } }); await b;
  requests[0].resolve({ summary: { today: 1 } }); await Promise.all([a, duplicate]);
  assert.equal(ui.todayCount, 3, 'An A → B → A date race must retain the newest response');
  const old = ui.loadAdminBookings();
  epoch++;
  const afterAction = ui.loadAdminBookings();
  assert.equal(requests.length, 5, 'A mutation must refresh even while an older poll is pending');
  requests[4].resolve({ summary: { today: 5 } }); await afterAction;
  requests[3].resolve({ summary: { today: 4 } }); await old;
  assert.equal(ui.todayCount, 5);
}
async function visiblePolling() {
  const intervals = new Map(), listeners = new Map(), windowListeners = new Map(); let calls = 0;
  const document = { visibilityState: 'visible', addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name) };
  const c = vm.createContext({ Date, Intl, URLSearchParams, console: { error() {}, warn() {} }, document,
    setInterval: fn => { intervals.set(1, fn); return 1; }, clearInterval: id => intervals.delete(id), clearTimeout() {},
    window: { addEventListener: (name, fn) => windowListeners.set(name, fn), removeEventListener: name => windowListeners.delete(name) }, API: { loadAdminData: (key, load, render) => load().then(render), getAdminBookings: async () => { calls++; throw new Error('offline'); }, getNotifications: async () => ({}) } });
  vm.runInContext(read('scripts/components/admin-dashboard.js'), c);
  const ui = c.adminDashboard(); ui.bookingsLoaded = true; ui.startContentPolling();
  document.visibilityState = 'hidden'; intervals.get(1)(); assert.equal(calls, 0, 'Hidden pages must not poll');
  document.visibilityState = 'visible';
  await ui.loadAdminBookings(); await ui.loadAdminBookings(); await ui.loadAdminBookings();
  assert.equal(intervals.size, 1, 'Transient board errors must not stop all shared polling');
  windowListeners.get('admin-session:unlocked')?.();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(calls, 4, 'Verified session unlock must immediately refresh existing content');
  ui.destroy(); assert.equal(intervals.size, 0); assert.equal(listeners.size, 0); assert.equal(windowListeners.size, 0);
}
(async () => { for (const test of [sharedReads, mutationRace, lazySecurity, boardRaces, visiblePolling]) { await test(); console.log(test.name + ' passed'); } })()
  .catch(error => { console.error(error); process.exitCode = 1; });
