const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

let today = '2026-10-05';
let bookings = {};
const requests = [];
const listeners = new Map();
const context = {
  Date, Intl, URLSearchParams, console,
  document: {},
  API: {
    loadAdminData: (key, load, render) => load().then(render),
    getAdminToken: () => 'test-token',
    getAdminBookings: async (date, options) => {
      requests.push({ date, options });
      return bookings;
    },
  },
  window: {
    location: { pathname: '/pages/admin/dashboard.html' },
    AppClock: { todayKey: () => today },
    addEventListener: (name, handler) => listeners.set(name, handler),
    removeEventListener: (name) => listeners.delete(name),
    dispatchEvent: (event) => listeners.get(event.type)?.(event),
  },
  CustomEvent: class {
    constructor(type, detail) { this.type = type; Object.assign(this, detail); }
  },
};
context.window.API = context.API;
vm.createContext(context);
for (const file of ['admin-sidebar.js', 'admin-dashboard.js']) {
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8'), context);
}

const sidebar = context.adminSidebar();
sidebar.registerIncomingAppointmentListener();
const dashboard = context.adminDashboard();
dashboard.$nextTick = (callback) => callback();
const activeLists = ['incomingList', 'queuedList', 'inProgressList', 'forPaymentList', 'releasedList'];
const emptyLists = () => Object.fromEntries([...activeLists, 'forPickupList'].map((key) => [key, []]));
const record = (date, status) => ({ id: 1, appointmentDate: date, status, pets: [] });

