const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const { pathToFileURL } = require("node:url");

const dashboardPath = path.resolve(
  __dirname,
  "../scripts/components/client-dashboard.js",
);
const source = fs.readFileSync(dashboardPath, "utf8");
const marker = source.indexOf("Appointments, Grooming Tracker & History");
const start = source.indexOf("(function () {", marker);

assert.notEqual(marker, -1, "Customer dashboard section marker is missing.");
assert.notEqual(start, -1, "Customer dashboard component could not be isolated.");

const dashboardComponent = source.slice(start);

function createElementStore() {
  const elements = new Map();

  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set();
      elements.set(id, {
        id,
        innerHTML: "<initial>Loading...</initial>",
        textContent: "",
        value: "",
        min: "",
        max: "",
        disabled: false,
        style: {},
        dataset: {},
        listeners: {},
        className: "",
        classList: {
          add(...names) { names.forEach((name) => classes.add(name)); },
          remove(...names) { names.forEach((name) => classes.delete(name)); },
          toggle(name, force) {
            const add = force === undefined ? !classes.has(name) : force;
            if (add) classes.add(name);
            else classes.delete(name);
            return add;
          },
          contains(name) { return classes.has(name); },
        },
        addEventListener(type, callback) { this.listeners[type] = callback; },
        focus() { this.focused = true; },
        setCustomValidity() {},
        querySelectorAll() { return []; },
      });
    }

    return elements.get(id);
  }

  return { element };
}

async function runDashboard(getBookingHistory, { pets = [], capacity = {} } = {}) {
  const { element } = createElementStore();
  const consoleErrors = [];
  const context = {
    API: {
      readCustomerCache: () => null,
      loadCustomerData: (endpoint, load, render) => load().then(render),
      getUserPets: async () => ({ pets }),
      getGroomingCapacity: async () => capacity,
      getBookingHistory,
    },
    console: {
      error(...args) { consoleErrors.push(args); },
    },
    document: {
      getElementById: element,
      addEventListener(type, callback) {
        if (type === "DOMContentLoaded") callback();
      },
    },
    setInterval: () => 0,
    setTimeout: (callback) => {
      callback();
      return 0;
    },
    window: { location: {} },
  };

  vm.createContext(context);
  vm.runInContext(fs.readFileSync(path.join(__dirname, "../scripts/services/grooming-estimate-engine.js"), "utf8"), context);
  vm.runInContext(dashboardComponent, context);
  await new Promise((resolve) => setImmediate(resolve));

  return { element, consoleErrors, refresh: context.window._refreshAppointments };
}

function testRescheduleArrivalExpiryInManila() {
  let now = '2026-10-09T15:59:59Z';
  const context = vm.createContext({
    Date: class extends Date {
      constructor(...args) { super(...(args.length ? args : [now])); }
    },
    Intl, window: {},
  });
  vm.runInContext(
    source.slice(source.indexOf('  function getRescheduleTodayKey()'), source.indexOf('  function addDaysToDateKey('))
    + source.slice(source.indexOf('  function isRescheduleSlotPast('), source.indexOf('  function isCurrentRescheduleSelection(')),
    context,
  );
  assert.equal(context.getRescheduleTodayKey(), '2026-10-09');
  assert.equal(context.isRescheduleSlotPast({ end_time: '23:59:59' }, '2026-10-09'), true);
  assert.equal(context.isRescheduleSlotPast({ end_time: '23:59:59', latest_arrival_time: '23:59:59' }, '2026-10-09'), false);
  now = '2026-10-09T16:00:00Z';
  assert.equal(context.getRescheduleTodayKey(), '2026-10-10');
  assert.equal(context.isRescheduleSlotPast({ end_time: '00:00:00' }, '2026-10-10'), true);
  assert.equal(context.isRescheduleSlotPast({ end_time: '00:00:01' }, '2026-10-10'), false);
  assert.equal(context.isRescheduleSlotPast({ end_time: '00:00:00', latest_arrival_time: '00:00:00' }, '2026-10-10'), false);
  now = '2026-10-09T16:00:01Z';
  assert.equal(context.isRescheduleSlotPast({ end_time: '00:00:00', latest_arrival_time: '00:00:00' }, '2026-10-10'), true);
  assert.equal(context.isRescheduleSlotPast({ is_past: true }, '2026-10-11'), true);
  assert.equal(context.isRescheduleSlotPast({ end_time: '08:00:00' }, '2026-10-11'), false);
}

