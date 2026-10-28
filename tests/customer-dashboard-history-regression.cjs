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
  vm.runInContext(dashboardComponent, context);
  await new Promise((resolve) => setImmediate(resolve));

  return { element, consoleErrors, refresh: context.window._refreshAppointments };
}

function testPhilippineDateRollover() {
  let now = '2026-10-09T15:59:59Z';
  const context = vm.createContext({ Date: class extends Date { constructor(...args) { super(...(args.length ? args : [now])); } }, Intl });
  vm.runInContext(source.slice(source.indexOf('  function getPhilippineTodayKey()'), source.indexOf('  function renderMyPetsSummary(')), context);
  assert.equal(context.getPhilippineTodayKey(), '2026-10-09');
  now = '2026-10-09T16:00:00Z';
  assert.equal(context.getPhilippineTodayKey(), '2026-10-10');
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

  assert.match(schedule, /No upcoming visits/);
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
  assert.match(dashboard.element("appointmentsList").innerHTML, /cancel-10/);

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
  assert.match(schedule, /Grooming/);
  assert.doesNotMatch(schedule, /Regular Grooming/);
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
      capacity: { capacity: { max: 20, used, remaining: 20 - used, percent: used * 5, is_full: used >= 20 }, queue: { active: 1 } },
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
    const label = status === 'no_show' ? 'No Show' : 'Expired';
    assert.equal(context.groomingOutcome(status)[0], label);
    assert.ok(context.buildHistoryCard({ type: "grooming", booking, pet: null }).includes(`>${label}</span>`));
    assert.ok(context.buildHistoryModalContent(booking, null).includes(`>${label}</span>`));
    assert.doesNotMatch(context.groomingOutcome(status)[1], /amber|red|rose/);
  }
  assert.equal(context.groomingOutcome('archived')[0], 'Completed');
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
  let booking = { booking_id: 701, booking_type: "online", status: "waiting_to_arrive", pets: [{ pet_name: "Coco" }] };
  const dashboard = await runDashboard(async () => ({ bookings: [booking], history: [] }));
  assert.match(dashboard.element("appointmentsList").innerHTML, /View online registration summary/);
  assert.match(dashboard.element("appointmentsList").innerHTML, /Waiting to Arrive/);
  assert.match(dashboard.element("appointmentsList").innerHTML, /Cancel/);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /View online registration summary/);
  for (const status of ["checked_in", "in_progress", "for_payment", "released"]) {
    booking.status = status;
    await dashboard.refresh();
    assert.doesNotMatch(dashboard.element("appointmentsList").innerHTML, /Coco|View online registration summary/);
    assert.match(dashboard.element("groomingTracker").innerHTML, /View online registration summary/);
  }
  booking.status = "checked_in";
  booking.pets = [{ pet_name: "Coco", grooming_status: "checked_in", pet_queue_number: 2 }, { pet_name: "Luna", grooming_status: "checked_in", pet_queue_number: 3 }];
  await dashboard.refresh();
  const uniform = dashboard.element("groomingTracker").innerHTML;
  assert.match(uniform, /Queue #2/); assert.match(uniform, /Queue #3/);
  assert.doesNotMatch(uniform, /Waiting to start grooming|All 2 pets|>Checked In<\/p>/);
  booking.booking_type = "walk_in";
  await dashboard.refresh();
  assert.match(dashboard.element("groomingTracker").innerHTML, /View visit details/);
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /View online registration summary/);
  booking.show_grooming_tracker = false;
  await dashboard.refresh();
  assert.doesNotMatch(dashboard.element("groomingTracker").innerHTML, /View online registration summary/);
  booking = { ...booking, status: "archived" };
  await dashboard.refresh();
  assert.doesNotMatch(dashboard.element("appointmentsList").innerHTML, /Coco/);
}

