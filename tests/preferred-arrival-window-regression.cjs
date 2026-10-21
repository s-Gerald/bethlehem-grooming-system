const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function element() {
  const classes = new Set();
  return {
    children: [], listeners: {}, disabled: false, value: '', checked: false,
    classList: {
      add(...names) { names.forEach((name) => classes.add(name)); },
      remove(...names) { names.forEach((name) => classes.delete(name)); },
      toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
      contains(name) { return classes.has(name); },
    },
    set innerHTML(value) { this.html = value; this.children = []; },
    get innerHTML() { return this.html || ''; },
    addEventListener(name, callback) { this.listeners[name] = callback; },
    appendChild(child) { this.children.push(child); },
    setAttribute() {}, focus() {}, scrollIntoView() {},
  };
}

function harness(file, bindings = {}) {
  const elements = new Map();
  const storage = new Map();
  const context = vm.createContext({
    console, Intl, Date, URLSearchParams,
    document: {
      getElementById(id) {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
      },
      createElement: element,
      body: element(),
    },
    window: { location: { href: '', replace(value) { this.href = value; } } },
    sessionStorage: {
      getItem(key) { return storage.get(key) ?? null; },
      setItem(key, value) { storage.set(key, String(value)); },
      removeItem(key) { storage.delete(key); },
    },
    formatBookingSchedule: (date, label) => `${date} ${label}`,
    ...bindings,
  });
  const source = fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8')
    .replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, '')
    .replace(/^export /gm, '');
  vm.runInContext(source, context, { filename: file });
  return { context, elements, storage };
}

const endedMessage = 'That arrival window just ended. Please choose the next available time.';
const slot = {
  window_id: 1, window_label: '7:00 PM - 8:00 PM',
  start_time: '19:00:00', end_time: '20:00:00',
  is_past: false, is_closed: false, is_cutoff: false,
};

async function calendar(service, time, date = '2026-10-09', restriction = null, windows = [slot]) {
  const run = harness('booking-calendar.js');
  const { context, storage } = run;
  let now = new Date(`2026-10-09T${time}+08:00`);
  context.Date = class extends Date {
    constructor(...args) { super(...(args.length ? args : [now])); }
  };
  const availability = {
    pre_registration_cutoff_time: restriction === 'cutoff' ? '19:00' : '22:00',
    pre_registration_cutoff_label: '10:00 PM', operating_hours_label: '6:00 PM – 10:00 PM',
  };
  context.API = {
    getClinicStatus: async () => ({
      stopped_today: restriction === 'stop_today',
      blocked_dates: restriction === 'closure' ? [{ start_date: date, end_date: date }] : [],
      availability: { clinic: availability, grooming: availability },
    }),
  };
  const key = service === 'clinic' ? 'clinicVisitDraft' : 'bookingSchedule';
  const draft = service === 'clinic'
    ? { appointmentDate: date, windowId: 1, windowLabel: slot.window_label, pet: { id: 101 }, chiefComplaint: 'Keep these notes' }
    : { date, window_id: 1, time: slot.window_label };
  storage.set(key, JSON.stringify(draft));
  storage.set('bookingPets', JSON.stringify([{ id: 101, petName: 'Mochi' }]));
  await context.initBookingCalendar({
    service, storageKey: key, dateField: service === 'clinic' ? 'appointmentDate' : 'date',
    fetchTimeslots: async () => ({ windows: windows.map((window) => ({ ...window })), availability }),
    ...(service === 'clinic' ? {
      clearSelection: () => storage.set(key, JSON.stringify({ ...JSON.parse(storage.get(key)), windowId: null, windowLabel: '' })),
      saveSelection: (selection) => storage.set(key, JSON.stringify({ ...JSON.parse(storage.get(key)), windowId: selection.window_id, windowLabel: selection.time })),
    } : {}),
  });
  return { ...run, key, setTime(value) { now = new Date(`2026-10-09T${value}+08:00`); } };
}