async function testCompletedHistoryDoesNotBreakEmptySchedule() {
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [],
    history_total: 1,
    history: [{
      booking_id: 90,
      booking_reference: "BAC-20260803-0003",
      booking_date: "2026-08-03",
      paid: true,
      pets: [{ pet_name: "Peter" }],
    }],
  }));

  const schedule = element("appointmentsList").innerHTML;
  const history = element("groomingHistory").innerHTML;

  assert.match(schedule, /No upcoming grooming scheduled/);
  assert.doesNotMatch(schedule, /Failed to load schedule/);
  assert.match(history, /Peter/);
  assert.doesNotMatch(history, /<initial>Loading/);
  assert.equal(consoleErrors.length, 0, "Rendering emitted an unexpected error.");
}

async function testApiFailureSettlesEveryDashboardPanel() {
  const { element } = await runDashboard(async () => {
    throw new Error("Simulated history failure");
  });

  assert.match(element("appointmentsList").innerHTML, /Failed to load schedule/);
  assert.match(element("groomingTracker").innerHTML, /Failed to load grooming status/);
  assert.match(element("groomingHistory").innerHTML, /Failed to load grooming history/);

  for (const id of ["appointmentsList", "groomingTracker", "groomingHistory"]) {
    assert.doesNotMatch(
      element(id).innerHTML,
      /<initial>Loading/,
      `${id} remained in its loading state.`,
    );
  }
  assert.equal(element("groomingTrackerMetadata").innerHTML, "");
}

async function testTrackerPaymentMetadata() {
  const scenarios = [
    { status: "checked_in", paid: true, label: "Pre-Paid" },
    { status: "in_progress", paid: false, label: null },
    { status: "released", paid: true, label: "Paid" },
    { status: "released", paid: true, label: "Pre-Paid", paidAt: "2026-09-15T08:00:00+08:00", finishedAt: "2026-09-15T10:00:00+08:00" },
    { status: "released", paid: true, label: "Paid", paidAt: "2026-09-15T11:00:00+08:00", finishedAt: "2026-09-15T10:00:00+08:00" },
  ];

  for (const scenario of scenarios) {
    const { element, consoleErrors } = await runDashboard(async () => ({
      bookings: [{
        booking_id: 1,
        booking_reference: "BAC-20260915-0001",
        booking_date: "2026-09-15",
        status: scenario.status,
        paid: scenario.paid,
        payment_summary: { paid_at: scenario.paidAt },
        grooming_finished_timestamp: scenario.finishedAt,
        pets: [{
          pet_name: "Peter",
          grooming_status: scenario.status,
        }],
      }],
      history: [],
    }));

    const metadata = element("groomingTrackerMetadata").innerHTML;
    assert.match(metadata, /Ref\. BAC-20260915-0001/);
    if (scenario.label) {
      const labelPosition = metadata.indexOf(">" + scenario.label + "<");
      assert.notEqual(labelPosition, -1, "The correct payment label is present.");
      assert.ok(labelPosition < metadata.indexOf("Ref."), "Payment is above the reference.");
    } else {
      assert.doesNotMatch(metadata, /Pre-Paid|>Paid</);
    }
    assert.doesNotMatch(element("groomingTracker").innerHTML, /Ref\.|#bell|We’ll notify/);
    assert.equal(consoleErrors.length, 0);
  }
}

async function testStateHierarchyAndLiveTransitions() {
  let bookings = [];
  const dashboard = await runDashboard(async () => ({ bookings, history: [] }));
  const hidden = (id) => dashboard.element(id).classList.contains("hidden");
  assert.equal(hidden("trackerSection"), false);
  assert.equal(hidden("upcomingSection"), true);
  assert.match(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.match(dashboard.element("groomingTracker").innerHTML, /Your pet’s status will appear here after clinic check-in\./);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /<svg/);

  bookings = [{ booking_id: 10, status: "waiting_to_arrive", booking_date: "2026-10-04", pets: [{ pet_name: "Luna", species: "cat" }] }];
  await dashboard.refresh();
  assert.equal(hidden("trackerSection"), false);
  assert.equal(hidden("upcomingSection"), false);
  assert.match(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.match(dashboard.element("appointmentsList").innerHTML, /reschedule-10/);

  bookings.push({ booking_id: 11, status: "in_progress", pets: [{ pet_name: "Bruno", grooming_status: "in_progress" }] });
  await dashboard.refresh();
  assert.equal(hidden("trackerSection"), false);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.match(dashboard.element("groomingStatusAnnouncement").textContent, /Bruno: Grooming in Progress/);
  bookings[1].status = "for_payment";
  await dashboard.refresh();
  assert.match(dashboard.element("groomingStatusAnnouncement").textContent, /Bruno: Ready for Pickup/);
  bookings = [];
  await dashboard.refresh();
  assert.equal(hidden("trackerSection"), false);
  assert.match(dashboard.element("groomingTracker").innerHTML, /No grooming in progress/);
  assert.equal(dashboard.element("groomingTrackerMetadata").innerHTML, "");
  assert.equal(dashboard.consoleErrors.length, 0);
}

async function testMultiPetProgressAndPickupReadiness() {
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [{
      booking_id: 1, status: "in_progress", booking_reference: "REF",
      pets: [
        { pet_name: "Bruno", grooming_status: "in_progress" },
        { pet_name: "Mochi", grooming_status: "checked_in" },
        { pet_name: "Luna", grooming_status: "grooming_finished", active_in_grooming: false },
        { pet_name: "Referred", clinic_referred: true },
      ],
    }], history: [],
  }));
  const tracker = element("groomingTracker").innerHTML;
  assert.match(tracker, /Bruno/);
  assert.match(tracker, /Mochi/);
  assert.match(tracker, /Grooming finished/);
  assert.doesNotMatch(tracker, /Referred|Being Groomed/);
  assert.doesNotMatch(tracker, /Luna is ready\. Please/);
  assert.match(element("groomingStatusAnnouncement").textContent, /Luna: Grooming finished/);
  assert.equal((tracker.match(/<ol /g) || []).length, 1, "Mixed statuses share one grooming visit progress tracker.");
  assert.equal(consoleErrors.length, 0);
}

