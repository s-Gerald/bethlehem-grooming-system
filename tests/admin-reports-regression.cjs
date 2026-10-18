const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function component(api) {
  const context = { API: api, Intl, Date, setTimeout, window: {} };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('scripts/components/admin-reports.js', 'utf8'), context);
  const page = context.adminReports();
  page.$nextTick = fn => fn();
  return page;
}
const report = () => ({ success: true, period: { start: '2026-10-01', end: '2026-10-31', grouping: 'day' },
  overview: { collections: 600, completedVisits: 2, averageSale: 600, paidBookings: 1, returningCustomers: 0 },
  performance: [{ date: '2026-10-01', end: '2026-10-01', collections: 600, visits: 2 }],
  services: [{ name: 'Recorded service', kind: 'package', count: 2 }], customers: { new: 1, returning: 0 },
  outcomes: { completed: 1, cancelled: 0, noShow: 0 } });

test('default and every preset fetch one shared report; chart mode does not fetch', async () => {
  const calls = [];
  const page = component({ getGroomingPerformanceReport: async args => { calls.push(args); return report(); } });
  await page.init();
  assert.equal(page.activeSection, 'grooming');
  assert.equal(calls[0].period, 'this_month');
  for (const period of ['today', 'last_7_days', 'last_month']) {
    page.period = period;
    await page.changePeriod();
    assert.equal(calls.at(-1).period, period);
    assert.equal(page.report.overview.collections, 600);
    assert.equal(page.report.services[0].count, 2);
  }
  assert.equal(calls.length, 4);
  page.metric = 'visits';
  assert.equal(page.chartPoints[0].value, 2);
  assert.equal(calls.length, 4);
});

test('Clinic loads lazily with exactly three KPIs and switching tabs restores each section without reloading', async () => {
  const calls = [];
  const clinic = report(); delete clinic.customers;
  clinic.overview = { collections: 1500, completedVisits: 3, averageSale: 750, paidCases: 2 };
  const page = component({
    getGroomingPerformanceReport: async args => { calls.push(['grooming', args]); return report(); },
    getClinicPerformanceReport: async args => { calls.push(['clinic', args]); return clinic; },
  });
  await page.init();
  page.period = 'last_month'; await page.changePeriod();
  page.metric = 'visits';
  await page.selectSection('clinic');
  assert.equal(calls.length, 3);
  assert.equal(calls[2][0], 'clinic');
  assert.equal(page.period, 'this_month');
  assert.equal(page.kpis.length, 3);
  assert.equal(page.kpis[0].title, 'Clinic Collections');
  assert.equal(page.kpis[1].value, '3');
  assert.equal(page.kpis[2].value, '₱750.00');
  await page.selectSection('grooming');
  assert.equal(page.period, 'last_month');
  assert.equal(page.metric, 'visits');
  assert.equal(page.kpis.length, 4);
  await page.selectSection('clinic');
  assert.equal(page.report.overview.collections, 1500);
  assert.equal(calls.length, 3);
});

test('section switches cannot leak pending responses and returning to a loading section reuses its request', async () => {
  let finishGrooming, finishClinic;
  let groomingRequests = 0;
  const page = component({
    getGroomingPerformanceReport: () => { groomingRequests++; return new Promise(resolve => { finishGrooming = resolve; }); },
    getClinicPerformanceReport: () => new Promise(resolve => { finishClinic = resolve; }),
  });
  const grooming = page.init();
  const clinic = page.selectSection('clinic');
  await page.selectSection('grooming');
  assert.equal(groomingRequests, 1);
  const clinicData = report(); delete clinicData.customers; clinicData.overview.collections = 1500;
  finishClinic(clinicData); await clinic;
  assert.equal(page.report, null);
  assert.equal(page.loading, true);
  finishGrooming(report()); await grooming;
  assert.equal(page.report.overview.collections, 600);
  await page.selectSection('clinic');
  assert.equal(page.report.overview.collections, 1500);
  assert.equal(page.loading, false);
});