async function testPhoneVerificationUsesServerProofAndPreservesAccess() {
  const previous = { window: global.window, document: global.document, API: global.API };
  const originalNow = Date.now;
  let now = originalNow();
  Date.now = () => now;
  const elements = new Map();
  const calls = { access: 0, dialogs: 0, navigation: [], sends: 0, verifies: 0, resends: 0, profiles: 0, sendArgs: [], resendIds: [] };
  let flags = { available: false, enforced: false };
  let sendError = null, verifyError = null, pendingSend = null;
  let timer;
  const countdowns = new Set();
  let expectedGeneration = 'generation-one';
  let recovery = null, stateCalls = 0, pendingState = null;
  let eligible = true;
  function element(id) {
    if (elements.has(id)) return elements.get(id);
    const attributes = new Map(id === 'phoneVerificationSpinner' ? [['hidden', '']] : []);
    const listeners = {};
    const classes = new Set();
    const node = {
      id, value: '', textContent: '', hidden: false, disabled: false, open: false,
      set innerHTML(html) {
        for (const match of html.matchAll(/<([a-z][\w-]*)\b[^>]*\bid="([^"]+)"[^>]*>([^<]*)/gi)) {
          element(match[2]).textContent = match[3].trim();
        }
      },
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
      async dispatch(name, extra = {}) {
        const event = { target: node, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...extra };
        for (const handler of listeners[name] || []) await handler(event);
        return event;
      },
      querySelector(selector) { return element(selector.replace('#', '')); },
      querySelectorAll() { return cells; },
      appendChild() {},
      focus() { global.document.activeElement = node; },
      setSelectionRange(start, end) { node.selectionStart = start; node.selectionEnd = end; },
      showModal() { node.open = true; calls.dialogs++; },
      close() { node.open = false; void node.dispatch('close'); },
    };
    elements.set(id, node);
    return node;
  }
  const cells = Array.from({ length: 6 }, (_, index) => element(`cell${index}`));
  const trigger = element('preRegisterButton');
  trigger.parentElement = { appendChild() {} };
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
    location: { hostname: 'clinic.example', search: '', assign(url) { calls.navigation.push(url); } },
    requestAnimationFrame(callback) { scheduled.push(callback); },
    requestIdleCallback(callback) { return callback(); },
    setInterval(callback, milliseconds) { timer = callback; if (milliseconds === 1000) countdowns.add(callback); return callback; },
    clearInterval(callback) { countdowns.delete(callback); },
    dispatchEvent() {},
  };
  global.API = {
    async getPhoneVerificationState() { stateCalls++; if (pendingState) await pendingState; return { challenge: recovery, allowed_prefixes: ['0917', '0895', '0896', '0897', '0898', '0991'] }; },
    readCustomerCache: () => ({ fresh: true, data: { allowed: true, sms_verification: flags } }),
    async getPreRegistrationAccess() { calls.access++; return { allowed: eligible, sms_verification: flags }; },
    async getMe() { calls.profiles++; return { user: { ...user, phone_verification_status: 'verified' } }; },
    async sendPhoneVerification(phone, password) {
      calls.sends++;
      calls.sendArgs.push([phone, password]);
      assert.match(phone, /^0[89]\d{9}$/);
      if (pendingSend) await pendingSend;
      if (sendError) throw sendError;
      return { success: true, challenge_id: 'generation-one', expires_in: 300, resend_after: 60 };
    },
    async verifyPhoneVerification(id, code) {
      calls.verifies++; assert.equal(id, expectedGeneration); assert.equal(code, '123456');
      if (verifyError) throw verifyError;
      return { success: true };
    },
    async resendPhoneVerification(id) { calls.resends++; calls.resendIds.push(id); if (sendError) throw sendError; return { success: true, challenge_id: 'generation-two', expires_in: 300, resend_after: 60 }; },
  };
  try {
    const context = { window: global.window, document: global.document };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/auth/verification-code.js'), 'utf8'), context);
    await import(pathToFileURL(path.join(__dirname, '../scripts/components/customer-pre-registration-button.js')).href);
    await trigger.dispatch('click');
    assert.equal(calls.navigation.length, 1, 'Unavailable optional SMS preserves navigation.');
    flags = { available: true, enforced: false };
    await scheduled[0](); await new Promise(resolve => setImmediate(resolve));
    trigger.setAttribute('aria-disabled', 'true');
    await trigger.dispatch('click');
    assert.equal(calls.dialogs, 0);
    trigger.setAttribute('aria-disabled', 'false');
    trigger.customerProfileReady = Promise.resolve({ ...user, phone_verification_status: 'verified' });
    await trigger.dispatch('click');
    assert.equal(calls.navigation.length, 2);
    trigger.customerProfileReady = Promise.resolve(user);
    await trigger.dispatch('click');
    const dialog = element('phoneVerificationDialog');
    assert.equal(dialog.open, true);
    assert.equal(element('phoneVerificationPhone').value, '0917 123 4567');
    assert.equal(element('phoneVerificationPhoneField').hidden, false, 'Saved numbers remain editable.');
    assert.equal(element('phoneVerificationEntry').hidden, false);
    const phoneField = element('phoneVerificationPhone');
    const phoneError = element('phoneVerificationPhoneError');
    const requestsBeforeTyping = [calls.sends, stateCalls];
    assert.equal(phoneError.textContent, '');
    for (const value of ['09', '0999', '0999123456']) {
      phoneField.value = value; await phoneField.dispatch('input');
      assert.equal(phoneError.textContent, '', 'Incomplete typing has no premature error.');
    }
    phoneField.value = '09991234567'; await phoneField.dispatch('input');
    assert.equal(phoneError.textContent, "SMS verification isn't supported for this number.");
    assert.equal(phoneField.getAttribute('aria-invalid'), 'true');
    assert.equal(element('phoneVerificationNext').disabled, true);
    assert.equal(element('phoneVerificationMessage').textContent, '');
    await element('phoneVerificationForm').dispatch('submit');
    assert.deepEqual([calls.sends, stateCalls], requestsBeforeTyping, 'Unsupported edits and submission never request SMS or policy.');
    phoneField.value = '0999123456'; await phoneField.dispatch('input');
    assert.equal(phoneError.textContent, '');
    assert.equal(phoneField.getAttribute('aria-invalid'), 'false');
    await phoneField.dispatch('blur');
    assert.equal(phoneError.textContent, 'Enter an 11-digit mobile number.');
    phoneField.value = ''; await phoneField.dispatch('input'); await phoneField.dispatch('blur');
    assert.equal(phoneError.textContent, '');
    phoneField.value = '07123456789'; await phoneField.dispatch('input');
    assert.equal(phoneError.textContent, 'Enter a valid Philippine mobile number.');
    for (const value of ['08951234567', '08961234567', '08971234567', '08981234567', '09911234567']) {
      phoneField.value = value; await phoneField.dispatch('input');
      assert.equal(phoneError.textContent, '');
      assert.equal(element('phoneVerificationNext').disabled, false);
    }
    phoneField.value = '0917x12345678'; phoneField.selectionStart = 5; phoneField.selectionEnd = 5;
    await phoneField.dispatch('input');
    assert.equal(phoneField.value, '0917 123 4567');
    assert.equal(phoneField.selectionStart, 4, 'Sanitizing preserves the caret relative to the remaining digits.');
    phoneField.value = '0917'; phoneField.selectionStart = 4; phoneField.selectionEnd = 4;
    await phoneField.dispatch('paste', { clipboardData: { getData: () => ' 123-45678' } });
    assert.equal(phoneField.value, '0917 123 4567');
    phoneField.selectionStart = 0; phoneField.selectionEnd = 4;
    await phoneField.dispatch('paste', { clipboardData: { getData: () => '0895' } });
    assert.equal(phoneField.value, '0895 123 4567', 'Pasting replaces the selected prefix.');
    phoneField.selectionStart = 0; phoneField.selectionEnd = 4;
    await phoneField.dispatch('paste', { clipboardData: { getData: () => '089612345' } });
    assert.equal(phoneField.value, '0896 123 4567', 'An oversized paste preserves unselected trailing digits.');
    phoneField.selectionStart = 0; phoneField.selectionEnd = 13;
    await phoneField.dispatch('paste', { clipboardData: { getData: () => '+63 897-123-4567' } });
    assert.equal(phoneField.value, '0897 123 4567', 'A full-field international paste is displayed in the required local format.');
    phoneField.value = '09171234567'; await phoneField.dispatch('input');
    await trigger.dispatch('click'); assert.equal(calls.dialogs, 1);
    await dialog.dispatch('click'); assert.equal(dialog.open, true);
    assert.equal((await dialog.dispatch('cancel')).defaultPrevented, true);
    sendError = new Error('SMS unavailable');
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationOtp').hidden, true, 'Failed sends never claim a sent code.');
    assert.equal(element('phoneVerificationTitle').textContent, 'Verification Unavailable');
    assert.match(element('phoneVerificationDescription').textContent, /Try again or use another number/);
    assert.equal(element('phoneVerificationRecovery').hidden, false);
    await element('phoneVerificationChange').dispatch('click');
    sendError = null;
    let finishSend;
    pendingSend = new Promise(resolve => { finishSend = resolve; });
    const sending = element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationNext').disabled, true);
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(calls.sends, 2, 'Repeated submit does not duplicate a pending send.');
    finishSend(); await sending; pendingSend = null;
    assert.equal(element('phoneVerificationOtp').hidden, false);
    assert.equal(element('phoneVerificationNumber').textContent, '0917 \u2022\u2022\u2022\u2022 567');
    assert.equal(element('phoneVerificationChange').hidden, false);
    assert.equal(elements.has('phoneVerificationNetwork'), false);
    const readsBeforeEditing = [stateCalls, calls.profiles, calls.sends];
    await element('phoneVerificationChange').dispatch('click');
    assert.equal(element('phoneVerificationTitle').textContent, 'Change Phone Number');
    assert.equal(element('phoneVerificationEntry').hidden, false);
    assert.equal(element('phoneVerificationPhone').value, '0917 123 4567');
    assert.equal(element('phoneVerificationNext').disabled, true, 'Editing does not reset cooldown.');
    assert.equal(global.document.activeElement, element('phoneVerificationPhone'));
    element('phoneVerificationPhone').value = '09991234567';
    await element('phoneVerificationForm').dispatch('submit');
    assert.deepEqual([stateCalls, calls.profiles, calls.sends], readsBeforeEditing, 'Changing/editing during cooldown is local and never sends.');
    await element('phoneVerificationBack').dispatch('click');
    assert.equal(element('phoneVerificationOtp').hidden, false);
    assert.equal(element('phoneVerificationNumber').textContent, '0917 •••• 567');
    assert.equal(global.document.activeElement, element('phoneVerificationCode'));
    assert.equal(element('phoneVerificationVerify').disabled, true);
    assert.equal(element('phoneVerificationResend').disabled, true);
    assert.match(element('phoneVerificationResend').textContent, /1m 00s/);
    await element('phoneVerificationResend').dispatch('click'); assert.equal(calls.resends, 0);
    const code = element('phoneVerificationCode');
    await code.dispatch('paste', { clipboardData: { getData: () => '12a345678' } });
    assert.equal(code.value, '123456');
    assert.equal(cells.map(cell => cell.textContent).join(''), '123456');
    assert.equal(element('phoneVerificationVerify').disabled, false);
    verifyError = new Error('Incorrect code');
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(calls.navigation.length, 2);
    assert.match(element('phoneVerificationMessage').textContent, /Incorrect/);
    verifyError = null;
    now += 60000; timer();
    assert.equal(element('phoneVerificationResend').disabled, false);
    await element('phoneVerificationChange').dispatch('click');
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(calls.sends, 2, 'An unchanged active candidate reuses the challenge.');
    assert.equal(element('phoneVerificationOtp').hidden, false);
    await element('phoneVerificationResend').dispatch('click');
    assert.equal(calls.resends, 1);
    assert.equal(element('phoneVerificationCode').value, '');
    expectedGeneration = 'generation-two';
    await code.dispatch('paste', { clipboardData: { getData: () => '123456' } });
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(calls.navigation.length, 3, 'Only confirmed verification navigates.');
    assert.equal(calls.profiles, 1, 'One profile refresh follows confirmation.');
    assert.equal(dialog.open, false);
    assert.equal(global.document.activeElement, trigger);
    trigger.customerProfileReady = Promise.resolve({ phone: null, phone_verification_status: 'not_added' });
    await trigger.dispatch('click');
    assert.equal(element('phoneVerificationTitle').textContent, 'Add Phone Number');
    element('phoneVerificationPhone').value = '123';
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationOtp').hidden, true);
    assert.equal(element('phoneVerificationPhone').getAttribute('aria-invalid'), 'true');
    element('phoneVerificationPhone').value = '09179876543';
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationNumber').textContent, '0917 \u2022\u2022\u2022\u2022 543');
    await element('phoneVerificationCancel').dispatch('click');
    assert.equal(dialog.open, false);
    assert.equal(calls.navigation.length, 3);
    await trigger.dispatch('click');
    await element('phoneVerificationClose').dispatch('click'); assert.equal(dialog.open, false);
    await trigger.dispatch('click');
    element('phoneVerificationPhone').value = '09179876543';
    let finishLateSend;
    pendingSend = new Promise(resolve => { finishLateSend = resolve; });
    const lateSend = element('phoneVerificationForm').dispatch('submit');
    await element('phoneVerificationCancel').dispatch('click');
    finishLateSend(); await lateSend; pendingSend = null;
    assert.equal(dialog.open, false);
    assert.equal(calls.navigation.length, 3, 'A late response after cancellation cannot navigate.');
    await trigger.dispatch('click');
    element('phoneVerificationPhone').value = '09179876543';
    await element('phoneVerificationForm').dispatch('submit');
    now += 301000; timer();
    assert.equal(element('phoneVerificationVerify').disabled, true);
    assert.match(element('phoneVerificationMessage').textContent, /expired/i);
    assert.equal(element('phoneVerificationOtp').hidden, true);
    assert.equal(element('phoneVerificationChange').hidden, false);
    assert.equal(global.document.activeElement, element('phoneVerificationRetry'), 'Expiry moves focus out of the hidden code input.');
    await element('phoneVerificationChange').dispatch('click');
    assert.equal(element('phoneVerificationBack').hidden, true, 'An expired challenge cannot return to code entry.');
    await element('phoneVerificationClose').dispatch('click');
    const { openPhoneVerification } = await import(pathToFileURL(path.join(__dirname, '../scripts/components/customer-phone-verification.js')).href + '?v=mobile-format-20261029');
    recovery = { success: true, challenge_id: 'generation-two', masked_destination: '0917 •••• 567', expires_in: 120, resend_after: 30, status: 'active', can_verify: true, replacement: true };
    const sendsBeforeRecovery = calls.sends;
    await openPhoneVerification(trigger, { ...user, phone_verification_status: 'verified' }, { navigate: false });
    assert.equal(element('phoneVerificationOtp').hidden, false, 'Explicit reopening restores a persisted challenge.');
    assert.equal(calls.sends, sendsBeforeRecovery, 'Recovery never resends.');
    assert.equal(element('phoneVerificationPasswordField').hidden, true, 'An authorized replacement resumes without another password.');
    await code.dispatch('paste', { clipboardData: { getData: () => '123456' } });
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(calls.navigation.length, 3, 'Settings confirmation stays on Settings.');
    assert.equal(calls.profiles, 2);
    assert.equal(countdowns.size, 0, 'Closing clears the modal countdown.');
    recovery = null;
    await openPhoneVerification(trigger, { ...user, phone_verification_status: 'verified' }, { navigate: false });
    assert.equal(element('phoneVerificationPasswordField').hidden, false);
    await element('phoneVerificationClose').dispatch('click');
    recovery = { success: true, challenge_id: 'uncertain-one', masked_destination: '0917 •••• 567', expires_in: 120, resend_after: 42, status: 'uncertain', can_verify: false };
    await openPhoneVerification(trigger, user, { navigate: false });
    assert.equal(element('phoneVerificationTitle').textContent, 'Verification Unavailable');
    assert.equal(element('phoneVerificationOtp').hidden, true);
    assert.equal(element('phoneVerificationVerify').disabled, true);
    assert.equal(element('phoneVerificationRecovery').hidden, false);
    assert.equal(element('phoneVerificationRetry').disabled, true);
    assert.equal(element('phoneVerificationRetry').textContent, 'Try Again in 42s');
    const requestsBeforeRecoveryEdit = [calls.sends, calls.resends, calls.profiles, stateCalls];
    await element('phoneVerificationChange').dispatch('click');
    assert.equal(element('phoneVerificationPhone').value, '0917 123 4567');
    assert.equal(element('phoneVerificationPasswordField').hidden, true);
    element('phoneVerificationPhone').value = '09911234567';
    await element('phoneVerificationForm').dispatch('submit');
    assert.deepEqual([calls.sends, calls.resends, calls.profiles, stateCalls], requestsBeforeRecoveryEdit);
    now += 42000; timer();
    const uncertainNew = { success: true, challenge_id: 'uncertain-two', masked_destination: '0991 •••• 567', expires_in: 300, resend_after: 60, status: 'uncertain', can_verify: false };
    sendError = Object.assign(new Error('SMS could not be confirmed.'), { status: 503, code: 'sms_send_uncertain', data: { resend_after: 60, challenge: uncertainNew } });
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(element('phoneVerificationOtp').hidden, true, 'A failed replacement hides unusable code controls immediately.');
    assert.equal(element('phoneVerificationNumber').textContent, '0991 •••• 567');
    assert.equal(element('phoneVerificationRetry').disabled, true);
    sendError = null;
    now += 60000; timer();
    assert.equal(element('phoneVerificationRetry').textContent, 'Try Again');
    await element('phoneVerificationRetry').dispatch('click');
    assert.equal(calls.resendIds.at(-1), 'uncertain-two', 'Retry must target the newly accepted candidate, never the old one.');
    assert.equal(element('phoneVerificationOtp').hidden, false);
    await element('phoneVerificationClose').dispatch('click');
    recovery = { ...uncertainNew, status: 'failed' };
    await openPhoneVerification(trigger, user, { navigate: false });
    assert.equal(element('phoneVerificationDescription').textContent, 'SMS could not be sent. Try again or use another number.');
    assert.equal(element('phoneVerificationOtp').hidden, true);
    assert.equal(element('phoneVerificationRetry').disabled, true);
    assert.equal(element('phoneVerificationChange').disabled, false);
    await element('phoneVerificationClose').dispatch('click');
    recovery = { ...uncertainNew, status: 'locked', resend_after: 0 };
    await openPhoneVerification(trigger, user, { navigate: false });
    assert.equal(element('phoneVerificationRetry').disabled, true);
    await element('phoneVerificationChange').dispatch('click');
    assert.equal(element('phoneVerificationNext').disabled, true, 'Changing numbers cannot bypass lockout.');
    await element('phoneVerificationClose').dispatch('click');
    recovery = { success: true, challenge_id: 'generation-two', masked_destination: '0917 •••• 567', expires_in: 120, resend_after: 0, status: 'active', can_verify: true, replacement: true };
    await openPhoneVerification(trigger, { ...user, phone_verification_status: 'verified' }, { navigate: false });
    await element('phoneVerificationChange').dispatch('click');
    assert.equal(element('phoneVerificationPasswordField').hidden, false);
    element('phoneVerificationPhone').value = '09911234567';
    const sendsBeforePassword = calls.sends;
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(calls.sends, sendsBeforePassword);
    element('phoneVerificationPassword').value = 'current-password';
    await element('phoneVerificationForm').dispatch('submit');
    assert.deepEqual(calls.sendArgs.at(-1), ['09911234567', 'current-password']);
    assert.equal(element('phoneVerificationPassword').value, '', 'The password is cleared after requesting a replacement.');
    await element('phoneVerificationClose').dispatch('click');
    recovery = { ...recovery, replacement: false };
    await openPhoneVerification(trigger, user, { navigate: false });
    await element('phoneVerificationChange').dispatch('click');
    element('phoneVerificationPhone').value = '09911234567';
    await element('phoneVerificationBack').dispatch('click');
    sendError = new Error('Connection lost');
    await element('phoneVerificationResend').dispatch('click');
    sendError = null;
    assert.equal(element('phoneVerificationOtp').hidden, true);
    await element('phoneVerificationRetry').dispatch('click');
    assert.equal(calls.sendArgs.at(-1)[0], '09171234567', 'Retry uses the displayed candidate, not an abandoned edit.');
    assert.equal(countdowns.size, 1, 'Recovery and resend reuse one countdown.');
    await element('phoneVerificationClose').dispatch('click');
    assert.equal(countdowns.size, 0);
    recovery = { success: true, challenge_id: 'unsupported-legacy', masked_destination: '0999 •••• 567', expires_in: 120, resend_after: 0, status: 'active', can_verify: true };
    await openPhoneVerification(trigger, user, { navigate: false });
    sendError = Object.assign(new Error("SMS verification isn't supported for this number."), {
      status: 422, errors: { phone: ["SMS verification isn't supported for this number."] },
      data: { code: 'sms_destination_unsupported' },
    });
    await element('phoneVerificationResend').dispatch('click');
    assert.equal(element('phoneVerificationEntry').hidden, false, 'A rejected legacy resend returns to the editable phone field.');
    assert.equal(phoneError.textContent, "SMS verification isn't supported for this number.");
    phoneField.value = '08951234567'; await phoneField.dispatch('input');
    assert.equal(phoneError.textContent, '');
    sendError = null;
    await element('phoneVerificationClose').dispatch('click');
    recovery = { success: true, challenge_id: 'generation-two', masked_destination: '0917 •••• 567', expires_in: 120, resend_after: 0, status: 'active', can_verify: true };
    await openPhoneVerification(trigger, user, { navigate: false });
    await element('phoneVerificationChange').dispatch('click');
    element('phoneVerificationPhone').value = '09911234567';
    recovery = { ...uncertainNew, challenge_id: 'lost-response' };
    sendError = Object.assign(new Error('Connection lost'), { status: 0 });
    const readsBeforeLostResponse = stateCalls, sendsBeforeLostResponse = calls.sends;
    await element('phoneVerificationForm').dispatch('submit');
    assert.equal(stateCalls, readsBeforeLostResponse + 1, 'A lost HTTP response recovers server timing once, without polling.');
    assert.equal(calls.sends, sendsBeforeLostResponse + 1, 'Network recovery does not retry sending.');
    assert.equal(element('phoneVerificationNumber').textContent, '0991 •••• 567');
    assert.equal(element('phoneVerificationRetry').disabled, true);
    assert.equal(element('phoneVerificationOtp').hidden, true);
    sendError = null;
    await element('phoneVerificationClose').dispatch('click');
    recovery = null;
    let finishState;
    pendingState = new Promise(resolve => { finishState = resolve; });
    const opening = openPhoneVerification(trigger, user);
    await element('phoneVerificationClose').dispatch('click');
    finishState(); await opening; pendingState = null;
    assert.equal(dialog.open, false, 'Late state recovery never reopens a dismissed modal.');
    assert.ok(stateCalls > 0);
    await openPhoneVerification(trigger, { phone: '09991234567', phone_verification_status: 'unverified' }, { navigate: false });
    assert.equal(phoneError.textContent, "SMS verification isn't supported for this number.", 'A saved completed number explains why sending is disabled.');
    assert.equal(element('phoneVerificationNext').disabled, true);
    await element('phoneVerificationClose').dispatch('click');
    for (const phone of ['08951234567', '08961234567', '08971234567', '08981234567']) {
      await openPhoneVerification(trigger, { phone: null, phone_verification_status: 'not_added' }, { navigate: false });
      phoneField.value = phone; await phoneField.dispatch('input');
      await element('phoneVerificationForm').dispatch('submit');
      assert.equal(calls.sendArgs.at(-1)[0], phone);
      assert.equal(element('phoneVerificationOtp').hidden, false, 'Approved 08 numbers reach the existing code screen.');
      await element('phoneVerificationClose').dispatch('click');
    }
    trigger.customerProfileReady = Promise.resolve(user);
    flags = { available: false, enforced: true };
    await scheduled[0](); await new Promise(resolve => setImmediate(resolve));
    await trigger.dispatch('click');
    assert.equal(dialog.open, false);
    assert.equal(calls.navigation.length, 3, 'An unavailable enforced flow has no navigation fallback.');
    eligible = false;
    await scheduled[0](); await new Promise(resolve => setImmediate(resolve));
    await trigger.dispatch('click'); assert.equal(dialog.open, false, 'Ongoing registrations still block entry.');
    assert.equal(calls.access, 3, 'Modal actions add no eligibility polling.');
  } finally {
    Date.now = originalNow;
    Object.assign(global, previous);
  }
}