async function testPreviewsAndNearestSchedule() {
  const pets = [
    { pet_id: 1, pet_name: "Breed only", breed: "Maltese", birthdate: null },
    { pet_id: 2, pet_name: "No details", breed: null, birthdate: null, species: "cat" },
    { pet_id: 3, pet_name: "<Pet>", breed: "-", birthdate: "2022-01-01" },
    { pet_id: 4, pet_name: "Outside preview" },
  ];
  const booking = (id, date, time, status = "waiting_to_arrive") => ({
    booking_id: id, booking_date: date, status,
    time_window: { window_label: time },
    pets: [{ pet_name: "Pet " + id, services: [{ service_name: "Regular Grooming" }] }],
  });
  const { element, consoleErrors } = await runDashboard(async () => ({
    bookings: [
      booking(4, "2026-10-05", "8:00 AM - 9:00 AM"),
      booking(3, "2026-10-04", "1:00 PM - 2:00 PM"),
      booking(2, "2026-10-04", "9:00 AM - 10:00 AM", "waiting"),
      booking(1, "2026-10-04", "8:00 AM - 9:00 AM"),
    ],
    history: [booking(5, "2026-10-02", ""), booking(6, "2026-10-01", ""), booking(7, "2026-09-30", ""), booking(8, "2026-09-29", "")],
  }), { pets });
  const schedule = element("appointmentsList").innerHTML;
  assert.ok(schedule.indexOf("Pet 1") < schedule.indexOf("Pet 2"));
  assert.ok(schedule.indexOf("Pet 2") < schedule.indexOf("Pet 3"));
  assert.doesNotMatch(schedule, /Pet 4|reschedule-2|cancel-2/);
  assert.match(schedule, /Regular Grooming/);
  const preview = element("myPetsPreview").innerHTML;
  assert.match(preview, /Maltese/);
  assert.doesNotMatch(preview, /Maltese ·|Outside preview|<Pet>|· -|· —/);
  assert.match(preview, /&lt;Pet&gt;/);
  assert.match(preview, /#cat/);
  assert.match(element("groomingHistory").innerHTML, /Pet 7/);
  assert.doesNotMatch(element("groomingHistory").innerHTML, /Pet 8/);
  assert.equal(consoleErrors.length, 0);
}

async function testQueueStates() {
  for (const [used, label] of [[1, "Open"], [16, "Nearly Full"], [20, "Full"]]) {
    const { element } = await runDashboard(async () => ({ bookings: [], history: [] }), {
      capacity: { capacity: { max: 20, used, remaining: 20 - used }, queue: { active: 1 } },
    });
    assert.equal(element("groomingCapacityBadge").textContent, label);
    assert.equal(element("groomingQueueLoading").classList.contains("hidden"), true);
    assert.equal(element("groomingQueueDetails").classList.contains("flex"), true);
    assert.equal(element("groomingQueueSummary").textContent, "1 pet currently in the grooming queue");
    assert.match(element("groomingCapacityText").textContent, /Grooming pets on-site|Grooming capacity/);
    assert.doesNotMatch(element("groomingCapacityText").textContent, /Clinic|grooming slots|daily capacity/);
  }
}

async function testInitialAndReturningSessionGreetings() {
  const shell = source.slice(0, source.indexOf("// ── Customer Notification Bell"));
  async function greeting(cookie, userId = 7) {
    const { element } = createElementStore();
    const context = {
      API: { getMe: async () => ({ user: { user_id: userId, first_name: "Gerald", last_name: "Senining" } }) },
      console,
      document: { cookie, getElementById: element, body: { classList: { toggle() {}, contains() {} } }, addEventListener() {} },
      window: {
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        requestAnimationFrame: (callback) => callback(),
        requestIdleCallback: (callback) => callback(),
        addEventListener() {},
        location: { href: "http://127.0.0.1:8000/pages/client/dashboard.html" },
      },
    };
    element("clientSidebar").querySelectorAll = () => [];
    for (const id of ["clientSidebarToggle", "dashboardGreeting"]) element(id).setAttribute = () => {};
    vm.createContext(context);
    vm.runInContext(shell, context);
    await new Promise((resolve) => setImmediate(resolve));
    return element("dashboardGreeting").textContent;
  }
  const initialCookie = "other=value; bethlehem_customer_initial_session_7=1";
  assert.equal(await greeting(initialCookie), "Welcome, Gerald");
  assert.equal(await greeting(initialCookie), "Welcome, Gerald", "Reloads and new tabs in the initial session stay new.");
  assert.equal(await greeting(""), "Welcome back, Gerald", "A later browser session welcomes the customer back.");
  assert.equal(await greeting(initialCookie, 8), "Welcome back, Gerald", "The marker belongs only to the registered customer.");
}

async function testExpiredRegistrationsHaveNeutralHistoryLabels() {
  const historySource = fs.readFileSync(path.resolve(__dirname, '../pages/client/grooming-history.html'), 'utf8');
  const helpers = historySource.slice(historySource.indexOf('function escapeHistoryHtml'), historySource.indexOf('function closeHistoryModal'));
  const { healthDate } = await import("../scripts/components/customer-health-records.js");
  const context = vm.createContext({ Intl, Date, healthDate, document: { getElementById: () => null } });
  vm.runInContext(helpers, context);
  for (const status of ['expired', 'no_show']) {
    const booking = { status, number_of_pets: 1, pets: [] };
    assert.equal(context.groomingOutcome(status)[0], 'Expired');
    assert.match(context.buildHistoryCard({ type: "grooming", booking, pet: null }), />Expired<\/span>/);
    assert.match(context.buildHistoryModalContent(booking, null), />Expired<\/span>/);
    assert.doesNotMatch(context.groomingOutcome(status)[1], /amber|red|rose/);
  }
  assert.equal(context.groomingOutcome('archived')[0], 'Completed');
}

async function testPerPetGroomingEstimatesAndOverdueTracker() {
  const now = Date.now();
  let bookings = [{ booking_id: 501, status: "checked_in", pets: [
    { pet_name: "Coco", size: "medium", grooming_status: "checked_in", grooming_estimate: { minMinutes: 90, maxMinutes: 105, formatted: "1 hr 30 min–1 hr 45 min", preferenceLabel: "Regular Trim" } },
    { pet_name: "Bruno", size: "large", grooming_status: "checked_in", grooming_estimate: { minMinutes: 120, maxMinutes: 120, formatted: "2 hrs" } },
  ] }];
  const dashboard = await runDashboard(async () => ({ bookings, history: [] }));
  assert.match(dashboard.element("groomingTracker").innerHTML, /Est\. grooming: 1 hr 30 min–1 hr 45 min/);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /Regular Trim|Medium/);
  assert.match(dashboard.element("groomingTracker").innerHTML, /Est\. grooming: 2 hrs/);
  for (const [size, minutes, formatted] of [['large', 120, '2 hrs'], ['small', 90, '1 hr 30 min']]) {
    bookings[0].pets[0].size = size;
    bookings[0].pets[0].grooming_estimate = { ...bookings[0].pets[0].grooming_estimate, minMinutes: minutes, maxMinutes: minutes, formatted };
    await dashboard.refresh();
    assert.ok(dashboard.element("groomingTracker").innerHTML.includes(`Est. grooming: ${formatted}`));
    assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /1 hr 30 min–1 hr 45 min/);
  }
  bookings[0].pets[0] = { ...bookings[0].pets[0], grooming_status: "in_progress", grooming_started_at: "1:15 PM", grooming_started_timestamp: new Date(now).toISOString() };
  await dashboard.refresh();
  assert.match(dashboard.element("groomingTracker").innerHTML, /Est\. ready:/);
  assert.match(dashboard.element("groomingTracker").innerHTML, /Started at 1:15 PM/);
  bookings[0].pets[0].grooming_started_timestamp = new Date(now - 91 * 60000).toISOString();
  await dashboard.refresh();
  assert.match(dashboard.element("groomingTracker").innerHTML, /Taking longer than estimated/);
  assert.match(dashboard.element("groomingStatusAnnouncement").textContent, /Taking longer than estimated/);
  assert.equal(dashboard.consoleErrors.length, 0);
}