test('Clinic custom dates, failures, retry, and service expansion use the shared behavior', async () => {
  let fail = true;
  const calls = [];
  const clinic = report(); delete clinic.customers;
  clinic.services = Array.from({ length: 8 }, (_, i) => ({ name: `Clinic ${i}`, kind: 'service', count: 8 - i }));
  const page = component({
    getGroomingPerformanceReport: async () => report(),
    getClinicPerformanceReport: async args => { calls.push(args); if (fail) throw new Error('SQL details'); return clinic; },
  });
  await page.init(); await page.selectSection('clinic');
  assert.equal(page.error, 'Unable to load reports. Please try again.');
  assert.equal(page.report, null);
  page.period = 'custom'; page.start = '2026-10-31'; page.end = '2026-10-01';
  await page.loadReport();
  assert.ok(page.validationError); assert.equal(calls.length, 1);
  page.start = '2026-10-01'; page.end = '2026-10-31'; fail = false;
  await page.loadReport();
  assert.equal(page.error, ''); assert.equal(calls[1].start, '2026-10-01');
  assert.equal(page.visibleServices.length, 6);
  page.showAllServices = true; assert.equal(page.visibleServices.length, 8);
  page.metric = 'visits'; assert.equal(page.chartPoints[0].value, 2);
  assert.match(page.chartMarkup, /2 visits/);
  assert.equal(calls.length, 2);
});

test('custom range rejects invalid dates before fetching and forwards inclusive valid dates', async () => {
  const calls = [];
  const page = component({ getGroomingPerformanceReport: async args => { calls.push(args); return report(); } });
  page.period = 'custom';
  for (const [start, end] of [['', ''], ['2026-10-31', '2026-10-01'], ['2026-02-30', '2026-03-01']]) {
    page.start = start; page.end = end;
    await page.loadReport();
    assert.ok(page.validationError);
    assert.equal(page.report, null);
  }
  assert.equal(calls.length, 0);
  page.start = '2026-10-01'; page.end = '2026-10-31';
  await page.loadReport();
  assert.equal(calls[0].start, '2026-10-01');
  assert.equal(calls[0].end, '2026-10-31');
});

test('API errors and invalid responses hide business values; retry restores them', async () => {
  let fail = true;
  const page = component({ getGroomingPerformanceReport: async () => { if (fail) throw new Error('Unavailable'); return report(); } });
  page.report = report();
  await page.loadReport();
  assert.equal(page.report, null);
  assert.equal(page.error, 'Unable to load reports. Please try again.');
  assert.equal(page.loading, false);
  fail = false;
  await page.loadReport();
  assert.equal(page.error, '');
  assert.equal(page.report.overview.collections, 600);
  const broken = component({ getGroomingPerformanceReport: async () => ({ success: false }) });
  await broken.loadReport();
  assert.ok(broken.error);
  assert.equal(broken.report, null);
});

test('technical API errors never appear in the dashboard', async () => {
  const page = component({ getGroomingPerformanceReport: async () => {
    throw new Error("SQLSTATE[42000]: 1055 private_column isn't in GROUP BY; SELECT * FROM payments");
  } });
  await page.loadReport();
  assert.equal(page.error, 'Unable to load reports. Please try again.');
  assert.equal(page.report, null);
});

test('an older request cannot overwrite the newly selected period or its loading state', async () => {
  const pending = [];
  const page = component({ getGroomingPerformanceReport: () => new Promise(resolve => pending.push(resolve)) });
  const first = page.loadReport();
  page.period = 'today';
  const second = page.loadReport();
  const latest = report(); latest.overview.collections = 900;
  pending[1](latest); await second;
  pending[0](report()); await first;
  assert.equal(page.report.overview.collections, 900);
  assert.equal(page.loading, false);
});

test('empty charts remain finite and service expansion preserves the backend order', async () => {
  const empty = report();
  empty.overview.collections = 0; empty.overview.completedVisits = 0;
  empty.performance[0].collections = 0; empty.performance[0].visits = 0;
  empty.services = Array.from({ length: 9 }, (_, i) => ({ name: `Recorded ${i}`, kind: 'addon', count: 9 - i }));
  const page = component({ getGroomingPerformanceReport: async () => empty });
  await page.init();
  assert.equal(page.hasPerformance, false);
  assert.ok(page.chartPoints.every(p => Number.isFinite(p.x) && Number.isFinite(p.y)));
  assert.equal(page.visibleServices.length, 6);
  page.showAllServices = true;
  assert.equal(page.visibleServices.length, 9);
  assert.equal(page.visibleServices[0].name, 'Recorded 0');
});
