// Regression coverage for the retired arrival-picker submission contract.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function element() {
  return { listeners: {}, disabled: false, textContent: '', innerHTML: '',
    classList: { add() {}, remove() {} }, focus() {}, scrollIntoView() {},
    addEventListener(name, callback) { this.listeners[name] = callback; } };
}
function harness(file, bindings = {}) {
  const elements = new Map(), storage = new Map();
  const context = vm.createContext({ console, Intl, Date, URLSearchParams,
    document: { getElementById(id) { if (!elements.has(id)) elements.set(id, element()); return elements.get(id); } },
    window: { location: { href: '', replace(value) { this.href = value; } } },
    sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
    renderRegistrationQueueInformation: async () => ({ registration_available: true }),
    readReviewedServiceDay: () => '2026-10-26', registrationDayNotice: () => false, ...bindings,
  });
  const source = fs.readFileSync(`${__dirname}/../scripts/components/${file}`, 'utf8')
    .replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, '').replace(/^export /gm, '');
  const ready = vm.runInContext(source, context, { filename: file });
  return { context, elements, storage, ready };
}
async function main() {
  const clinicDraft = { pet: { id: 101, petName: 'Mochi' }, commonConcerns: ['Vomiting'], chiefComplaint: 'Started yesterday' };
  let clinicPayload;
  const bindings = { CLINIC_VISIT_DRAFT_KEY: 'clinicVisitDraft', CLINIC_VISIT_PETS_KEY: 'clinicVisitPets', CLINIC_VISIT_CONFIRMATION_KEY: 'clinicVisitConfirmation',
    requireCustomerSession: () => true, readClinicVisitDraft: () => clinicDraft,
    validateClinicVisitReason: () => '', formatClinicVisitReason: () => 'Vomiting', getClinicVisitSummaryMarkup: () => '<p>Mochi</p>',
    API: { submitClinicPreRegistration: async payload => { clinicPayload = payload; return { appointment: { appointment_reference: 'CL-1', queue_number: null, created_at: '2026-10-26T09:00:00+08:00' } }; } },
  };
  const clinic = harness('clinic-visit-summary.js', bindings);
  await clinic.ready;
  await clinic.elements.get('submitClinicVisitBtn').listeners.click();
  assert.equal(clinicPayload.pet_id, 101);
  assert.equal(clinicPayload.service_day, '2026-10-26');
  assert.equal(clinicPayload.appointment_date, undefined);
  assert.equal(clinicPayload.window_id, undefined);
  assert.equal(clinic.context.window.location.href, './clinic-visit-confirmed.html');
  assert.equal(JSON.parse(clinic.storage.get('clinicVisitConfirmation')).queue_number, null);
  const failed = harness('clinic-visit-summary.js', { ...bindings, API: { submitClinicPreRegistration: async () => { throw { message: 'Try again' }; } } });
  await failed.ready;
  await failed.elements.get('submitClinicVisitBtn').listeners.click();
  assert.equal(failed.context.window.location.href, '');
  assert.equal(failed.elements.get('submitClinicVisitBtn').disabled, false);
  assert.equal(failed.elements.get('clinicVisitSubmitStatus').textContent, 'Try again');

  let reviewedDay, availabilityLoads = 0, clinicAttempts = 0;
  const rollover = harness('clinic-visit-summary.js', { ...bindings,
    readReviewedServiceDay: () => reviewedDay,
    registrationDayNotice: error => error.code === 'service_day_changed',
    renderRegistrationQueueInformation: async () => { reviewedDay = ++availabilityLoads === 1 ? '2026-10-26' : '2026-10-27'; return { registration_available: true }; },
    API: { submitClinicPreRegistration: async payload => {
      if (++clinicAttempts === 1) { assert.equal(payload.service_day, '2026-10-26'); throw { code: 'service_day_changed', message: 'Review the new date.' }; }
      assert.equal(payload.service_day, '2026-10-27');
      return { appointment: { appointment_date: '2026-10-27' } };
    } },
  });
  rollover.storage.set('clinicVisitDraft', JSON.stringify(clinicDraft));
  await rollover.ready;
  await rollover.elements.get('submitClinicVisitBtn').listeners.click();
  assert.equal(clinicAttempts, 1, 'A date change never triggers automatic resubmission');
  assert.equal(rollover.context.window.location.href, '');
  assert.equal(JSON.parse(rollover.storage.get('clinicVisitDraft')).chiefComplaint, 'Started yesterday');
  assert.equal(rollover.elements.get('submitClinicVisitBtn').disabled, false);
  await rollover.elements.get('submitClinicVisitBtn').listeners.click();
  assert.equal(clinicAttempts, 2, 'The customer must explicitly submit after reviewing the updated day');

  global.sessionStorage = clinic.context.sessionStorage;
  const service = await import('../scripts/services/clinic-visit-service.js');
  clinic.storage.set('clinicVisitDraft', JSON.stringify({ ...clinicDraft, appointmentDate: '2026-10-01', windowId: 1, windowLabel: 'Old time' }));
  const restored = service.readClinicVisitDraft();
  assert.equal(restored.pet.id, 101);
  assert.deepEqual(restored.commonConcerns, ['Vomiting']);
  for (const key of ['appointmentDate', 'windowId', 'windowLabel']) assert.equal(restored[key], undefined);

  const weightTools = await import('../scripts/components/pet-weight-size.js');
  const ageTools = await import('../scripts/services/grooming-age.js');
  let groomingPayload, step;
  const grooming = harness('booking-consent-step.js', { ...weightTools, ...ageTools,
    initGroomingConsent: () => ({ valid: true, prefill() {}, validate() {}, setBusy() {}, confirmSubmission: async () => true,
      payload: () => ({ terms_agreed: true, sedation_consent: false, signer_name: 'Customer', signature_strokes: [[[10, 10], [30, 30]]] }) }),
    loadGroomingCatalogue: async () => {}, getBookingDraft: () => ({}), normalizeStepThreeDraft: () => [], selectedPricingSignature: () => 'reviewed',
    goToGroomingStep: value => { step = value; },
    API: { getMe: async () => ({ user: {} }), storeBooking: async payload => { groomingPayload = payload; return { booking: { booking_id: 1, booking_reference: 'BAC-1', booking_date: '2026-10-26', created_at: '2026-10-26T09:00:00+08:00', status: 'waiting_to_arrive', number_of_pets: 1 } }; } },
  });
  for (const [key, value] of Object.entries({ bookingPets: [{ id: 101, petName: 'Mochi', petType: 'cat' }], bookingStep3: { petSelections: [{ petId: 101, servicePackage: 'cat_full_grooming' }] }, bookingStep4Review: { pets: [{ petId: 101, specialInstructions: 'Keep these notes' }] } })) grooming.storage.set(key, JSON.stringify(value));
  grooming.storage.set('groomingReviewedPricing', 'reviewed');
  grooming.context.initBookingConsentStep();
  await grooming.elements.get('bookingConsentForm').listeners.submit({ preventDefault() {} });
  assert.equal(step, 'confirmed');
  assert.equal(groomingPayload.service_day, '2026-10-26');
  assert.equal(groomingPayload.booking_date, undefined);
  assert.equal(groomingPayload.window_id, undefined);
  assert.equal(groomingPayload.pets[0].special_instructions, 'Keep these notes');
  assert.equal(groomingPayload.pets[0].services.package, 'cat_full_grooming');
  assert.equal(JSON.parse(grooming.storage.get('bookingConfirmation')).submitted_at, '2026-10-26T09:00:00+08:00');
  grooming.storage.delete('bookingConfirmation');
  grooming.context.registrationDayNotice = error => error.code === 'service_day_changed';
  grooming.context.API.storeBooking = async () => { throw { code: 'service_day_changed', message: 'Review tomorrow.' }; };
  await grooming.elements.get('bookingConsentForm').listeners.submit({ preventDefault() {} });
  assert.equal(step, 'review');
  assert.equal(JSON.parse(grooming.storage.get('bookingStep4Review')).pets[0].specialInstructions, 'Keep these notes');
  assert.equal(grooming.storage.has('bookingConfirmation'), false);

  const queue = await import('../scripts/components/registration-queue-information.js');
  let requests = 0;
  global.API = { getBaseUrl: () => 'https://clinic.example/api' };
  global.fetch = async url => { requests++; assert.match(url, /^https:\/\/clinic\.example\/api\/pre-registration\/queue-info\?service=grooming$/); return { ok: true, json: async () => ({ registration_available: true, waiting_count: 7, accepting_check_ins: true, service_day: '2026-10-26' }) }; };
  const root = { innerHTML: '' };
  await queue.renderRegistrationQueueInformation(root, 'grooming');
  assert.match(root.innerHTML, />7</);
  assert.match(root.innerHTML, /Not assigned/);
  assert.doesNotMatch(root.innerHTML, /#8|Estimated queue/);
  assert.equal(requests, 1);
  global.fetch = async () => ({ ok: true, json: async () => ({ registration_available: true, waiting_count: null, accepting_check_ins: false, service_day: '2026-10-27' }) });
  await queue.renderRegistrationQueueInformation(root, 'clinic');
  assert.match(root.innerHTML, /Currently unavailable/);
  assert.match(root.innerHTML, /Visit day/);
  assert.equal((root.innerHTML.match(/Queue numbers are assigned at check-in\./g) || []).length, 1);
  assert.doesNotMatch(root.innerHTML, /available during service hours|Received/);
  global.fetch = async () => { throw new Error('offline'); };
  await queue.renderRegistrationQueueInformation(root, 'clinic');
  assert.match(root.innerHTML, /Currently unavailable/);
  console.log('No-picker submissions, legacy draft recovery, server timestamps, service queue information and neutral failure states passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