async function main() {
  const weightTools = await import('../scripts/components/pet-weight-size.js');
  const ageTools = await import('../scripts/services/grooming-age.js');
  const draftStorage = new Map();
  globalThis.sessionStorage = { getItem: (key) => draftStorage.get(key) ?? null };
  let submittedForecast = null;
  globalThis.API = {
    getTimeslots: async (date) => ({ date, pendingSelections: true }),
    forecastGroomingWindows: async (date, pets) => { submittedForecast = { date, pets }; return { windows: [] }; },
  };
  const forecastService = await import('../scripts/services/grooming-workload-forecast.js');
  assert.throws(() => forecastService.forecastGroomingWindows('2026-10-09'), /Complete Pet Information/);
  assert.equal(submittedForecast, null, 'No workload forecast runs before pets and services are complete');
  draftStorage.set('bookingPets', JSON.stringify([{ id: 101, petName: 'Mochi', petType: 'Dog', size: 'Extra Large', weight: '55' }]));
  draftStorage.set('bookingStep3', JSON.stringify({ petSelections: [{ petId: 101, servicePackage: 'regular_dog_grooming',
    groomingPreference: 'regular_trim', alaCarteServices: ['facial_trimming'], estimateFactors: ['extra_handling'] }] }));
  const originalDraft = [...draftStorage.entries()];
  await forecastService.forecastGroomingWindows('2026-10-09');
  assert.equal(submittedForecast.pets[0].size, 'extra_large');
  assert.equal(submittedForecast.pets[0].weight, undefined, "Saved selections reference the stored weight without rewriting it");
  assert.equal(submittedForecast.pets[0].grooming_preference, 'regular_trim');
  assert.deepEqual(submittedForecast.pets[0].services.ala_carte, ['facial_trimming']);
  assert.equal(submittedForecast.pets[0].estimate_factors, undefined, 'Customer forecasts never send staff factors');
  assert.deepEqual([...draftStorage.entries()], originalDraft, 'Forecast refreshes preserve the draft');

  const fullGrooming = await calendar('grooming', '19:05:00', '2026-10-09', null, [{ ...slot, is_workload_unavailable: true }]);
  assert.equal(fullGrooming.elements.get('timeSlots').children[0].disabled, true);
  assert.match(fullGrooming.elements.get('timeSlots').children[0].innerHTML, /Unavailable — selected services may run past closing\./);
  assert.equal(fullGrooming.storage.has('bookingPets'), true);
  vm.runInContext('state.options.fetchTimeslots = async () => ({ windows: [availableForecastWindow] })',
    Object.assign(fullGrooming.context, { availableForecastWindow: { ...slot, is_workload_unavailable: false } }));
  await fullGrooming.context.refreshBookingCalendar();
  assert.equal(fullGrooming.elements.get('timeSlots').children[0].disabled, false);
  assert.equal(fullGrooming.storage.has('bookingPets'), true);
  assert.equal(fullGrooming.elements.get('nextStepBtn').disabled, true, 'Reopened capacity does not silently select an arrival window');

  let workloadUnavailable = true, destination = null, forecastFailed = false;
  const review = harness('booking-review-step.js', {
    forecastGroomingWindows: async () => {
      if (forecastFailed) throw new Error('Offline');
      return { windows: [{ ...slot, is_workload_unavailable: workloadUnavailable }] };
    },
    readSessionJson: (key) => key === 'bookingSchedule' ? { date: '2026-10-09', window_id: 1 } : null,
    hasRequiredGroomingPreference: () => true,
    goToGroomingStep: (step) => { destination = step; },
    getBookingDraft: () => ({}),
  });
  vm.runInContext('state.reviewPayload = { items: [] }', review.context);
  await review.context.refreshBookingWorkloadForecast();
  assert.equal(review.elements.get('confirmBookingBtn').textContent, 'Choose arrival window');
  await review.context.handleConfirmClick();
  assert.equal(destination, 'schedule');
  workloadUnavailable = false;
  await review.context.refreshBookingWorkloadForecast();
  assert.equal(review.elements.get('confirmBookingBtn').textContent, 'Next Step');
  assert.match(review.elements.get('reviewActionNotice').textContent, /Staff will confirm admission at check-in/);
  forecastFailed = true;
  destination = null;
  await review.context.handleConfirmClick();
  assert.equal(destination, null, 'A failed refresh cannot bypass capacity revalidation');
  assert.equal(review.elements.get('confirmBookingBtn').textContent, 'Retry capacity forecast');

  const shortened = { ...slot, window_label: '3:00 PM - 3:30 PM', start_time: '15:00:00',
    end_time: '15:30:00', latest_arrival_time: '15:30:00' };
  const partial = await calendar('grooming', '14:33:00', '2026-10-09', null, [shortened]);
  assert.match(partial.elements.get('timeSlots').children[0].innerHTML, /3:00 PM - 3:30 PM/);
  assert.equal(JSON.parse(partial.storage.get(partial.key)).end_time, '15:30:00', 'Restoring a selection saves the refreshed range');
  partial.setTime('15:30:00');
  await partial.context.refreshBookingCalendar();
  assert.equal(partial.elements.get('nextStepBtn').disabled, false, 'An arrival completing exactly at closing is allowed');
  partial.setTime('15:30:01');
  partial.elements.get('nextStepBtn').listeners.click();
  assert.equal(partial.elements.get('nextStepBtn').disabled, true, 'One second past the shortened range is too late');
  assert.equal(partial.storage.has('bookingPets'), true);

  for (const service of ['clinic', 'grooming']) {
    for (const [time, available] of [['18:59:00', true], ['19:00:00', true], ['19:05:00', true], ['19:59:00', true], ['20:00:00', false]]) {
      const run = await calendar(service, time);
      const buttons = run.elements.get('timeSlots').children;
      assert.equal(buttons.length, 1, `${service} at ${time}: keep the window visible`);
      assert.equal(run.elements.get('nextStepBtn').disabled, !available);
      assert.equal(buttons[0].disabled, !available);
      assert.doesNotMatch(buttons[0].innerHTML, /Available now/);
      if (available) {
        assert.doesNotMatch(buttons[0].className, /bg-slate-200|cursor-not-allowed/);
      } else {
        assert.match(buttons[0].className, /bg-slate-200/);
        assert.match(buttons[0].className, /text-slate-400/);
        assert.match(buttons[0].className, /cursor-not-allowed/);
        assert.equal(buttons[0].listeners.click, undefined);
      }
    }

    // Backend past flags and active/future windows share the same renderer.
    const mixed = await calendar(service, '19:05:00', '2026-10-09', null, [
      { ...slot, window_id: 2, window_label: '6:00 PM - 7:00 PM', start_time: '18:00:00', end_time: '19:00:00', is_past: true },
      slot,
      { ...slot, window_id: 3, window_label: '8:00 PM - 9:00 PM', start_time: '20:00:00', end_time: '21:00:00' },
    ]);
    const mixedButtons = mixed.elements.get('timeSlots').children;
    assert.equal(mixedButtons.length, 3);
    assert.deepEqual(mixedButtons.map((button) => button.disabled), [true, false, false]);
    assert.match(mixedButtons[0].className, /bg-slate-200/);
    assert.equal(mixedButtons[0].listeners.click, undefined);
    mixedButtons.forEach((button) => assert.doesNotMatch(button.innerHTML, /Available now/));
    mixedButtons[2].listeners.click();
    assert.equal(JSON.parse(mixed.storage.get(mixed.key))[service === 'clinic' ? 'windowId' : 'window_id'], 3);
    for (const restriction of ['closure', 'stop_today']) {
      const run = await calendar(service, '19:05:00', '2026-10-09', restriction);
      assert.equal(run.elements.get('nextStepBtn').disabled, true, `${service}/${restriction}`);
      assert.equal(run.elements.get('timeSlots').children.length, 0);
      assert.equal(run.storage.has('bookingPets'), true);
      if (service === 'clinic') assert.equal(JSON.parse(run.storage.get(run.key)).chiefComplaint, 'Keep these notes');
    }
    const afterLegacyCutoff = await calendar(service, '19:05:00', '2026-10-09', 'cutoff');
    assert.equal(afterLegacyCutoff.elements.get('nextStepBtn').disabled, false, `${service} ignores legacy fixed cutoffs`);
    const future = await calendar(service, '23:00:00', '2026-10-10');
    assert.equal(future.elements.get('nextStepBtn').disabled, false);
    assert.doesNotMatch(future.elements.get('timeSlots').children[0].innerHTML, /Available now/);

    // A stale API response must not allow continuing after the end time.
    const expired = await calendar(service, '19:59:00');
    expired.setTime('20:00:00');
    expired.elements.get('nextStepBtn').listeners.click();
    assert.equal(expired.elements.get('nextStepBtn').disabled, true);
    assert.equal(expired.elements.get('timeSlots').children.length, 1);
    assert.equal(expired.elements.get('timeSlots').children[0].disabled, true);
    assert.match(expired.elements.get('timeSlots').children[0].className, /bg-slate-200/);
    assert.equal(expired.elements.get('timeSlots').children[0].listeners.click, undefined);
    assert.equal(expired.storage.has('bookingPets'), true);
    if (service === 'clinic') assert.equal(JSON.parse(expired.storage.get(expired.key)).chiefComplaint, 'Keep these notes');

    // Recovery notice survives refresh and clears when the next window is chosen.
    const recovered = await calendar(service, '19:05:00');
    recovered.storage.set(`${recovered.key}ArrivalError`, endedMessage);
    await recovered.context.refreshBookingCalendar();
    assert.equal(recovered.elements.get('arrivalWindowNotice').textContent, endedMessage);
    recovered.elements.get('timeSlots').children[0].listeners.click();
    assert.equal(recovered.storage.has(`${recovered.key}ArrivalError`), false);
    assert.equal(recovered.elements.get('arrivalWindowNotice').classList.contains('hidden'), true);
  }

  const clinicDraft = { appointmentDate: '2026-10-09', windowId: 1, windowLabel: slot.window_label,
    pet: { id: 101 }, commonConcerns: ['Vomiting'], chiefComplaint: 'Started yesterday' };
  const clinic = harness('clinic-visit-summary.js', {
    CLINIC_VISIT_DRAFT_KEY: 'clinicVisitDraft', CLINIC_VISIT_PETS_KEY: 'clinicVisitPets',
    CLINIC_VISIT_CONFIRMATION_KEY: 'clinicVisitConfirmation',
    requireCustomerSession: () => true, readClinicVisitDraft: () => clinicDraft,
    validateClinicVisitReason: () => '', formatClinicVisitDate: (date) => date,
    formatClinicVisitReason: () => '', getClinicVisitSummaryMarkup: () => '',
    clearClinicVisitArrival: () => Object.assign(clinicDraft, { windowId: null, windowLabel: '' }),
    API: { submitClinicPreRegistration: async () => { throw { code: 'arrival_window_ended', message: endedMessage }; } },
  });
  await clinic.elements.get('submitClinicVisitBtn').listeners.click();
  assert.equal(clinic.context.window.location.href, './clinic-visit-date.html');
  assert.equal(clinicDraft.windowId, null);
  assert.equal(clinicDraft.pet.id, 101);
  assert.equal(clinicDraft.chiefComplaint, 'Started yesterday');
  assert.equal(clinic.storage.get('clinicVisitDraftArrivalError'), endedMessage);

  // The actual Clinic storage helper clears only arrival fields.
  global.sessionStorage = clinic.context.sessionStorage;
  const clinicService = await import('../scripts/services/clinic-visit-service.js');
  clinic.storage.set('clinicVisitDraft', JSON.stringify({ ...clinicDraft, windowId: 1, windowLabel: slot.window_label }));
  clinicService.clearClinicVisitArrival();
  const preserved = JSON.parse(clinic.storage.get('clinicVisitDraft'));
  assert.equal(preserved.appointmentDate, '2026-10-09');
  assert.equal(preserved.pet.id, 101);
  assert.equal(preserved.chiefComplaint, 'Started yesterday');
  assert.deepEqual(preserved.commonConcerns, ['Vomiting']);
  assert.equal(preserved.windowId, null);
  clinicService.clearClinicVisitArrival('2026-10-10');
  assert.equal(JSON.parse(clinic.storage.get('clinicVisitDraft')).appointmentDate, '2026-10-10');

  for (const failure of [
    { code: 'arrival_window_ended', message: endedMessage },
    { code: 'grooming_forecast_unavailable', message: 'That arrival time just became unavailable. Please choose another available time.' },
    { code: 'grooming_forecast_unavailable', message: 'Grooming capacity is full for this date. Please choose another available date.' },
    { errors: { window_id: ['This preferred arrival window is unavailable.'] }, message: 'This preferred arrival window is unavailable.' },
  ]) {
    let groomingStep;
    const grooming = harness('booking-consent-step.js', {
      ...weightTools, ...ageTools,
      initGroomingConsent: () => ({ valid: true, prefill() {}, validate() {}, setBusy() {},
        confirmSubmission: async () => true,
        payload: () => ({ terms_agreed: true, sedation_consent: true, signer_name: 'Customer Name',
          consent_version: '2026-10-17', signature_strokes: [[[10, 10], [30, 30]]] }),
      }),
      loadGroomingCatalogue: async () => {}, getBookingDraft: () => ({}),
      normalizeStepThreeDraft: () => [], selectedPricingSignature: () => 'reviewed',
      goToGroomingStep: (step) => { groomingStep = step; },
      API: { getMe: async () => ({ user: {} }), storeBooking: async () => { throw failure; } },
    });
    for (const [key, value] of Object.entries({
      bookingSchedule: { date: '2026-10-09', window_id: 1 },
      bookingPets: [{ id: 101, petName: 'Mochi', petType: 'cat' }],
      bookingStep3: { petSelections: [{ petId: 101, servicePackage: 'cat_full_grooming' }] },
      bookingStep4Review: { pets: [{ petId: 101, specialInstructions: 'Keep these notes' }] },
      bookingConsentStep: { signer_name: 'Customer Name', signature_strokes: [[[10, 10], [30, 30]]] },
    })) grooming.storage.set(key, JSON.stringify(value));
    grooming.storage.set('groomingReviewedPricing', 'reviewed');
    grooming.context.initBookingConsentStep();
    await grooming.elements.get('bookingConsentForm').listeners.submit({ preventDefault() {} });
    assert.equal(groomingStep, 'schedule');
    assert.deepEqual(JSON.parse(grooming.storage.get('bookingSchedule')), { date: '2026-10-09' });
    assert.equal(grooming.storage.get('bookingScheduleArrivalError'), failure.message);
    for (const key of ['bookingPets', 'bookingStep3', 'bookingStep4Review', 'bookingConsentStep']) {
      assert.equal(grooming.storage.has(key), true, `preserve ${key}`);
    }
    assert.equal(JSON.parse(grooming.storage.get('bookingConsentStep')).signer_name, 'Customer Name');
    assert.deepEqual(JSON.parse(grooming.storage.get('bookingConsentStep')).signature_strokes, [[[10, 10], [30, 30]]]);
  }
  console.log('Preferred arrival windows: boundaries, restrictions, draft preservation, and expiry recovery passed.');
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