async function testTrackerOverallStateAndIndividualCards() {
  const scenarios = [
    { statuses: ["checked_in", "checked_in"], overall: "Checked In", step: 0 },
    { statuses: ["checked_in", "in_progress"], overall: "Grooming in Progress", step: 1 },
    { statuses: ["for_payment", "in_progress", "checked_in"], overall: "Grooming in Progress", step: 1 },
    { statuses: ["for_payment", "checked_in"], overall: "Grooming in Progress", step: 1 },
    { statuses: ["for_payment", "released"], overall: "Ready for Pickup", step: 2 },
    { statuses: ["grooming_finished", "grooming_finished"], overall: "Grooming in Progress", step: 1 },
  ];
  for (const { statuses, overall, step } of scenarios) {
    const pets = statuses.map((status, index) => ({ pet_name: `Pet ${index}`, grooming_status: status }));
    const { element, consoleErrors } = await runDashboard(async () => ({
      bookings: [{ booking_id: 22, status: "checked_in", pets }], history: [],
    }));
    const tracker = element("groomingTracker").innerHTML;
    assert.match(tracker, new RegExp(`<h4[^>]*>${overall}</h4>`));
    assert.equal((tracker.match(/data-tracker-pet /g) || []).length, pets.length);
    assert.equal((tracker.match(/<ol /g) || []).length, 1);
    const steps = [...tracker.matchAll(/<li[\s\S]*?<\/li>/g)].map((match) => match[0]);
    steps.forEach((markup, index) => {
      assert.equal(markup.includes('aria-current="step"'), index === step);
      assert.ok(markup.includes(index < step ? ", complete" : index === step ? ", current" : ", upcoming"));
    });
    pets.forEach((pet) => assert.equal(tracker.split(`>${pet.pet_name}</h6>`).length - 1, 1));
    assert.doesNotMatch(tracker, /Appointment|Booking|appointment|booking/);
    assert.equal(consoleErrors.length, 0);
  }

  const { element } = await runDashboard(async () => ({ bookings: [{
    booking_id: 23, status: "in_progress", pets: [
      { pet_name: "Waiting pet", grooming_status: "in_progress", grooming_started_at: null, grooming_started_timestamp: null },
      { pet_name: "Active pet", grooming_status: "in_progress", grooming_started_at: "4:21 PM", grooming_started_timestamp: new Date().toISOString() },
      { pet_name: "Referred pet", clinic_referred: true },
    ],
  }], history: [] }));
  const tracker = element("groomingTracker").innerHTML;
  assert.match(tracker, /1 of 2 pets is currently being groomed/);
  assert.match(tracker, /Waiting pet<\/h6>[\s\S]*?>Checked In<\/p>[\s\S]*?Waiting to start grooming/);
  assert.match(tracker, /Started at 4:21 PM/);
  assert.doesNotMatch(tracker, /Referred pet/);
}