async function testClinicRegistrationUsesServerStatusAndCheckInNumber() {
  let visit = { id: 17, reference: "CL-20261026-017", pet_name: "Clinic patient", status: "waiting_to_arrive", service_day: "2026-10-26", queue_number: null, created_at: "2026-10-26T09:00:00+08:00" };
  const dashboard = await runDashboard(async () => ({ bookings: [], history: [], clinic_visits: [visit] }));
  let card = dashboard.element("appointmentsList").innerHTML;
  assert.match(card, /Clinic patient/);
  assert.match(card, /Waiting to Arrive/);
  assert.match(card, /CL-20261026-017/);
  assert.match(card, /Visit day/);
  visit = { ...visit, status: "checked_in", queue_number: 11, checked_in_at: "2026-10-26T09:30:00+08:00" };
  await dashboard.refresh();
  card = dashboard.element("appointmentsList").innerHTML;
  assert.match(card, /Queue number: 11/);
  assert.match(card, /Checked in:/);
  assert.match(card, /Checked In/);
  assert.doesNotMatch(card, /Queue number: CL-/);
}


(async () => {
  await testClinicRegistrationUsesServerStatusAndCheckInNumber();
  await testPhoneVerificationUsesServerProofAndPreservesAccess();
  testPhilippineDateRollover();
  await testVisitSummaryFollowsVisitState();
  await testTrackerOverallStateAndIndividualCards();
  await testTrackerDisclosureAndPrioritySurviveRefresh();
  await testExpiredRegistrationsHaveNeutralHistoryLabels();
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