async function run() {
  // The backend's active lists can contain other days, even for a date-filtered request.
  for (const [index, list] of activeLists.entries()) {
    const status = ['incoming', 'queued', 'in-progress', 'for-payment', 'released'][index];
    for (const date of ['2026-10-04', today, '2026-10-06']) {
      bookings = { ...emptyLists(), [list]: [record(date, status)] };
      await sidebar.loadIncomingAppointmentCount();
      assert.equal(sidebar.hasIncomingAppointments, date === today, `${list} must only trigger for today`);
    }
  }
  assert.ok(requests.every(({ date, options }) => date === today && !options?.includeFuture));

  bookings = { ...emptyLists(), incomingList: [record(today, 'incoming'), record('2026-10-06', 'incoming')] };
  await sidebar.loadIncomingAppointmentCount();
  assert.equal(sidebar.incomingAppointmentCount, 1);

  // Navigating to Grooming must retain the dot through every active workflow stage.
  context.window.location.pathname = '/pages/admin/appointments.html';
  sidebar.detectActivePage();
  assert.equal(sidebar.hasIncomingAppointments, true);
  for (const [index, list] of activeLists.entries()) {
    dashboard.selectedDate = today;
    dashboard.applyDashboardData({
      ...emptyLists(),
      [list]: [record(today, ['incoming', 'queued', 'in-progress', 'for-payment', 'released'][index])],
    });
    assert.equal(sidebar.hasIncomingAppointments, true, `Visiting ${list} must keep the dot`);
  }

  // Future Incoming cards on today's Grooming view must be filtered out.
  dashboard.applyDashboardData({ ...emptyLists(), incomingList: [record('2026-10-06', 'incoming')] });
  assert.equal(sidebar.hasIncomingAppointments, false);

  // Tomorrow's date picker results cannot turn the dot on or clear today's dot.
  dashboard.selectedDate = '2026-10-06';
  dashboard.applyDashboardData({ ...emptyLists(), incomingList: [record('2026-10-06', 'incoming')] });
  assert.equal(sidebar.hasIncomingAppointments, false);
  bookings = { ...emptyLists(), queuedList: [record(today, 'queued')] };
  await sidebar.loadIncomingAppointmentCount();
  dashboard.applyDashboardData(emptyLists());
  assert.equal(sidebar.hasIncomingAppointments, true);
  await sidebar.loadIncomingAppointmentCount();
  assert.equal(sidebar.hasIncomingAppointments, true, 'Polling still uses today while viewing tomorrow');

  // Clear only after the last active customer leaves the workflow.
  dashboard.selectedDate = today;
  dashboard.applyDashboardData({ ...emptyLists(), releasedList: [record(today, 'released'), { ...record(today, 'released'), id: 2 }] });
  assert.equal(sidebar.incomingAppointmentCount, 2);
  dashboard.applyDashboardData({ ...emptyLists(), releasedList: [record(today, 'released')] });
  assert.equal(sidebar.hasIncomingAppointments, true);
  dashboard.applyDashboardData({
    ...emptyLists(), noShowList: [record(today, 'no_show')], archivedList: [record(today, 'archived')],
  });
  assert.equal(sidebar.hasIncomingAppointments, false);
  bookings = { ...emptyLists(), noShowList: [record(today, 'no_show')], completedList: [record(today, 'completed')], archivedList: [record(today, 'archived')] };
  await sidebar.loadIncomingAppointmentCount();
  assert.equal(sidebar.hasIncomingAppointments, false);

  // The existing AppClock defines today, including rollover/test-clock changes.
  bookings = { ...emptyLists(), incomingList: [record('2026-10-06', 'incoming')] };
  await sidebar.loadIncomingAppointmentCount();
  assert.equal(sidebar.hasIncomingAppointments, false);
  today = '2026-10-06';
  await sidebar.loadIncomingAppointmentCount();
  assert.equal(requests.at(-1).date, today);
  assert.equal(sidebar.hasIncomingAppointments, true);
  today = '2026-10-07';
  await sidebar.loadIncomingAppointmentCount();
  assert.equal(sidebar.hasIncomingAppointments, false);

  // A late poll must not overwrite fresher Grooming state after the final pickup.
  const getAdminBookings = context.API.getAdminBookings;
  let resolvePending;
  context.API.getAdminBookings = () => new Promise((resolve) => { resolvePending = resolve; });
  const pending = sidebar.loadIncomingAppointmentCount();
  dashboard.selectedDate = today;
  dashboard.applyDashboardData(emptyLists());
  resolvePending({ ...emptyLists(), releasedList: [record(today, 'released')] });
  await pending;
  assert.equal(sidebar.hasIncomingAppointments, false, 'Older polls cannot restore a cleared dot');

  const rollover = sidebar.loadIncomingAppointmentCount();
  const previousDay = today;
  today = '2026-10-08';
  resolvePending({ ...emptyLists(), queuedList: [record(previousDay, 'queued')] });
  await rollover;
  assert.equal(sidebar.hasIncomingAppointments, false, 'Discard a response for the previous day after midnight');

  // Ignore tomorrow's late response if the picker has already returned to today.
  dashboard.selectedDate = '2026-10-09';
  const changedDate = dashboard.loadAdminBookings();
  dashboard.selectedDate = today;
  dashboard.applyDashboardData({ ...emptyLists(), incomingList: [record(today, 'incoming')] });
  resolvePending({ ...emptyLists(), incomingList: [record('2026-10-09', 'incoming')] });
  await changedDate;
  assert.equal(sidebar.hasIncomingAppointments, true, 'A stale date-picker response cannot clear today’s dot');
  assert.equal(dashboard.incomingList[0].appointmentDate, today);
  context.API.getAdminBookings = getAdminBookings;

  // A fresh sidebar on navigation reloads the same persistent record state.
  bookings = { ...emptyLists(), releasedList: [record(today, 'released')] };
  const freshSidebar = context.adminSidebar();
  await freshSidebar.loadIncomingAppointmentCount();
  assert.equal(freshSidebar.hasIncomingAppointments, true);

  for (const file of [
    'dashboard.html', 'clients.html', 'appointments.html', 'clinic.html', 'archive.html',
    'transactions.html', 'reports.html', 'notifications.html', 'settings.html',
    'inventory/inventory.html',
  ]) {
    const page = fs.readFileSync(path.join(__dirname, '../pages/admin', file), 'utf8');
    const groomingLink = page.match(/<a\b[^>]*href="[^"]*appointments\.html"[\s\S]*?<\/a>/)?.[0];
    assert.ok(groomingLink?.includes('x-show="hasIncomingAppointments"'), `${file} uses the persistent dot`);
    assert.equal((groomingLink.match(/x-show="hasIncomingAppointments"/g) || []).length, 1, `${file} shows exactly one persistent dot`);
    assert.ok(groomingLink?.includes('aria-label="Active grooming customers today"'));
    assert.doesNotMatch(groomingLink, /@click=.*(?:incomingAppointmentCount|hasIncomingAppointments)/);
    assert.match(page, /<script[^>]+src="[^"]*admin-sidebar\.js\?/);
  }

  sidebar.destroy();
  assert.equal(listeners.has('admin-dashboard:data-applied'), false);
  console.log('Admin grooming sidebar indicator regression tests passed.');
}

run().catch((error) => { console.error(error); process.exitCode = 1; });
