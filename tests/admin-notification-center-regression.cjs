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
  if (!e.c.getAdminNotificationOrigin) {
    e.c.document.querySelectorAll = () => [];
    vm.runInContext(read('scripts/components/admin-sidebar.js'), e.c);
  }
  vm.runInContext(read('scripts/services/admin-notification-service.js'), e.c);
  vm.runInContext(read('scripts/components/admin-notifications.js'), e.c);
  return e.c.adminNotifications();
}
async function originsNeverClaimCurrentPage() {
  for (const file of ['dashboard', 'activity-log', 'availability', 'settings']) {
    const e = environment(), attributes = {};
    Object.assign(e.c.window.location, {pathname: '/pages/admin/notifications.html', search: '?return_to=' + encodeURIComponent(`/pages/admin/${file}.html`)});
    const link = {href: e.c.window.location.origin + `/pages/admin/${file}.html`, closest: () => null,
      setAttribute: (name, value) => {attributes[name] = value;}};
    e.c.document.querySelectorAll = () => [link];
    vm.runInContext(read('scripts/components/admin-sidebar.js'), e.c);
    assert.equal(attributes[':aria-current'], 'null', `${file} is origin context, never the Notifications current page`);
  }
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
  for (const [path, expected, section = ''] of [
    ['dashboard.html', 'dashboard'], ['clients.html?q=pet', 'customers'],
    ['appointments.html', 'appointments'], ['clinic.html#records', 'clinic'],
    ['inventory/inventory.html#overview', 'inventory', 'overview'],
    ['inventory/inventory.html#products?filter=expiry', 'inventory', 'products'],
    ['inventory/inventory.html#history', 'inventory', 'history'],
    ['inventory/inventory.html#stock-in?item=1&from=products', 'inventory', 'stock-in'],
    ['inventory/inventory.html#__proto__', 'inventory', 'overview'],
    ...['archive', 'transactions', 'reports', 'services', 'settings', 'availability', 'activity-log'].map(p => [p + '.html', p]),
  ]) {
    const returnTo = '/pages/admin/' + path;
    const search = '?return_to=' + encodeURIComponent(returnTo);
    assert.equal(e.c.getAdminSidebarActivePage('/pages/admin/notifications.html', search), expected);
    const context = e.c.getAdminNotificationOrigin(search);
    assert.equal(context.href, returnTo);
    assert.equal(context.section, section);
  }
  for (const value of ['', '//example.com/pages/admin/dashboard.html', 'https://example.com/pages/admin/dashboard.html',
    '/pages/admin/sign-in.html', '/pages/admin/notifications.html', '/pages/admin/../client/dashboard.html', '__proto__', '/pages/admin/dashboard.html/extra']) {
    const search = '?return_to=' + encodeURIComponent(value);
    assert.equal(e.c.getAdminSidebarActivePage('/pages/admin/notifications.html', search), '');
    assert.equal(e.c.getAdminNotificationOrigin(search), null);
  }
}

async function targetedNavigationAndReadState() {
  const e = environment(); e.c.document.querySelectorAll = () => [];
  vm.runInContext(read('scripts/components/admin-sidebar.js'), e.c);
  const ui = controller(e);
  e.c.window.removeEventListener = name => e.events.delete(name);
  e.c.document.removeEventListener = () => {};
  Object.assign(e.c.window.location, {pathname: '/pages/admin/inventory/inventory.html', search: '?q=soap', hash: '#products?filter=expiry'});
  let navigated;
  e.c.window.location.assign = value => {navigated = new URL(value, e.c.window.location.origin);};
  ui.mode = 'dropdown';
  for (const is_read of [false, true]) {
    await ui.activateNotification({notification_id: 42, is_read}, {detail: 1});
    assert.equal(navigated.pathname, '/pages/admin/notifications.html');
    assert.equal(navigated.searchParams.get('selected_id'), '42');
    assert.equal(navigated.searchParams.get('return_to'), '/pages/admin/inventory/inventory.html?q=soap#products?filter=expiry');
    assert.equal(navigated.searchParams.has('focus'), false);
  }
  await ui.activateNotification({notification_id: 42, is_read: true}, {detail: 0});
  assert.equal(navigated.searchParams.get('focus'), 'keyboard', 'Keyboard activation preserves a visible destination focus indicator');
  assert.equal(e.calls.length, 0, 'Dropdown navigation never starts a read mutation');
  const selected = {notification_id: 42, is_read: false, created_at: '2026-01-01'};
  Object.assign(e.c.window.location, {pathname: '/pages/admin/notifications.html', search: '?selected_id=42', hash: ''});
  e.c.setInterval = () => 1; e.c.clearInterval = () => {};
  const full = e.c.adminNotifications();
  full.$nextTick = fn => fn(); full.$root = {querySelector: () => null};
  e.respond(endpoint => endpoint.endsWith('/read') ? {unread_count: 0} : {
    unread_count: 1, notifications: [{notification_id: 1, is_read: true}], selected_notification: selected, pagination: {has_more: true},
  });
  await full.init();
  assert.equal(full.notifications.some(n => n.notification_id === 42 && n.is_read), true);
  assert.equal(e.calls.filter(c => c.method === 'PATCH').length, 1);
  assert.equal(new URLSearchParams(e.calls[0].endpoint.split('?')[1]).get('selected_id'), '42');
  assert.equal(full.highlightedId, '42');
  e.respond(() => ({unread_count: 0, notifications: [{...selected, is_read: true}], pagination: {has_more: false}}));
  await full.loadMore();
  assert.equal(full.notifications.filter(n => n.notification_id === 42).length, 1, 'Pagination never duplicates the selected row');
  await full.setStatus('unread');
  assert.equal(full.selectedId, '', 'Manual filtering releases the selected record');
  assert.equal(new URLSearchParams(e.calls.at(-1).endpoint.split('?')[1]).has('selected_id'), false);
  full.destroy();
}

