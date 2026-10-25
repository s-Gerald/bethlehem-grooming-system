const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
const source = fs.readFileSync('scripts/components/admin-dashboard.js', 'utf8');
const summary = { summary: { completedToday: { grooming: 4, clinic: 3, total: 7 }, revenueToday: 1200, revenuePaymentCount: 2, waitingNow: 3 },
  canViewRevenue: true, capacity: { current: 6, max: 20 }, queuePreview: [{ id: 1 }], activeTodayCount: 4 };
const failure = status => ({ ok: false, status, json: async () => ({message: 'Unavailable'}), headers: {get: () => null} });
const settle = () => new Promise(setImmediate);
function component(e, page = e) {
  page.c.Intl = Intl;
  vm.runInContext(source, page.c);
  const ui = page.c.adminDashboard();
  ui.pageMode = 'dashboard';
  ui.refreshIcons = ui.dispatchDashboardEvent = ui.closePaymentScanner = () => {};
  return ui;
}
function polling(e) {
  const intervals = new Map(), documentListeners = new Map(), windowListeners = new Map();
  let next = 0;
  const add = (map, name, fn) => { if (!map.has(name)) map.set(name, new Set()); map.get(name).add(fn); };
  e.c.setInterval = fn => { intervals.set(++next, fn); return next; };
  e.c.clearInterval = id => intervals.delete(id);
  e.c.document.addEventListener = (name, fn) => add(documentListeners, name, fn);
  e.c.document.removeEventListener = (name, fn) => documentListeners.get(name)?.delete(fn);
  e.c.window.addEventListener = (name, fn) => add(windowListeners, name, fn);
  e.c.window.removeEventListener = (name, fn) => windowListeners.get(name)?.delete(fn);
  return { intervals, documentListeners, windowListeners,
    tick: async ms => { e.advance(ms); for (const fn of intervals.values()) fn(); await settle(); },
    visible: async () => { for (const fn of documentListeners.get('visibilitychange') || []) fn(); await settle(); } };
}
async function retainedFailureIsVisibleAndRetryRecovers() {
  const e = environment(), ui = component(e);
  e.respond(() => summary); await ui.loadDashboardSummary(); e.advance(16000);
  e.respond(() => failure(500)); await ui.loadDashboardSummary();
  assert.ok(ui.summaryError, 'A retained cache hit must not hide a failed server refresh');
  assert.match(ui.summaryError, /outdated/i);
  assert.equal(ui.completedToday.total, 7); assert.equal(ui.summaryLoaded, true);
  const count = e.calls.length;
  await ui.loadDashboardSummary(); assert.equal(e.calls.length, count, 'Automatic refresh observes backoff');
  e.respond(() => ({...summary, summary: {...summary.summary, completedToday: { grooming: 4, clinic: 4, total: 8 }}}));
  await ui.loadDashboardSummary({force: true});
  assert.equal(ui.completedToday.total, 8); assert.equal(ui.summaryError, '');
  e.advance(30000); await ui.loadDashboardSummary();
  assert.equal(e.calls.length, count + 2, 'Success restores the normal polling cadence');
}
async function boundedFailuresAndManualRetryShareOneRequest() {
  const e = environment(), ui = component(e), timer = polling(e);
  e.respond(endpoint => endpoint.endsWith('/summary') ? failure(500) : {recentActivity: []});
  await ui.loadDashboardSummary(); ui.startContentPolling();
  const count = () => e.calls.filter(c => c.endpoint.endsWith('/summary')).length;
  assert.equal(count(), 1); assert.equal(ui.summaryLoaded, false); assert.ok(ui.summaryError);
  await timer.tick(30000); assert.equal(count(), 1);
  await timer.tick(30000); assert.equal(count(), 2);
  for (let i = 0; i < 3; i++) await timer.tick(30000);
  assert.equal(count(), 2);
  await timer.tick(30000); assert.equal(count(), 3, 'Repeated failures retry at most every two minutes');
  const held = deferred(); e.respond(() => held.promise);
  const a = ui.loadDashboardSummary({force: true}), b = ui.loadDashboardSummary({force: true});
  assert.equal(a, b); assert.equal(count(), 4);
  held.resolve(summary); await a;
  assert.equal(ui.summaryError, ''); assert.equal(ui.summaryLoading, false);
  ui.destroy();
}
async function singlePollAndVisibilityLifecycle() {
  const e = environment(), ui = component(e), timer = polling(e);
  e.respond(endpoint => endpoint.endsWith('/summary') ? summary : {recentActivity: []});
  ui.startContentPolling(); ui.startContentPolling();
  assert.equal(timer.intervals.size, 1, 'Repeated startup owns one interval');
  assert.equal(timer.documentListeners.get('visibilitychange').size, 1);
  assert.equal(timer.windowListeners.get('admin-session:unlocked').size, 1);
  e.c.document.visibilityState = 'hidden'; await timer.tick(30000); assert.equal(e.calls.length, 0);
  e.c.document.visibilityState = 'visible'; await timer.visible(); assert.equal(e.calls.length, 2);
  await timer.visible(); assert.equal(e.calls.length, 2, 'Visibility changes reuse fresh data');
  const held = deferred(); e.respond(() => held.promise);
  await timer.tick(30000); await timer.tick(30000);
  assert.equal(e.calls.length, 4, 'A slow refresh does not overlap the next poll');
  ui.destroy(); assert.equal(timer.intervals.size, 0);
  assert.equal(timer.documentListeners.get('visibilitychange').size, 0);
  held.resolve({...summary, summary: {completedToday: {grooming: 99, clinic: 0, total: 99}}}); await settle();
  assert.equal(ui.completedToday.total, 7, 'Destroyed components ignore late responses');
  await timer.tick(120000); assert.equal(e.calls.length, 4);
}
async function navigationRefreshAndSessionOwnership() {
  const e = environment(); e.respond(() => summary);
  await component(e).loadDashboardSummary();
  for (const [pathname, endpoint] of [['grooming-schedule.html', '/admin/bookings?date=2026-10-21&view=board'],
    ['clinic.html', '/admin/clinic-cases'], ['clients.html', '/admin/customers?status=active']]) {
    const page = e.page(); page.c.window.location.pathname = '/pages/admin/'+pathname;
    await page.API.adminRequest('GET', endpoint);
    await component(e, e.page()).loadDashboardSummary();
  }
  assert.equal(e.calls.filter(c => c.endpoint.endsWith('/summary')).length, 1, 'Returning from other HTML pages reuses a fresh summary');
  e.advance(16000); const held = deferred(); e.respond(() => held.promise);
  const refreshed = component(e, e.page()), pending = refreshed.loadDashboardSummary();
  assert.equal(refreshed.summaryLoaded, true, 'Retained metrics appear before revalidation');
  assert.equal(refreshed.completedToday.total, 7); assert.equal(refreshed.summaryLoading, true);
  held.resolve(summary); await pending;
  e.advance(121000); e.respond(() => failure(500));
  const expired = component(e, e.page()); await expired.loadDashboardSummary();
  assert.equal(expired.summaryLoaded, false, 'A hard refresh cannot render expired presentation data');
  e.API.setAdminToken('2|another-session');
  const other = component(e, e.page()); await other.loadDashboardSummary();
  assert.equal(other.summaryLoaded, false, 'A different session never renders prior metrics');
  e.API.setUserRole('staff');
  const staff = component(e, e.page()); await staff.loadDashboardSummary(); assert.equal(staff.summaryLoaded, false);
}
async function activityFailuresAlsoRetainAndRecover() {
  const e = environment(), ui = component(e);
  e.respond(() => ({recentActivity: [{id: 1, title: 'Payment collected'}]}));
  await ui.loadDashboardActivity(); e.advance(16000);
  e.respond(() => failure(503)); await ui.loadDashboardActivity();
  assert.equal(ui.recentActivity.length, 1); assert.match(ui.activityError, /outdated/i);
  e.respond(() => ({recentActivity: []})); await ui.loadDashboardActivity({force: true});
  assert.equal(ui.activityError, ''); assert.equal(ui.recentActivity.length, 0);
}
async function invalidationDoesNotOverlapSummaryRequests() {
  const e = environment(), held = deferred(); e.respond(() => held.promise);
  const old = e.API.getAdminDashboardSummary();
  e.respond(() => ({})); await e.API.adminRequest('POST', '/admin/bookings/1/mark-done', {});
  const next = e.API.getAdminDashboardSummary({force: true});
  assert.equal(e.calls.filter(c => c.endpoint.endsWith('/summary')).length, 1,
    'Mutation invalidation must not start a parallel summary request');
  held.resolve(summary);
  await Promise.all([assert.rejects(old, /changed/), assert.rejects(next, /changed/)]);
  e.respond(() => summary); await e.API.getAdminDashboardSummary();
  assert.equal(e.calls.filter(c => c.endpoint.endsWith('/summary')).length, 2);
}
async function revenueRequiresServerAuthorizationAndClearsOnRoleChange() {
  const e = environment(), ui = component(e), timer = polling(e);
  e.API.setUserRole('admin');
  e.respond(() => ({...summary, canViewRevenue: false}));
  await ui.loadDashboardSummary();
  assert.equal(ui.canViewRevenue, false, 'A locally editable admin role cannot reveal revenue');
  assert.equal(ui.revenueToday, 0);
  e.respond(() => summary); await ui.loadDashboardSummary({force: true});
  assert.equal(ui.canViewRevenue, true);
  assert.equal(ui.revenueToday, 1200);
  ui.startContentPolling();
  const held = deferred(); e.respond(() => held.promise);
  const pending = ui.loadDashboardSummary({force: true});
  for (const fn of timer.windowListeners.get('admin-navigation:invalidated') || []) fn({detail: {reload: true}});
  assert.equal(ui.canViewRevenue, false);
  assert.equal(ui.revenueToday, 0);
  held.resolve(summary); await pending;
  assert.equal(ui.canViewRevenue, false, 'A stale admin response cannot re-enable revenue');
  assert.equal(ui.revenuePaymentCount, 0);
  assert.deepEqual(JSON.parse(JSON.stringify(ui.completedToday)), {}, 'Role invalidation clears completion data too');
  ui.destroy();
}
async function activityFiltersRejectStaleResponsesAndPreserveSummary() {
  const e = environment(), ui = component(e);
  ui.activityCanFilter = true; ui.activityArea = 'all'; ui.completedToday.total = 9;
  const first = deferred(), second = deferred();
  e.respond(endpoint => endpoint.includes('area=grooming') ? first.promise : second.promise);
  const grooming = ui.setActivityArea('grooming');
  const clinic = ui.setActivityArea('clinic');
  second.resolve({area: 'clinic', canFilter: true, recentActivity: [{id: 2, area: 'clinic', title: 'Consultation started'}]});
  await clinic;
  first.resolve({area: 'grooming', canFilter: true, recentActivity: [{id: 1, title: 'Grooming started'}]});
  await grooming;
  assert.equal(ui.activityArea, 'clinic'); assert.equal(ui.recentActivity[0].id, 2);
  assert.equal(ui.recentActivity[0].area, 'clinic', 'The feed retains recorded department metadata for the shared color rules');
  assert.equal(ui.completedToday.total, 9, 'Changing activity must preserve dashboard metrics');
  assert.equal(e.calls.length, 2);
  ui.activityCanFilter = false;
  await ui.setActivityArea('grooming');
  assert.equal(e.calls.length, 2, 'Clinic-only users have no interactive cross-area filter');
}

