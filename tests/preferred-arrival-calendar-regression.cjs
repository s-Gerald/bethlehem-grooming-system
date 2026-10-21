const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../scripts/components/booking-calendar.js'), 'utf8')
  .replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, '')
  .replace(/export async function /g, 'async function ');

async function checkCalendar(service) {
  function element() {
    const handlers = {};
    let html = '';
    return {
      children: [], textContent: '', disabled: false,
      classList: { add() {}, remove() {}, toggle() {} },
      setAttribute() {},
      addEventListener(type, handler) { handlers[type] = handler; },
      appendChild(child) { this.children.push(child); },
      get innerHTML() { return html; },
      set innerHTML(value) { html = value; this.children = []; },
      async click() { assert.equal(this.disabled, false); await handlers.click?.(); },
    };
  }
  const elements = new Map();
  const get = id => {
    if (!elements.has(id)) elements.set(id, element());
    return elements.get(id);
  };
  const values = new Map();
  let currentTime = new Date('2026-10-09T02:00:00Z');
  const status = {
    stopped_today: false, blocked_dates: [],
    availability: Object.fromEntries(['clinic', 'grooming'].map(key => [key, {
      operating_hours_label: '8:00 AM – 5:00 PM', pre_registration_cutoff_time: '14:00',
    }])),
  };
  const windows = [
    { window_id: 1, window_label: '8:00 AM - 9:00 AM', start_time: '08:00:00', end_time: '09:00:00', is_full: true, recommended: true, booked: 30 },
    { window_id: 2, window_label: '11:00 AM - 12:00 PM', start_time: '11:00:00', end_time: '12:00:00' },
    { window_id: 3, window_label: '1:00 PM - 2:00 PM', start_time: '13:00:00', end_time: '14:00:00', is_closed: true },
  ];
  const context = vm.createContext({
    Date: class extends Date {
      constructor(...args) { super(...(args.length ? args : [currentTime])); }
    },
    Intl, console,
    formatBookingSchedule: (date, label) => `${date} · ${label}`,
    document: { getElementById: get, createElement: element },
    window: { location: {} },
    sessionStorage: { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) },
    API: { getClinicStatus: async () => status, getTimeslots: async () => ({ day_full: true, windows }) },
  });
  vm.runInContext(source, context);
  await context.initBookingCalendar({
    service,
    fetchTimeslots: async () => ({ windows: windows.map((window) => ({ ...window })) }),
  });
  const date = day => get('calendarGrid').children.find(button => String(button.textContent) === String(day));
  assert.equal(date(8).disabled, true, 'Past dates stay disabled.');
  assert.equal(date(13).disabled, true, 'The existing three-day limit remains.');
  await date(10).click();
  let buttons = get('timeSlots').children;
  assert.equal(buttons[0].disabled, false, 'Registration counts cannot fill a preferred window.');
  assert.ok(buttons.every(button => !/Recommended|Full/.test(button.innerHTML)));
  assert.equal(buttons[2].disabled, true, 'Real closures still disable times.');
  await buttons[0].click();
  assert.equal(JSON.parse(values.get('bookingSchedule')).window_id, 1);
  assert.equal(get('nextStepBtn').disabled, false);
  await date(9).click();
  assert.equal(get('timeSlots').children[0].disabled, true, 'Past times stay disabled.');
  assert.equal(get('timeSlots').children[1].disabled, false);
  windows[1].is_cutoff = true;
  await context.refreshBookingCalendar();
  assert.equal(get('timeSlots').children[1].disabled, false, 'Legacy cutoff flags no longer restrict arrival windows.');
  status.blocked_dates = [{ start_date: '2026-10-10', end_date: '2026-10-10' }];
  await context.refreshBookingCalendar();
  assert.equal(date(10).disabled, true, 'Closure dates remain disabled.');
  status.stopped_today = true;
  await context.refreshBookingCalendar();
  assert.equal(date(9).disabled, true, 'Done for Today blocks same-day registration.');
  status.stopped_today = false;
  currentTime = new Date('2026-10-09T06:01:00Z');
  await context.refreshBookingCalendar();
  assert.equal(date(9).disabled, false, 'Legacy same-day cutoff no longer disables the date.');
}

(async () => {
  await checkCalendar('grooming');
  await checkCalendar('clinic');
  console.log('Clinic and Grooming preferred-arrival calendar regression checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