async function testTrackerDisclosureAndPrioritySurviveRefresh() {
  let bookings = [{ booking_id: 30, status: "checked_in", pets: [
    ...Array.from({ length: 7 }, (_, index) => ({ pet_name: `Waiting ${index}`, grooming_status: "checked_in" })),
    { pet_name: "Grooming first", grooming_status: "in_progress" },
    { pet_name: "Ready pet", grooming_status: "for_payment" },
    { pet_name: "Grooming second", grooming_status: "in_progress" },
  ] }];
  const originalOrder = bookings[0].pets.map((pet) => pet.pet_name);
  const dashboard = await runDashboard(async () => ({ bookings, history: [] }));
  const markup = () => dashboard.element("groomingTracker").innerHTML;
  const toggle = dashboard.element("tracker-toggle-30");
  toggle.dataset.trackerToggle = "30";
  const click = () => dashboard.element("groomingTracker").listeners.click({ target: { closest: () => toggle } });
  assert.match(markup(), /2 grooming · 1 ready · 7 waiting/);
  assert.match(markup(), /Showing 4 of 10 pets/);
  assert.match(markup(), /Show all 10 pets/);
  assert.match(markup(), /aria-expanded="false"/);
  assert.equal((markup().match(/data-tracker-pet /g) || []).length, 4);
  assert.ok(markup().indexOf("Ready pet</h6>") < markup().indexOf("Grooming first</h6>"));
  assert.ok(markup().indexOf("Grooming first</h6>") < markup().indexOf("Grooming second</h6>"));
  assert.ok(markup().indexOf("Grooming second</h6>") < markup().indexOf("Waiting 0</h6>"));
  assert.doesNotMatch(markup(), /Waiting 1<\/h6>/);
  assert.deepEqual(bookings[0].pets.map((pet) => pet.pet_name), originalOrder);
  click();
  assert.match(markup(), /Showing 10 of 10 pets/);
  assert.match(markup(), /Show fewer/);
  assert.match(markup(), /aria-expanded="true"/);
  assert.equal((markup().match(/data-tracker-pet /g) || []).length, 10);
  assert.equal(toggle.focused, true);
  bookings.push({ booking_id: 31, status: "checked_in", pets: bookings[0].pets });
  await dashboard.refresh();
  assert.match(markup(), /Showing 10 of 10 pets/);
  assert.match(markup(), /Showing 4 of 10 pets/);
  bookings[0].pets[0].grooming_status = "in_progress";
  await dashboard.refresh();
  assert.match(markup(), /3 grooming · 1 ready · 6 waiting/);
  assert.match(markup(), /Showing 10 of 10 pets/);
  click();
  assert.equal((markup().match(/data-tracker-pet /g) || []).length, 8);
  assert.equal(dashboard.consoleErrors.length, 0);
}

