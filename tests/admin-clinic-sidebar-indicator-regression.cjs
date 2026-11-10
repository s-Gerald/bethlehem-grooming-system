const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const listeners = new Map(), documentListeners = new Map(), timers = new Map();
let clinicCalls = 0, groomingCalls = 0, clinicCount = 0, locked = false, pendingClinic;
let timerId = 0;
const user = { role: 'admin', first_name: 'Test' };
const API = {
  getMe: async () => ({ user }), getAdminToken: () => 'token',
  getAdminBookings: async () => ({}),
  getAdminGroomingActivity: async () => { groomingCalls++; return { activeTodayCount: 1 }; },
  getAdminClinicActivity: async () => { clinicCalls++; return pendingClinic || { activeClinicCount: clinicCount }; },
  isSessionLocked: () => locked,
};
const document = { visibilityState: 'visible', addEventListener: (n, f) => documentListeners.set(n, f), removeEventListener: n => documentListeners.delete(n) };
const window = { API, location: { origin: 'http://localhost', pathname: '/admin/customers' }, addEventListener: (n, f) => listeners.set(n, f), removeEventListener: n => listeners.delete(n) };
const context = vm.createContext({ API, document, window, URL, URLSearchParams, Intl, Date,
  setInterval(f, ms) { assert.equal(ms, 30000); timers.set(++timerId, f); return timerId; }, clearInterval: id => timers.delete(id),
});
vm.runInContext(fs.readFileSync('scripts/components/admin-sidebar.js', 'utf8'), context);
const tick = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
(async () => {
  delete document.querySelector;
  const sidebar = context.adminSidebar();
  await sidebar.init();
  assert.equal(sidebar.hasClinicActivity, false);
  assert.equal(sidebar.hasIncomingAppointments, true);
  assert.equal(clinicCalls, 1);
  assert.equal(timers.size, 1);
  clinicCount = 2; timers.get(1)(); await tick();
  assert.equal(sidebar.hasClinicActivity, true);
  assert.equal(sidebar.hasIncomingAppointments, true);
  const calls = clinicCalls;
  document.visibilityState = 'hidden'; timers.get(1)(); await tick();
  assert.equal(clinicCalls, calls);
  document.visibilityState = 'visible'; locked = true; timers.get(1)(); await tick();
  assert.equal(clinicCalls, calls);
  locked = false; listeners.get('admin-session:unlocked')(); await tick();
  assert.ok(clinicCalls > calls);
  let resolve;
  pendingClinic = new Promise(r => { resolve = r; });
  const request = sidebar.loadClinicActivity();
  listeners.get('admin-clinic:activity-applied')({ detail: { activeClinicCount: 0, requestedAt: Date.now() } });
  resolve({ activeClinicCount: 7 }); await request;
  assert.equal(sidebar.hasClinicActivity, false, 'A late poll cannot replace newer Clinic data.');
  pendingClinic = null;
  const olderBoardRequest = Date.now() - 1000;
  await sidebar.loadClinicActivity();
  assert.equal(sidebar.clinicActivityCount, 2);
  listeners.get('admin-clinic:activity-applied')({ detail: { activeClinicCount: 9, requestedAt: olderBoardRequest } });
  assert.equal(sidebar.clinicActivityCount, 2, 'An older Clinic board request cannot replace a newer poll.');
  document.querySelector = () => null;
  listeners.get('admin-navigation:invalidated')({});
  assert.equal(sidebar.clinicActivityCount, 0);
  const invalidatedCalls = clinicCalls; timers.get(1)(); await tick();
  assert.equal(clinicCalls, invalidatedCalls);
  sidebar.destroy(); assert.equal(timers.size, 0);
  assert.equal(listeners.has('admin-clinic:activity-applied'), false);
  user.role = 'staff'; user.staff_permissions = { grooming: true }; user.clinic_permissions = { access: false };
  const groomingOnly = context.adminSidebar();
  const before = clinicCalls; await groomingOnly.init();
  timers.values().next().value(); await tick();
  assert.equal(clinicCalls, before, 'Grooming-only staff never request Clinic activity.');
  assert.ok(groomingCalls > 0); groomingOnly.destroy();
  console.log('Clinic sidebar lifecycle regressions passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