async function selectedReadFailuresAndFocus() {
  for (const scenario of ['read', 'keyboard', 'unread', 'unavailable', 'failed', 'invalid']) {
    const e = environment();
    const ui = controller(e);
    e.c.setInterval = () => 1; e.c.clearInterval = () => {};
    e.c.window.removeEventListener = () => {}; e.c.document.removeEventListener = () => {};
    Object.assign(e.c.window.location, {pathname: '/pages/admin/notifications.html', search: '?selected_id=' + (scenario === 'invalid' ? 'bad' : '42') + (scenario === 'keyboard' ? '&focus=keyboard' : '')});
    let focused = 0, scrolled = 0;
    const focusState = {};
    ui.$nextTick = fn => fn();
    ui.$root = {querySelector: selector => {
      assert.equal(selector, '[data-notification-id="42"]');
      return {dataset: focusState, focus: options => {assert.equal(options.preventScroll, true); focused++;}, scrollIntoView: () => scrolled++};
    }};
    e.respond((endpoint, options) => {
      if (options.method === 'PATCH') {
        if (scenario === 'failed') return {ok: false, status: 500, json: async () => ({message: 'Read failed.'}), headers: {get: () => null}};
        return {unread_count: 0};
      }
      return {unread_count: scenario === 'read' ? 0 : 1, notifications: [],
        selected_notification: scenario === 'unavailable' ? null : {notification_id: 42, is_read: ['read', 'keyboard'].includes(scenario)}};
    });
    await ui.init();
    if (['unavailable', 'invalid'].includes(scenario)) {
      assert.equal(ui.notice, 'Notification unavailable.');
      assert.equal(focused, 0);
    } else {
      assert.equal(focused, 1); assert.equal(scrolled, 1);
      assert.equal(focusState.pointerFocus, scenario === 'keyboard' ? undefined : 'true');
      assert.equal(ui.notifications.length, 1);
      assert.equal(ui.error, scenario === 'failed' ? 'Read failed.' : '');
    }
    assert.equal(e.calls.filter(c => c.method === 'PATCH').length, ['unread', 'failed'].includes(scenario) ? 1 : 0);
    assert.equal(e.calls.filter(c => c.method === 'GET').length, 1, 'Target lookup shares the initial history request');
    ui.destroy();
  }
}

async function responsiveBellKeepsOneController() {
  const e = environment(), ui = controller(e);
  const home = {insertBefore: host => {host.parentNode = home;}};
  const mobile = {append: host => {host.parentNode = mobile;}};
  const products = {append: host => {host.parentNode = products;}};
  let mutations = 0;
  const host = {parentNode: home, before: comment => {comment.parentNode = home;}};
  ui.$el = {closest: () => host};
  e.c.document.createComment = () => ({});
  e.c.document.querySelector = selector => selector === '[data-notification-mobile]' ? mobile : selector === '[data-notification-slot="products"]' ? products : null;
  e.c.Alpine = {mutateDom: fn => {mutations++; fn();}};
  Object.assign(e.c.window.location, {pathname: '/pages/admin/inventory/inventory.html', hash: '#products', search: ''});
  e.c.window.innerWidth = 375; ui.placeBell(); assert.equal(host.parentNode, mobile);
  ui.placeBell(); assert.equal(mutations, 1, 'Unchanged placement performs no DOM mutation');
  e.c.window.innerWidth = 1280; ui.placeBell(); assert.equal(host.parentNode, products);
  e.c.window.innerWidth = 375; ui.placeBell(); assert.equal(host.parentNode, mobile);
  assert.equal(e.calls.length, 0, 'Responsive movement never initializes a second request owner');
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
  for (const test of [filtersCacheAndCountReuse, filterRaceAndPagination, mutationRacesAndUserIsolation, sidebarOrigin, targetedNavigationAndReadState, selectedReadFailuresAndFocus, responsiveBellKeepsOneController, sharedPollingAndLifecycle, concurrentReadMutations, fullHistoryAvoidsCountRequest, visiblePreviewFollowsCountChanges, serverNoShowRefreshesVisibleHistory, changedServerCountInvalidatesCachedHistory]) {
    await test(); console.log(`${test.name} passed`);
  }
  await originsNeverClaimCurrentPage(); console.log('originsNeverClaimCurrentPage passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