async function testVisitSummaryFollowsVisitState() {
  let booking = { booking_id: 701, status: "waiting_to_arrive", pets: [{ pet_name: "Coco" }] };
  const dashboard = await runDashboard(async () => ({ bookings: [booking], history: [] }));
  assert.match(dashboard.element("appointmentsList").innerHTML, /View drop-off summary/);
  assert.match(dashboard.element("appointmentsList").innerHTML, /Waiting to arrive/);
  assert.match(dashboard.element("appointmentsList").innerHTML, /Reschedule[\s\S]*Cancel/);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /View visit details/);
  for (const status of ["checked_in", "in_progress", "for_payment", "released"]) {
    booking.status = status;
    await dashboard.refresh();
    assert.doesNotMatch(dashboard.element("appointmentsList").innerHTML, /Coco|View drop-off summary/);
    assert.match(dashboard.element("groomingTracker").innerHTML, /View visit details/);
  }
  booking.show_grooming_tracker = false;
  await dashboard.refresh();
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /View visit details/);
  booking = { ...booking, status: "archived" };
  await dashboard.refresh();
  assert.doesNotMatch(dashboard.element("appointmentsList").innerHTML, /Coco/);
}

async function testPhonePreviewPreservesAccessAndNeverVerifies() {
  const previous = { window: global.window, document: global.document, API: global.API };
  const elements = new Map();
  const calls = { access: 0, dialogs: 0, navigation: [] };
  let eligible = true;
  function element(id) {
    if (elements.has(id)) return elements.get(id);
    const attributes = new Map(id === 'phoneVerificationSpinner' ? [['hidden', '']] : []);
    const listeners = {};
    const classes = new Set();
    const node = {
      id, value: '', textContent: '', hidden: false, disabled: false, open: false,
      href: 'http://127.0.0.1:8000/pages/client/pre-register.html',
      classList: {
        toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
      },
      setAttribute(name, value) { attributes.set(name, value); },
      removeAttribute(name) { attributes.delete(name); },
      getAttribute(name) { return attributes.get(name) ?? null; },
      hasAttribute(name) { return attributes.has(name); },
      addEventListener(name, handler) { (listeners[name] ??= []).push(handler); },
      async dispatch(name) {
        const event = { target: node, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        for (const handler of listeners[name] || []) await handler(event);
        return event;
      },
      querySelector(selector) { return element(selector.replace('#', '')); },
      querySelectorAll() { return cells; },
      appendChild() {},
      focus() { global.document.activeElement = node; },
      setSelectionRange() {},
      showModal() { node.open = true; calls.dialogs++; },
      close() { node.open = false; void node.dispatch('close'); },
    };
    elements.set(id, node);
    return node;
  }
  const cells = Array.from({ length: 6 }, (_, index) => element(`cell${index}`));
  const trigger = element('preRegisterButton');
  trigger.setAttribute('data-phone-verification-preview', '');
  const user = { phone: '09171234567', phone_verification_status: 'unverified' };
  trigger.customerProfileReady = Promise.resolve(user);
  const scheduled = [];
  global.document = {
    activeElement: trigger, visibilityState: 'visible',
    head: { appendChild() {} }, body: { appendChild() {} },
    getElementById: (id) => elements.get(id) || null,
    querySelectorAll: () => [trigger],
    createElement: (tag) => element(tag === 'dialog' ? 'phoneVerificationDialog' : tag),
    addEventListener() {},
  };
  global.window = {
    location: { hostname: 'clinic.example', search: '?smsPreview=1', assign(url) { calls.navigation.push(url); } },
    requestAnimationFrame(callback) { scheduled.push(callback); },
    requestIdleCallback(callback) { return callback(); },
    setInterval() {},
  };
  global.API = {
    readCustomerCache: () => ({ fresh: true, data: { allowed: true } }),
    async getPreRegistrationAccess() { calls.access++; return { allowed: eligible }; },
  };
  try {
    const context = { window: global.window, document: global.document };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/auth/verification-code.js'), 'utf8'), context);
    await import(pathToFileURL(path.join(__dirname, '../scripts/components/customer-pre-registration-button.js')).href);
    assert.equal((await trigger.dispatch('click')).defaultPrevented, false, 'Production navigation stays native even with a preview parameter.');
    global.window.location.hostname = '127.0.0.1';
    global.window.location.search = '';
    assert.equal((await trigger.dispatch('click')).defaultPrevented, false, 'Local preview must be opt-in.');
    global.window.location.search = '?smsPreview=1';
    trigger.setAttribute('aria-disabled', 'true');
    await trigger.dispatch('click');
    assert.equal(calls.dialogs, 0, 'Disabled pre-registration never opens a modal.');
    trigger.setAttribute('aria-disabled', 'false');
    trigger.customerProfileReady = Promise.resolve({ ...user, phone_verification_status: 'verified' });
    await trigger.dispatch('click');
    assert.equal(calls.dialogs, 0, 'Server-confirmed verified customers continue directly.');
    assert.equal(calls.navigation.length, 1);
    trigger.customerProfileReady = Promise.resolve(user);
    await trigger.dispatch('click');
    const dialog = element('phoneVerificationDialog');
    assert.equal(dialog.open, true, 'Unverified customers see the local preview.');
    assert.equal(element('phoneVerificationNumber').textContent, '0917 •••• 567');
    assert.equal(element('phoneVerificationEntry').hidden, false);
    await trigger.dispatch('click');
    assert.equal(calls.dialogs, 1, 'Repeated clicks reuse the open modal.');
    await dialog.dispatch('click');
    assert.equal(dialog.open, true, 'Backdrop clicks do not dismiss.');
    assert.equal((await dialog.dispatch('cancel')).defaultPrevented, true, 'Escape dismissal is prevented.');
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationOtp').hidden, false);
    assert.equal(global.document.activeElement, element('phoneVerificationCode'));
    const code = element('phoneVerificationCode');
    code.value = '12a345678';
    await code.dispatch('input');
    assert.equal(code.value, '123456');
    assert.equal(cells.map(cell => cell.textContent).join(''), '123456');
    element('phoneVerificationState').value = 'loading';
    await element('phoneVerificationState').dispatch('change');
    assert.equal(element('phoneVerificationVerify').disabled, true);
    assert.equal(element('phoneVerificationVerify').getAttribute('aria-busy'), 'true');
    assert.equal(element('phoneVerificationSpinner').hasAttribute('hidden'), false, 'Loading preview exposes the spinner.');
    element('phoneVerificationState').value = 'error';
    await element('phoneVerificationState').dispatch('change');
    assert.match(element('phoneVerificationMessage').textContent, /unavailable/i);
    element('phoneVerificationState').value = 'ready';
    await element('phoneVerificationState').dispatch('change');
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(user.phone_verification_status, 'unverified');
    assert.match(element('phoneVerificationMessage').textContent, /unavailable/i);
    await element('phoneVerificationResend').dispatch('click');
    assert.match(element('phoneVerificationMessage').textContent, /unavailable/i);
    assert.equal(calls.navigation.length, 1, 'No entered code produces successful verification.');
    await element('phoneVerificationCancel').dispatch('click');
    assert.equal(dialog.open, false);
    assert.equal(global.document.activeElement, trigger);
    trigger.customerProfileReady = Promise.resolve({ phone: null, phone_verification_status: 'not_added' });
    await trigger.dispatch('click');
    assert.equal(element('phoneVerificationTitle').textContent, 'Add Phone Number');
    assert.equal(element('phoneVerificationPhone').value, '');
    element('phoneVerificationPhone').value = '123';
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationOtp').hidden, true);
    assert.equal(element('phoneVerificationPhone').getAttribute('aria-invalid'), 'true');
    element('phoneVerificationPhone').value = '09179876543';
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationOtp').hidden, false);
    assert.equal(element('phoneVerificationNumber').textContent, '0917 •••• 543');
    await element('phoneVerificationClose').dispatch('click');
    assert.equal(dialog.open, false);
    await trigger.dispatch('click');
    eligible = false;
    await scheduled[0]();
    await new Promise(resolve => setImmediate(resolve));
    await element('phoneVerificationContinue').dispatch('click');
    assert.equal(calls.navigation.length, 1, 'Continuation respects an eligibility change while the modal is open.');
    eligible = true;
    await scheduled[0]();
    await new Promise(resolve => setImmediate(resolve));
    await element('phoneVerificationContinue').dispatch('click');
    assert.equal(calls.navigation.length, 2, 'Explicit preview continuation uses existing navigation.');
    assert.equal(calls.access, 2, 'Preview actions add no access requests.');
    assert.equal(user.phone_verification_status, 'unverified');
  } finally {
    Object.assign(global, previous);
  }
}

(async () => {
  await testPhonePreviewPreservesAccessAndNeverVerifies();
  testRescheduleArrivalExpiryInManila();
  await testVisitSummaryFollowsVisitState();
  await testTrackerOverallStateAndIndividualCards();
  await testTrackerDisclosureAndPrioritySurviveRefresh();
  await testExpiredRegistrationsHaveNeutralHistoryLabels();
  await testPerPetGroomingEstimatesAndOverdueTracker();
  await testInitialAndReturningSessionGreetings();
  await testStateHierarchyAndLiveTransitions();
  await testMultiPetProgressAndPickupReadiness();
  await testPreviewsAndNearestSchedule();
  await testQueueStates();
  await testCompletedHistoryDoesNotBreakEmptySchedule();
  await testApiFailureSettlesEveryDashboardPanel();
  await testTrackerPaymentMetadata();
  console.log("Customer dashboard history regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
