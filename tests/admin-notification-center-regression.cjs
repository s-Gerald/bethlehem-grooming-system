const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
const read = file => fs.readFileSync(file, 'utf8');

async function filtersCacheAndCountReuse() {
  const e = environment(); e.respond(() => ({unread_count: 4, notifications: [{notification_id: 1}]}));
  const filters = {mode: 'full', status: 'unread', service: 'clinic', activity: 'payments', page: 1, per_page: 10};
  await Promise.all([e.API.getNotifications(filters), e.API.getNotifications(filters)]);
  assert.equal(e.calls.length, 1);
  const query = new URLSearchParams(e.calls[0].endpoint.split('?')[1]);
  assert.equal(query.get('service'), 'clinic'); assert.equal(query.get('activity'), 'payments');
  await e.page().API.getNotifications({mode: 'count'});
  assert.equal(e.calls.length, 1, 'A recent history response supplies the count across navigation');
  await e.API.markNotificationRead(1);
  await e.API.getNotifications({mode: 'count'});
  assert.equal(e.calls.length, 3, 'Mutation invalidates records and count together');
}

function controller(e) {
  e.c.Intl = Intl;
  vm.runInContext(read('scripts/services/admin-notification-service.js'), e.c);
  vm.runInContext(read('scripts/components/admin-notifications.js'), e.c);
  return e.c.adminNotifications();
}
async function filterRaceAndPagination() {
  const e = environment(), ui = controller(e), old = deferred();
  e.respond(() => old.promise);
  const first = ui.loadNotifications(true);
  e.respond(() => ({notifications: [{notification_id: 2, is_read: false}], unread_count: 1, pagination: {has_more: true}}));
  await ui.setService('clinic');
  assert.equal(ui.notifications[0].notification_id, 2); assert.equal(ui.page, 1);
  old.resolve({notifications: [{notification_id: 1}], unread_count: 9}); await first;
  assert.equal(ui.notifications[0].notification_id, 2, 'Old filter response cannot overwrite newer results');
  assert.equal(e.c.AdminNotificationService.unreadCount, 1, 'Old responses cannot replace the current unread badge');
  assert.equal((await e.API.getNotifications({mode: 'count'})).unread_count, 1, 'The shared count cache also keeps the newer response');
  e.respond(() => ({notifications: [{notification_id: 3}], unread_count: 1, pagination: {has_more: false}}));
  await Promise.all([ui.loadMore(), ui.loadMore()]);
  assert.equal(ui.page, 2); assert.equal(ui.notifications.length, 2);
  await ui.setActivity('payments'); assert.equal(ui.page, 1);
  assert.equal(new URLSearchParams(e.calls.at(-1).endpoint.split('?')[1]).get('service'), 'clinic');
}
async function mutationRacesAndUserIsolation() {
  const e = environment(), ui = controller(e), held = deferred();
  e.respond(() => held.promise); const old = ui.loadNotifications(true);
  e.respond(() => ({unread_count: 0})); await e.c.AdminNotificationService.markAllRead();
  held.resolve({unread_count: 7, notifications: [{notification_id: 1, is_read: false}]});
  await old;
  assert.equal(e.c.AdminNotificationService.unreadCount, 0);
  assert.equal(ui.notifications.length, 0, 'Mutation revision rejects in-flight unread records');
  e.API.setAdminToken('2|other-user');
  assert.equal(e.API.readAdminCache('/admin/notifications?mode=count'), null);
}
async function sidebarOrigin() {
  const e = environment(); e.c.document.querySelectorAll = () => [];
  vm.runInContext(read('scripts/components/admin-sidebar.js'), e.c);
  for (const [from, expected] of [['dashboard', 'dashboard'], ['grooming', 'appointments'], ['clinic', 'clinic'], ['', ''], ['payments', ''], ['__proto__', '']]) {
    assert.equal(e.c.getAdminSidebarActivePage('/pages/admin/notifications.html', `?from=${from}`), expected);
  }
}
async function sharedPollingAndLifecycle() {
  const e = environment(), timers = new Map(), documentEvents = new Map();
  e.c.setInterval = fn => {timers.set(fn, fn); return fn;};
  e.c.clearInterval = id => timers.delete(id);
  e.c.window.dispatchEvent = event => e.events.get(event.type)?.(event);
  e.c.window.removeEventListener = name => e.events.delete(name);
  e.c.document.addEventListener = (name, fn) => documentEvents.set(name, fn);
  e.c.document.removeEventListener = name => documentEvents.delete(name);
  e.c.document.visibilityState = 'visible';
  e.respond(() => ({unread_count: 2})); controller(e);
  const service = e.c.AdminNotificationService, changes = [];
  const a = service.subscribe(event => changes.push(event.kind));
  const b = service.subscribe(() => {});
  await new Promise(resolve => setTimeout(resolve, 10));
  assert.equal(e.calls.length, 1); assert.equal(timers.size, 1, 'Consumers share one count timer');
  e.advance(31000); e.c.document.visibilityState = 'hidden';
  for (const tick of timers.values()) await tick();
  assert.equal(e.calls.length, 1, 'Hidden tabs never poll');
  e.c.document.visibilityState = 'visible'; documentEvents.get('visibilitychange')();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(e.calls.length, 2); assert.ok(e.calls.every(call => call.endpoint === '/admin/notifications?mode=count'));
  e.c.window.dispatchEvent({type: 'admin-session:locked'});
  assert.equal(service.unreadCount, 0); assert.ok(changes.includes('reset'));
  a(); assert.equal(timers.size, 1); b();
  assert.equal(timers.size, 0); assert.equal(documentEvents.size, 0);
  assert.equal(e.events.has('admin-data:invalidated'), false, 'Destroy removes listeners');
}
async function concurrentReadMutations() {
  const e = environment(); controller(e);
  const first = deferred();
  e.respond(endpoint => endpoint.includes('/1/read') ? first.promise : {unread_count: 0});
  const a = e.c.AdminNotificationService.markRead(1);
  const b = e.c.AdminNotificationService.markRead(2);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(e.calls.length, 1, 'Read mutations run in order so delayed counts cannot overwrite newer counts');
  first.resolve({unread_count: 1}); await Promise.all([a, b]);
  assert.equal(e.c.AdminNotificationService.unreadCount, 0);
}
async function fullHistoryAvoidsCountRequest() {
  const e = environment(); e.c.setInterval = () => 1; e.c.clearInterval = () => {};
  const hold = deferred(); e.respond(() => hold.promise);
  const ui = controller(e); const loading = ui.init();
  await new Promise(resolve => setTimeout(resolve, 10));
  const count = e.calls.length;
  hold.resolve({unread_count: 3, notifications: []}); await loading;
  assert.equal(count, 1, 'Full history already supplies the unread count at startup');
  assert.ok(e.calls[0].endpoint.includes('mode=full'));
}
async function visiblePreviewFollowsCountChanges() {
  const e = environment(), ui = controller(e);
  ui.mode = 'dropdown'; ui.open = true; ui.loaded = true; ui.unreadCount = 1;
  e.respond(() => ({unread_count: 2, notifications: [{notification_id: 42}]}));
  await ui.onNotificationChange({kind: 'count', unreadCount: 2});
  assert.equal(ui.notifications[0].notification_id, 42, 'A changed count refreshes an already open preview');
  const count = e.calls.length;
  await ui.onNotificationChange({kind: 'count', unreadCount: 2});
  ui.open = false; await ui.onNotificationChange({kind: 'count', unreadCount: 3});
  assert.equal(e.calls.length, count, 'Unchanged counts and closed bells never reload records');
}
async function serverNoShowRefreshesVisibleHistory() {
  const e = environment(), ui = controller(e);
  ui.loaded = true; ui.unreadCount = 0;
  e.respond(() => ({unread_count: 1, notifications: [{notification_id: 43, type: 'clinic_no_show', activity: 'expired'}]}));
  await ui.onNotificationChange({kind: 'count', unreadCount: 1});
  assert.equal(ui.notifications[0]?.type, 'clinic_no_show', 'A server-generated event refreshes visible full history');
  const count = e.calls.length;
  await ui.onNotificationChange({kind: 'count', unreadCount: 1});
  assert.equal(e.calls.length, count, 'An unchanged count does not fetch history again');
}
async function changedServerCountInvalidatesCachedHistory() {
  const e = environment();
  const filters = {mode: 'full', service: 'clinic', activity: 'expired'};
  e.respond(() => ({unread_count: 0, notifications: []}));
  await e.API.getNotifications(filters);
  e.advance(31000);
  // A history view can be fetched shortly before the next count poll.
  await e.API.getNotifications(filters);
  e.respond(() => ({unread_count: 1, notifications: [{notification_id: 44, type: 'clinic_no_show'}]}));
  await e.API.getNotifications({mode: 'count'});
  // Force the count request through the existing request API, without waiting for history to age.
  await e.API.adminRequest('GET', '/admin/notifications?mode=count', null, {force: true});
  const data = await e.API.getNotifications(filters);
  assert.equal(data.notifications[0]?.type, 'clinic_no_show', 'An authoritative changed count invalidates cached record pages');
}
(async () => {
  for (const test of [filtersCacheAndCountReuse, filterRaceAndPagination, mutationRacesAndUserIsolation, sidebarOrigin, sharedPollingAndLifecycle, concurrentReadMutations, fullHistoryAvoidsCountRequest, visiblePreviewFollowsCountChanges, serverNoShowRefreshesVisibleHistory, changedServerCountInvalidatesCachedHistory]) {
    await test(); console.log(`${test.name} passed`);
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
