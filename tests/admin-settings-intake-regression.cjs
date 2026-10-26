const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
function setup(role = 'admin', manage = true) {
  const e = environment();
  e.API.setUserRole(role); e.c.window.API = e.API;
  e.c.window.location.search = ''; e.c.document.visibilityState = 'visible';
  e.c.window.removeEventListener = () => {}; e.c.document.removeEventListener = () => {};
  let stopped = {clinic: false, grooming: false}, pendingMutation, pendingRead, fail = false;
  const payload = () => ({availability: {clinic: {open_time: '08:00', close_time: '17:00'}, grooming: {open_time: '09:00', close_time: '18:00'}},
    stopped_today: {...stopped}, capacity: {current: 3, used: 3, max: 15, remaining: 12, percent: 20, is_full: false}, pet_groomers: [], groomers_on_duty: 0, blocked_dates: []});
  e.respond(async (endpoint, options) => {
    if (endpoint === '/me') return {sidebar_revision: 'availability-1', user: {user_id: 1, role, staff_permissions: {availability_view: true, availability_manage: manage}}};
    if (endpoint === '/admin/clinic/settings/availability') { if (pendingRead) await pendingRead.promise; if (fail) throw Error('Unavailable'); return payload(); }
    if (/\/(stop|reopen)-today$/.test(endpoint)) {
      if (pendingMutation) await pendingMutation.promise;
      const {service} = JSON.parse(options.body); assert.ok(['clinic', 'grooming'].includes(service));
      stopped[service] = endpoint.endsWith('/stop-today'); return {success: true, stopped_today: {...stopped}};
    }
    return payload();
  });
  vm.runInContext(fs.readFileSync('scripts/components/admin-availability.js', 'utf8'), e.c);
  const ui = e.c.adminAvailability(); ui.$nextTick = fn => fn();
  return {...e, ui, holdMutation: x => pendingMutation = x, holdRead: x => pendingRead = x,
    fail: x => fail = x, mutations: () => e.calls.filter(c => /\/(stop|reopen)-today$/.test(c.endpoint))};
}
async function dedicatedDataAndConfirmation() {
  const e = setup(); await e.ui.init();
  assert.equal(e.ui.capacity.max, 15); assert.equal(e.ui.capacity.remaining, 12);
  assert.equal(e.calls.filter(x => x.endpoint === '/admin/clinic/settings/availability').length, 1);
  assert.equal(e.calls.some(x => ['/clinic/status', '/admin/clinic/blocked-dates'].includes(x.endpoint)), false, 'One operational response supplies the workspace');
  await e.ui.openIntakeConfirmation('stop'); assert.equal(e.ui.intakeModal.open, true);
  assert.equal(e.mutations().length, 0, 'Explicit confirmation is required');
  e.ui.intakeModal.service = 'clinic';
  const wait = deferred(); e.holdMutation(wait); const action = e.ui.executeIntakeAction();
  e.ui.closeIntakeConfirmation(); assert.equal(e.ui.intakeModal.open, true);
  const duplicate = e.ui.executeIntakeAction(); wait.resolve(); await Promise.all([action, duplicate]);
  assert.equal(e.mutations().length, 1); assert.equal(e.ui.intakeStopped.clinic, true); assert.equal(e.ui.intakeStopped.grooming, false);
  await e.ui.openIntakeConfirmation('reopen'); assert.equal(e.ui.intakeModal.service, 'clinic');
  await e.ui.executeIntakeAction(); assert.equal(e.ui.intakeStopped.clinic, false);
}
async function veterinarianAndIdentityInvalidation() {
  const e = setup('staff', false); await e.ui.init(); assert.equal(e.ui.canManage, false); assert.equal(e.ui.availabilityLoaded, true);
  await e.ui.openIntakeConfirmation('stop'); await e.ui.executeIntakeAction(); assert.equal(e.mutations().length, 0);
  e.ui.capacityMaximum = 12; await e.ui.saveCapacity();
  assert.equal(e.calls.some(x => x.endpoint.endsWith('/capacity')), false);
  const admin = setup(); await admin.ui.init(); admin.ui.invalidateIdentity(); assert.equal(admin.ui.canManage, false);
  assert.equal(admin.ui.availabilityLoaded, false);
}
async function refreshDeduplicationAndErrors() {
  const e = setup(); await e.ui.init(); const wait = deferred(); e.holdRead(wait);
  const first = e.ui.loadAvailability(); const second = e.ui.loadAvailability(); wait.resolve(); await Promise.all([first, second]);
  assert.equal(e.calls.filter(x => x.endpoint === '/admin/clinic/settings/availability').length, 2);
  e.fail(true); await e.ui.loadAvailability(); assert.ok(e.ui.availabilityError); assert.equal(e.ui.capacity.max, 15);
  e.fail(false); await e.ui.loadAvailability(); assert.equal(e.ui.availabilityError, '');
}
async function settingsNeverLoadsOperationalData() {
  const e = environment(); e.API.setUserRole('admin'); e.c.window.API = e.API; e.c.window.location.search = '';
  e.respond(endpoint => endpoint === '/me' ? {user: {user_id: 1, role: 'admin', staff_permissions: {}}, sidebar_revision: '1'} : {});
  vm.runInContext(fs.readFileSync('scripts/components/admin-settings.js', 'utf8'), e.c);
  const ui = e.c.adminSettings(); await ui.init();
  assert.equal(e.calls.some(x => /availability|clinic\/status|blocked-dates/.test(x.endpoint)), false);
}
async function staleRefreshCannotOverwriteSavedCapacity() {
  const e = setup(); await e.ui.init();
  const wait = deferred(); e.API.getAvailabilitySettings = () => wait.promise;
  const refresh = e.ui.loadAvailability();
  e.respond(endpoint => endpoint.endsWith('/capacity') ? {capacity: {current: 3, used: 3, max: 12, remaining: 9, percent: 25, is_full: false}} : {});
  e.ui.capacityMaximum = 12; await e.ui.saveCapacity();
  wait.resolve({availability: {}, capacity: {used: 3, max: 15}, stopped_today: {clinic: false, grooming: false}}); await refresh;
  assert.equal(e.ui.capacity.max, 12); assert.equal(e.ui.availabilityLoading, false);
}
async function confirmationRestoresAVisibleAction() {
  const e = setup(); await e.ui.init(); let focused = '';
  e.ui._intakeOpener = {offsetParent: null, focus() {focused = 'hidden';}};
  e.ui.$refs = {reopenIntake: {focus() {focused = 'reopen';}}};
  e.ui.intakeStopped = {clinic: true, grooming: true}; e.ui.intakeModal.open = true;
  e.ui.closeIntakeConfirmation(); assert.equal(focused, 'reopen');
}
async function confirmationErrorAndRetry() {
  const e = setup(); await e.ui.init(); await e.ui.openIntakeConfirmation('stop');
  const stop = e.API.adminStopToday;
  e.API.adminStopToday = async () => {throw Error('Could not change intake');};
  await e.ui.executeIntakeAction();
  assert.equal(e.ui.intakeModal.open, true); assert.equal(e.ui.intakeModal.busy, false); assert.ok(e.ui.intakeModal.error);
  assert.equal(e.ui.intakeStopped.clinic, false);
  e.API.adminStopToday = stop; await e.ui.executeIntakeAction(); assert.equal(e.ui.intakeStopped.clinic, true);
}
async function legacySettingsLinksForwardWithoutOperationalReads() {
  for (const legacy of ['availability', 'capacity']) {
    const e = environment(); let destination = '';
    e.c.window.location = {href: 'http://127.0.0.1:8000/pages/admin/settings.html', search: '?tab=' + legacy, hash: '', replace: url => destination = String(url)};
    vm.runInContext(fs.readFileSync('scripts/components/admin-settings.js', 'utf8'), e.c);
    await e.c.adminSettings().init();
    assert.equal(destination, 'http://127.0.0.1:8000/pages/admin/availability.html' + (legacy === 'capacity' ? '?service=grooming' : ''));
    assert.equal(e.calls.length, 0);
  }
}
function settingsSetup(role = 'admin') {
  const e = environment();
  e.API.setUserRole(role);
  e.c.window.API = e.API;
  e.c.window.location.search = '';
  e.c.document.visibilityState = 'visible';
  e.c.window.removeEventListener = () => {};
  e.c.document.removeEventListener = () => {};
  const timers = new Map(), timeouts = new Map();
  let nextTimer = 0;
  e.c.window.setInterval = callback => { timers.set(++nextTimer, callback); return nextTimer; };
  e.c.window.clearInterval = id => timers.delete(id);
  e.c.window.setTimeout = (callback, delay = 0) => {
    timeouts.set(++nextTimer, { callback, dueAt: e.c.Date.now() + delay });
    return nextTimer;
  };
  e.c.window.clearTimeout = id => timeouts.delete(id);
  e.respond(async endpoint => endpoint === '/me' ? {sidebar_revision: 'settings', user: {user_id: 1, role, staff_permissions: {}}} : {});
  vm.runInContext(fs.readFileSync('scripts/components/admin-settings.js', 'utf8'), e.c);
  const ui = e.c.adminSettings();
  ui.$nextTick = fn => fn();
  return { ...e, ui, timers, timeouts, tick: milliseconds => {
    e.advance(milliseconds);
    for (const callback of timers.values()) callback();
    for (const [id, timeout] of [...timeouts]) {
      if (timeouts.has(id) && timeout.dueAt <= e.c.Date.now()) {
        timeouts.delete(id);
        timeout.callback();
      }
    }
  },
  };
}
async function timerCleanupPreservesIntakeRequests() {
  const e = settingsSetup();
  await e.ui.init();
  const requests = e.calls.length;
  e.ui.startSecurityResendCountdown(2);
  assert.equal(e.ui.securityVerification.resendRemaining, 2);
  assert.equal(e.timers.size, 1);
  e.tick(1000);
  assert.equal(e.ui.securityVerification.resendRemaining, 1);
  e.tick(1000);
  assert.equal(e.ui.securityVerification.resendRemaining, 0);
  assert.equal(e.timers.size, 0, 'Expired countdown clears its interval');
  const recovery = { id: 'recovery-timer', status: 'pending', expires_at: new Date(e.c.Date.now() + 1000).toISOString() };
  e.ui.staffDetailsModal.staff = { id: 7, emailRecovery: recovery };
  e.ui.armStaffRecoveryExpiry();
  assert.equal(e.timeouts.size, 1, 'Recovery expiry registers a timeout');
  e.tick(999);
  assert.equal(recovery.status, 'pending', 'Expiry must not run before its deadline');
  e.tick(1);
  assert.equal(recovery.status, 'expired');
  assert.equal(e.timeouts.size, 0, 'Expiry is a one-shot callback');

  recovery.status = 'pending';
  recovery.expires_at = new Date(e.c.Date.now() + 1000).toISOString();
  e.ui.armStaffRecoveryExpiry();
  const previousExpiry = e.ui._staffRecoveryExpiryTimer;
  recovery.expires_at = new Date(e.c.Date.now() + 3000).toISOString();
  e.ui.armStaffRecoveryExpiry();
  assert.equal(e.timeouts.has(previousExpiry), false, 'Rearming cancels the previous expiry callback');
  assert.equal(e.timeouts.size, 1);
  e.tick(1000);
  assert.equal(recovery.status, 'pending', 'The canceled expiry cannot expire a rearmed recovery');
  e.tick(2000);
  assert.equal(recovery.status, 'expired');
  assert.equal(e.timeouts.size, 0);

  e.ui.startSecurityResendCountdown(5);
  const previousInterval = e.ui._securityResendTimer;
  e.ui.startSecurityResendCountdown(10);
  assert.equal(e.timers.has(previousInterval), false);
  assert.equal(e.timers.size, 1, 'Restarting countdown replaces the previous interval');
  e.tick(1000);
  assert.equal(e.ui.securityVerification.resendRemaining, 9);
  e.ui.startSecurityResendCountdown(8, e.ui.staffEmailChange, '_staffEmailResendTimer');
  e.ui.startSecurityResendCountdown(9, e.ui.staffRecovery, '_staffRecoveryResendTimer');
  recovery.status = 'pending';
  recovery.expires_at = new Date(e.c.Date.now() + 1000).toISOString();
  e.ui.armStaffRecoveryExpiry();
  assert.equal(e.timers.size, 3);
  assert.equal(e.timeouts.size, 1);
  assert.notEqual(e.ui._staffRecoveryExpiryTimer, e.ui._securityResendTimer, 'Intervals and timeouts have distinct timer IDs');
  e.ui.destroy();
  assert.equal(e.timers.size, 0, 'Destroy clears every active resend interval');
  assert.equal(e.timeouts.size, 0, 'Destroy cancels recovery expiry');
  assert.equal(e.ui._securityResendTimer, null);
  e.tick(20000);
  assert.equal(recovery.status, 'pending', 'A destroyed component cannot run its expiry callback');
  assert.equal(e.ui.securityVerification.resendRemaining, 9);
  assert.equal(e.ui.staffEmailChange.resendRemaining, 8);
  assert.equal(e.ui.staffRecovery.resendRemaining, 9);
  assert.equal(e.timers.size + e.timeouts.size, 0, 'Canceled callbacks cannot register more work');
  assert.equal(e.calls.length, requests, 'Timer lifecycle performs no intake or other requests');
}
async function sidebarRefreshNeverReadsIntake() {
  for (const role of ['admin', 'staff']) {
    for (const page of ['dashboard', 'clients', 'appointments', 'clinic', 'services', 'inventory/inventory', 'transactions', 'archive', 'reports', 'notifications', 'settings']) {
      const e = environment(), timers = [], listeners = new Map();
      e.API.setUserRole(role); e.c.window.API = e.API;
      e.c.window.location.pathname = `/pages/admin/${page}.html`;
      e.c.window.addEventListener = (name, fn) => listeners.set(name, fn);
      e.c.window.removeEventListener = name => listeners.delete(name);
      e.c.document.querySelectorAll = () => [];
      e.c.document.visibilityState = 'visible';
      e.c.document.removeEventListener = () => {};
      e.c.setInterval = fn => { timers.push(fn); return timers.length; };
      e.c.clearInterval = () => {};
      e.respond(endpoint => endpoint === '/me' ? { sidebar_revision: '1', user: {
        user_id: 1, role, staff_type: 'grooming', staff_subrole: 'grooming_receptionist', clinic_permissions: {}, staff_permissions: {grooming: true},
      } } : { success: true, activeTodayCount: 1 });
      vm.runInContext(fs.readFileSync('scripts/components/admin-sidebar.js', 'utf8'), e.c);
      const sidebar = e.c.adminSidebar(); sidebar.$nextTick = fn => fn();
      const allowed = e.API.enforceAdminPageAccess();
      await sidebar.init(); assert.equal(sidebar.sidebarReady, allowed);
      for (const timer of timers) timer();
      listeners.get('admin-session:unlocked')?.();
      await new Promise(resolve => setImmediate(resolve));
      assert.equal(e.calls.filter(c => c.endpoint === '/clinic/status').length, 0, `${role} ${page}: no intake reads on init, polling, or unlock`);
      if (allowed && !['dashboard', 'appointments', 'notifications'].includes(page)) assert.equal(sidebar.hasIncomingAppointments, true, `${role} ${page}: legitimate Grooming activity still loads`);
      sidebar.destroy();
    }
  }
}
(async () => { for (const test of [dedicatedDataAndConfirmation, veterinarianAndIdentityInvalidation, refreshDeduplicationAndErrors, settingsNeverLoadsOperationalData, staleRefreshCannotOverwriteSavedCapacity, confirmationErrorAndRetry, confirmationRestoresAVisibleAction, legacySettingsLinksForwardWithoutOperationalReads, timerCleanupPreservesIntakeRequests, sidebarRefreshNeverReadsIntake]) {
  await test(); console.log(test.name + ' passed');
} })().catch(error => { console.error(error); process.exitCode = 1; });