async function completionBreakdownFollowsServerRoleAndReplacesOldData() {
  const e = environment(), ui = component(e);
  e.respond(() => summary); await ui.loadDashboardSummary();
  assert.deepEqual(JSON.parse(JSON.stringify(ui.completedToday)), {grooming: 4, clinic: 3, total: 7});
  const restored = component(e, e.page());
  restored.applyDashboardData(ui.getState());
  assert.deepEqual(JSON.parse(JSON.stringify(restored.completedToday)), {grooming: 4, clinic: 3, total: 7});
  e.respond(() => ({canViewRevenue: false, summary: {completedToday: {grooming: 4, clinic: 3}}}));
  await ui.loadDashboardSummary({force: true});
  assert.deepEqual(JSON.parse(JSON.stringify(ui.completedToday)), {grooming: 4, clinic: 3});
  e.respond(() => ({clinicOnly: true, canViewRevenue: false, summary: {completedToday: {clinic: 3}}}));
  await ui.loadDashboardSummary({force: true});
  assert.deepEqual(JSON.parse(JSON.stringify(ui.completedToday)), {clinic: 3});
  assert.equal(ui.clinicOnly, true);
  assert.equal(e.calls.length, 3, 'One existing request per refresh supplies all metrics');
}

(async () => {
  let failed = 0;
  for (const test of [retainedFailureIsVisibleAndRetryRecovers, boundedFailuresAndManualRetryShareOneRequest,
    singlePollAndVisibilityLifecycle, navigationRefreshAndSessionOwnership, activityFailuresAlsoRetainAndRecover,
    invalidationDoesNotOverlapSummaryRequests, revenueRequiresServerAuthorizationAndClearsOnRoleChange,
    activityFiltersRejectStaleResponsesAndPreserveSummary, completionBreakdownFollowsServerRoleAndReplacesOldData]) {
    try { await test(); console.log(test.name+' passed'); }
    catch (error) { failed++; console.error(test.name, error); }
  }
  process.exitCode = failed ? 1 : 0;
})();
