const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { environment, deferred } = require('./admin-data-cache-regression.cjs');
function setup(role = 'admin') {
  const e = environment();
  e.API.setUserRole(role);
  e.c.window.API = e.API;
  e.c.window.location.search = '';
  e.c.document.visibilityState = 'visible';
  e.c.window.removeEventListener = () => {};
  e.c.document.removeEventListener = () => {};
  const timers = new Map();
  let nextTimer = 0;
  e.c.window.setInterval = callback => { timers.set(++nextTimer, callback); return nextTimer; };
  e.c.window.clearInterval = id => timers.delete(id);
  let stopped = false, statusError = false, mutationError = false, pendingStatus, pendingMutation;
  e.respond(async (endpoint, options) => {
    if (endpoint === '/clinic/status') {
      if (pendingStatus) await pendingStatus.promise;
      if (statusError) throw Error('Status unavailable');
      return { success: true, stopped_today: stopped, blocked_dates: [], availability: {} };
    }
    if (/\/(stop|reopen)-today$/.test(endpoint)) {
      if (pendingMutation) await pendingMutation.promise;
      if (mutationError) throw Error('Could not change intake');
      assert.equal(options.method, 'POST');
      stopped = endpoint.endsWith('/stop-today');
      return { success: true };
    }
    return { availability: {}, blocked_dates: [] };
  });
  vm.runInContext(fs.readFileSync('scripts/components/admin-settings.js', 'utf8'), e.c);
  const ui = e.c.adminSettings();
  ui.$nextTick = fn => fn();
  return { ...e, ui, timers, tick: milliseconds => { e.advance(milliseconds); for (const callback of timers.values()) callback(); },
    statusCalls: () => e.calls.filter(c => c.endpoint === '/clinic/status').length,
    mutations: () => e.calls.filter(c => /\/(stop|reopen)-today$/.test(c.endpoint)),
    setStopped: value => { stopped = value; }, failStatus: value => { statusError = value; },
    failMutation: value => { mutationError = value; }, holdStatus: value => { pendingStatus = value; },
    holdMutation: value => { pendingMutation = value; } };
}
async function obsoleteNotificationSettingsAreAbsent() {
  for (const role of ['admin', 'staff']) {
    const e = setup(role);
    e.c.window.location.search = '?tab=notifications';
    await e.ui.init();
    assert.equal(e.ui.activeSettingsTab, 'general', 'Removed tab links fall back to General');
    for (const key of ['appointmentReminders', 'paymentReceipt']) {
      assert.equal(key in e.ui, false, `${role}: obsolete preference state is absent`);
    }
    const requests = e.calls.length;
    for (const tab of ['capacity', 'general']) {
      await e.ui.selectSettingsTab(tab);
      assert.equal(e.ui.activeSettingsTab, tab);
    }
    assert.equal(e.calls.length, requests, 'General and Capacity navigation adds no API requests');
    assert.equal(e.statusCalls(), 0, 'Removed tab links never load intake status');
    e.ui.destroy();
  }
}
async function lazyStatusAndFreshEntry() {
  const e = setup(); await e.ui.init();
  for (const tab of ['general', 'capacity']) await e.ui.selectSettingsTab(tab);
  assert.equal(e.statusCalls(), 0, 'Other Settings tabs never request intake status');
  const wait = deferred(); e.holdStatus(wait);
  const first = e.ui.selectSettingsTab('availability');
  const second = e.ui.selectSettingsTab('availability');
  assert.equal(e.ui.intakeLoading, true);
  wait.resolve(); await Promise.all([first, second]);
  assert.equal(e.statusCalls(), 1, 'Concurrent Availability entry shares one read');
  assert.equal(e.calls.filter(c => c.endpoint === '/admin/clinic/blocked-dates').length, 1, 'Repeated clicks do not duplicate Block Dates reads');
  assert.equal(e.ui.intakeLoaded, true); assert.equal(e.ui.intakeStopped, false);
  await e.ui.selectSettingsTab('availability'); assert.equal(e.statusCalls(), 1);
  await e.ui.selectSettingsTab('capacity'); e.setStopped(true);
  await e.ui.selectSettingsTab('availability');
  assert.equal(e.ui.intakeStopped, true, 'Reentry bypasses cached operational status');
  await e.ui.selectSettingsTab('general');
  const count = e.statusCalls(); await e.ui.refreshIntakeStatus();
  assert.equal(e.statusCalls(), count, 'Resume does not load intake on unrelated tabs');
}
async function confirmationAndActions() {
  const e = setup(); await e.ui.selectSettingsTab('availability');
  await e.ui.openIntakeConfirmation();
  assert.equal(e.ui.intakeModal.open, true); assert.equal(e.ui.intakeModal.action, 'stop');
  assert.equal(e.mutations().length, 0, 'Opening confirmation never mutates intake');
  e.ui.closeIntakeConfirmation(); assert.equal(e.ui.intakeModal.open, false);
  await e.ui.executeIntakeAction(); assert.equal(e.mutations().length, 0, 'Closed dialog cannot submit');
  await e.ui.openIntakeConfirmation();
  const wait = deferred(); e.holdMutation(wait);
  const action = e.ui.executeIntakeAction();
  assert.equal(e.ui.intakeModal.busy, true);
  e.ui.closeIntakeConfirmation(); assert.equal(e.ui.intakeModal.open, true, 'Cannot dismiss a pending mutation');
  const duplicate = e.ui.executeIntakeAction();
  wait.resolve(); await Promise.all([action, duplicate]);
  assert.equal(e.mutations().length, 1); assert.equal(e.ui.intakeStopped, true);
  assert.equal(e.ui.intakeModal.open, false); assert.ok(e.ui.intakeSuccess);
  assert.equal(e.ui.intakeModal.busy, false);
  await e.ui.openIntakeConfirmation(); assert.equal(e.ui.intakeModal.action, 'reopen');
  await e.ui.executeIntakeAction(); assert.equal(e.ui.intakeStopped, false);
  assert.deepEqual(e.mutations().map(c => c.endpoint), ['/admin/clinic/stop-today', '/admin/clinic/reopen-today']);
  assert.equal((await e.API.getClinicStatus()).stopped_today, false, 'Mutations invalidate the shared status cache');
  e.setStopped(true); await e.ui.openIntakeConfirmation();
  assert.equal(e.ui.intakeModal.action, 'reopen', 'Confirmation refreshes status before choosing the action');
}
async function staffIsReadOnly() {
  const e = setup('staff'); await e.ui.selectSettingsTab('availability');
  assert.equal(e.ui.intakeLoaded, true);
  await e.ui.openIntakeConfirmation(); assert.equal(e.ui.intakeModal.open, false);
  e.ui.intakeModal = { open: true, action: 'stop', busy: false };
  await e.ui.executeIntakeAction(); assert.equal(e.mutations().length, 0);
}
async function errorsAndRetry() {
  const e = setup(); e.failStatus(true); await e.ui.selectSettingsTab('availability');
  assert.equal(e.ui.intakeLoaded, false); assert.ok(e.ui.intakeError); assert.equal(e.ui.intakeLoading, false);
  await e.ui.openIntakeConfirmation(); assert.equal(e.ui.intakeModal.open, false);
  e.failStatus(false); await e.ui.loadIntakeStatus();
  assert.equal(e.ui.intakeLoaded, true); assert.equal(e.ui.intakeError, '');
  await e.ui.openIntakeConfirmation(); e.failMutation(true); await e.ui.executeIntakeAction();
  assert.equal(e.ui.intakeModal.open, true); assert.ok(e.ui.intakeModal.error);
  assert.equal(e.ui.intakeModal.busy, false); assert.equal(e.ui.intakeStopped, false);
  e.failMutation(false); await e.ui.executeIntakeAction(); assert.equal(e.ui.intakeStopped, true);
  e.failStatus(true); await e.ui.loadIntakeStatus();
  assert.equal(e.ui.intakeStopped, true, 'Failed refresh preserves the last confirmed status');
  assert.ok(e.ui.intakeError);
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
        user_id: 1, role, staff_type: 'grooming', staff_subrole: 'grooming_receptionist', clinic_permissions: {},
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
async function timerCleanupPreservesIntakeRequests() {
  const e = setup();
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
  e.ui.startSecurityResendCountdown(5);
  e.ui.startSecurityResendCountdown(10);
  assert.equal(e.timers.size, 1, 'Restarting countdown replaces the previous interval');
  e.ui.destroy();
  assert.equal(e.timers.size, 0, 'Destroy clears the remaining interval');
  assert.equal(e.ui._securityResendTimer, null);
  assert.equal(e.calls.length, requests, 'Timer lifecycle performs no intake or other requests');
}
(async () => { for (const test of [obsoleteNotificationSettingsAreAbsent, lazyStatusAndFreshEntry, confirmationAndActions, staffIsReadOnly, errorsAndRetry, sidebarRefreshNeverReadsIntake, timerCleanupPreservesIntakeRequests]) {
  await test(); console.log(test.name + ' passed');
} })().catch(error => { console.error(error); process.exitCode = 1; });
