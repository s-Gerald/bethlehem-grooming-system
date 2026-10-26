const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8');
const context = { window: {}, console, Date, Intl };
vm.runInNewContext(source, context);
const ui = context.adminDashboard();
ui.refreshIcons = () => {};
ui.dispatchDashboardEvent = () => {};
ui.applyDashboardData({
  summary: { today: 7, waitingNow: 3, revenueToday: 1200, revenuePaymentCount: 2 },
  capacity: { current: 6, max: 20, remaining: 14, percent: 30 },
});
assert.equal(ui.currentCapacity, 6);
assert.equal(ui.availableCapacity, 14);
assert.equal(ui.capacityPercent, 30);
assert.equal(ui.waitingNow, 3, 'Waiting pets remain separate from active capacity.');
assert.equal(ui.todayCount, 7);
const restored = ui.normalizeDashboardPayload(ui.getState());
assert.equal(restored.currentCapacity, 6);
assert.equal('clinicCapacity' in restored, false);
assert.equal('groomingCapacity' in restored, false);
assert.equal(restored.waitingNow, 3);
ui.applyDashboardData({ capacity: { current: 20, max: 20, remaining: 0, percent: 100 } });
assert.equal(ui.availableCapacity, 0);
assert.equal(ui.capacityPercent, 100);
assert.equal(ui.waitingNow, 3);

ui.applyDashboardData({capacity: {current: 3, max: 7, remaining: 4, percent: 43}});
assert.equal(ui.capacityPercent, 43, 'Use the server utilization value consistently');
assert.equal(ui.availableCapacity, 4);
ui.localToday = () => '2026-10-09';
ui.queuedList = [
  ui.normalizeBooking({ id: 1, queueNumber: 2, appointmentDate: '2026-10-09', appointmentTime: '8:00 AM - 9:00 AM', dropOffTime: '10:00 AM' }, 'queued'),
  ui.normalizeBooking({ id: 2, queueNumber: 1, appointmentDate: '2026-10-09', appointmentTime: '10:00 AM - 11:00 AM', dropOffTime: '9:30 AM' }, 'queued'),
];
assert.equal(ui.todayQueuePreview()[0].id, 2, 'Preferred arrival time must not reorder checked-in pets.');
assert.equal(ui.queuePreviewDetail(ui.queuedList[0]), 'Grooming · Checked in 10:00 AM');
assert.equal(ui.queuePreviewDetail({ serviceType: 'clinic', dropOffTime: '9:40 AM' }), 'Clinic · Checked in 9:40 AM');
assert.equal(ui.queuePreviewDetail({ appointmentTime: '8:00 AM - 9:00 AM' }), 'Grooming · Checked in');
assert.equal(ui.queuedList[0].appointmentTime, '8:00 AM - 9:00 AM', 'Preferred times remain available for pre-arrival/detail views.');

const page = fs.readFileSync(path.join(__dirname, '../pages/admin/dashboard.html'), 'utf8');
const cards = page.slice(page.indexOf('<!-- Summary cards -->'), page.indexOf('<!-- Queue and activity preview -->')).match(/<article[\s\S]*?<\/article>/g);
assert.equal(cards.length, 4);
['Grooming capacity', 'Waiting now', 'Completed today', 'Revenue today'].forEach((title, index) => assert.ok(cards[index].includes(title)));
ui.clinicOnly = true;
assert.equal(ui.queuePreviewStatusLabel('in-progress'), 'In Consultation');
assert.equal(ui.queuePreviewStatusLabel('queued'), 'Waiting');
assert.ok(cards[0].includes("clinicOnly ? 'Waiting Now'"));
assert.ok(cards[1].includes("clinicOnly ? 'In Consultation'"));
assert.doesNotMatch(cards[2], /[Cc]apacity/);
assert.doesNotMatch(cards.join(''), /This week|[Ss]hared [Cc]apacity/);
assert.match(cards[0], /role="progressbar"/);
assert.doesNotMatch(cards[0], /clinicCapacity|groomingCapacity|Clinic|by_service/);
const availability = fs.readFileSync(path.join(__dirname, '../resources/views/admin/availability.html'), 'utf8');
assert.match(availability, />Grooming Cage Capacity<\/h4>/);
assert.match(availability, /capacity\.used/);
assert.match(availability, /capacity\.max/);
assert.doesNotMatch(availability, /Advance Pre-register Window|Clinic and Grooming share/);
console.log('Dashboard capacity, waiting metrics, KPI hierarchy, and check-in preview regression checks passed.');
