const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const read = (path) => fs.readFileSync(`${__dirname}/../${path}`, 'utf8');
const script = (path) => read(path).replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, '').replace(/^export /gm, '');
const values = new Map();
const storage = {
  getItem: (key) => values.get(key) ?? null,
  setItem: (key, value) => values.set(key, value),
  removeItem: (key) => values.delete(key),
};
const save = (key, value) => storage.setItem(key, JSON.stringify(value));

async function main() {
  // Direct Grooming/Clinic entry uses the server's enforcement decision.
  for (const [enforced, required, allowed, blocked] of [
    [false, true, true, false], [true, true, true, true],
    [true, false, true, false], [false, false, false, true],
  ]) {
    let redirected = false;
    const guard = vm.createContext({ URLSearchParams, sessionStorage: storage, console,
      API: { getPreRegistrationAccess: async () => ({ allowed, services: { grooming: { allowed }, clinic: { allowed } }, phone_verification_required: required, sms_verification: { enforced } }) },
      window: { location: { search: '', replace() { redirected = true; } } },
    });
    vm.runInContext(script('scripts/services/booking-form-access-guard.js'), guard);
    assert.equal(await guard.enforceBookingFormAccessGuard(), blocked);
    assert.equal(redirected, blocked);
  }
  for (const active of [[], ['grooming'], ['clinic'], ['grooming', 'clinic']]) {
    for (const service of ['grooming', 'clinic']) {
      let redirected = false, restore;
      const guard = vm.createContext({ sessionStorage: storage, console,
        API: { getPreRegistrationAccess: async options => {
          assert.equal(options.force, true);
          return { allowed: active.length < 2, services: Object.fromEntries(['grooming', 'clinic'].map(name => [name, { allowed: !active.includes(name), message: `${name} blocked` }])) };
        } },
        window: { location: { replace() { redirected = true; } }, addEventListener(name, handler) { if (name === 'pageshow') restore = handler; } },
      });
      vm.runInContext(script('scripts/services/booking-form-access-guard.js'), guard);
      assert.equal(await guard.initBookingFormAccessGuard({ service }), active.includes(service));
      assert.equal(redirected, active.includes(service));
      redirected = false;
      await restore({ persisted: true });
      assert.equal(redirected, active.includes(service), 'Restoration preserves the selected service context');
    }
  }
  global.sessionStorage = storage;
  const draft = await import('../scripts/services/booking-draft-service.js');
  const grooming = await import('../scripts/services/grooming-service.js?v=grooming-pricing-20261006&grooming=actual-20261026&registration=fcfs-20261027-v1');
  const groomingAge = await import('../scripts/services/grooming-age.js');
  const catalogue = JSON.parse(read('tests/grooming-catalogue-fixture.json'));
  grooming.applyGroomingCatalogue(catalogue.data, catalogue.groomingPreferences);

  // Explicit draft cleanup remains scoped. Tab switching preserves drafts (selector regression).
  const groomingKeys = ['bookingSchedule', 'bookingScheduleArrivalError', 'bookingPets', 'bookingStep2',
    'bookingStep3', 'bookingStep4Review', 'bookingReview', 'bookingConsentStep', 'bookingConfirmation',
    'groomingSelectedPricing', 'groomingReviewedPricing'];
  for (const key of [...groomingKeys, 'clinicVisitDraft', 'clientPets']) save(key, { stale: true });
  draft.clearGroomingDraft();
  for (const key of groomingKeys) assert.equal(values.has(key), false, `Clear stale ${key}`);
  assert.equal(values.has('clinicVisitDraft'), true);
  assert.equal(values.has('clientPets'), true);

  const pet = { id: '101', petName: 'Mochi', petType: 'dog', size: 'small', weight: '5' };
  const selection = { petId: '101', servicePackage: 'regular_dog_grooming', groomingPreference: 'regular_trim', alaCarteServices: [] };
  const sections = ['pets', 'services', 'review', 'consent', 'confirmed'].map((step) => ({
    dataset: { groomingStep: step }, classList: { toggle() {} },
  }));
  let ready, popstate, calendarNext;
  const entered = [];
  const location = { href: 'http://localhost/pages/client/grooming-pre-registration.html', search: '' };
  const context = vm.createContext({
    console, URL, URLSearchParams, sessionStorage: storage, ...grooming, ...groomingAge,
    document: {
      body: { classList: { toggle() {} } }, querySelectorAll: () => sections,
      addEventListener(event, handler) { if (event === 'DOMContentLoaded') ready = handler; },
    },
    window: {
      location, history: { pushState() {}, replaceState() {} }, scrollTo() {}, setInterval() {},
      addEventListener(event, handler) { if (event === 'popstate') popstate = handler; },
    },
    initBookingFormAccessGuard: async () => false,
    loadPetGroomers: async () => [], hasPetGroomerSelection: () => true,
    initBookingPetStep: async () => entered.push('pets'), refreshBookingPetStep: async () => entered.push('pets'),
    initBookingServicesStep: () => entered.push('services'), refreshBookingServicesStep: () => entered.push('services'),
    initBookingCalendar: async ({ onNext }) => { entered.push('schedule'); calendarNext = onNext; },
    refreshBookingCalendar: async () => entered.push('schedule'),
    initBookingReviewStep: () => entered.push('review'), refreshBookingReviewStep: () => entered.push('review'),
    initBookingConsentStep: () => entered.push('consent'), initBookingConfirmedStep() {},
  });
  vm.runInContext(script('scripts/components/grooming-pre-registration.js'), context);
  await ready();
  assert.equal(entered.at(-1), 'pets', 'A new registration starts with Pet Information');
  await context.navigate('consent');
  assert.equal(entered.at(-1), 'pets');
  save('bookingPets', [{ ...pet, birthdate: '2999-06-16' }]);
  for (const step of ['services', 'schedule', 'review', 'consent']) {
    assert.equal(context.availableStep(step), 'pets', 'Deep links cannot bypass the grooming minimum age');
  }
  save('bookingPets', [pet]);
  save('bookingStep2', { pets: [pet], bookingDate: 'old date', bookingTime: 'old time' });
  await context.navigate('schedule');
  assert.equal(entered.at(-1), 'services', 'No arrival step before service selection');
  save('bookingStep3', { petSelections: [{ ...selection, petId: 'stale-pet' }] });
  assert.equal(context.availableStep('schedule'), 'services', 'Services must belong to the current pets');
  save('bookingStep3', { petSelections: [{ ...selection, groomingPreference: '' }] });
  assert.equal(context.availableStep('schedule'), 'services', 'Required grooming preference must be complete');
  save('bookingStep3', { petSelections: [selection] });
  context.hasPetGroomerSelection = () => false;
  assert.equal(context.availableStep('schedule'), 'services', 'Unavailable Pet Groomer blocks later steps');
  context.hasPetGroomerSelection = () => true;
  await context.navigate('schedule');
  assert.equal(entered.at(-1), 'review');
  await context.navigate('review');
  assert.equal(entered.at(-1), 'review', 'Services proceed directly to review without an arrival');
  assert.equal(draft.getBookingDraft().bookingDate, '', 'Old draft arrival dates are discarded');
  assert.equal(draft.getBookingDraft().bookingTime, '');
  save('bookingStep4Review', { pets: [pet] });
  await context.navigate('consent');
  assert.equal(entered.at(-1), 'consent');
  const activeDraft = [...values.entries()];
  for (const step of ['review', 'schedule', 'services', 'pets']) await context.navigate(step);
  location.search = '';
  await popstate();
  assert.equal(entered.at(-1), 'pets', 'Browser Back without a step resolves to pets');
  assert.deepEqual([...values.entries()], activeDraft, 'Back navigation preserves the current draft');

  // Reloading an arrival deep link loads the shared service rules before checking selections.
  let catalogueLoads = 0;
  location.search = '?step=schedule';
  const reloaded = vm.createContext({ ...context,
    loadGroomingCatalogue: async () => { catalogueLoads++; },
  });
  vm.runInContext(script('scripts/components/grooming-pre-registration.js'), reloaded);
  await ready();
  assert.equal(catalogueLoads, 1);
  assert.equal(entered.at(-1), 'review');
  assert.deepEqual([...values.entries()], activeDraft, 'Reloading preserves the current draft');

  // Clinic initialization sets Home navigation without changing either grooming entry point.
  const petSource = read('scripts/components/booking-pet-step.js');
  const petInitialization = petSource.slice(petSource.indexOf('function configurePageForFlow()'), petSource.indexOf('export async function refreshBookingPetStep()'))
    .replace(/^export /gm, '');
  for (const clinic of [false, true]) {
    const elements = {
      backLink: { href: './grooming-pre-registration.html' }, backLabel: { textContent: 'Back to Schedule' },
      pageTitle: {}, savedPetsInstruction: {}, selectedPetsTitle: {},
    };
    const initialization = vm.createContext({
      IS_CLINIC_VISIT: clinic, elements, document: { title: 'Pet Information' },
      initBookingFormAccessGuard: async () => false, requireCustomerSession: () => true, bindEvents() {}, initStepState: async () => {},
    });
    vm.runInContext(petInitialization, initialization);
    for (let attempt = 0; attempt < 2; attempt++) {
      await initialization.initBookingPetStep();
      assert.equal(elements.backLink.href, clinic ? './dashboard.html' : './grooming-pre-registration.html');
      assert.equal(elements.backLabel.textContent, clinic ? 'Back to Home' : 'Back to Schedule');
    }
    if (!clinic) {
      elements.backLink.href = './dashboard.html';
      elements.backLabel.textContent = 'Back to Home';
      await initialization.initBookingPetStep();
      assert.equal(elements.backLink.href, './dashboard.html', 'Single-page grooming keeps its Home link');
      assert.equal(elements.backLabel.textContent, 'Back to Home');
    }
  }
  // Exercise the real in-form handlers independently of the upper-left link.
  const handlers = petSource.slice(petSource.indexOf('function handleBack()'), petSource.indexOf('function setFieldError('));
  let target, saved = false, error;
  const petContext = vm.createContext({ IS_CLINIC_VISIT: false, isGroomingSinglePage: () => true,
    ...groomingAge, savedPetsLoading: false,
    getBookingSchedule: () => null, getBookingPets: () => [pet], getPetWeightSubmissionValidationMessage: () => '',
    showStepError: (message) => { error = message; }, clearStepError() {},
    saveStepTwoDraft: () => { saved = true; }, goToGroomingStep: (step) => { target = step; }, window: { location: {} },
  });
  vm.runInContext(handlers, petContext);
  petContext.handleNext();
  assert.equal(target, 'services');
  assert.equal(saved, true);
  assert.equal(error, undefined);
  petContext.handleBack();
  assert.equal(petContext.window.location.href, './pre-register.html');
  petContext.IS_CLINIC_VISIT = true;
  petContext.handleBack();
  assert.equal(petContext.window.location.href, './pre-register.html', 'Clinic Back returns to service choice');

  const page = read('pages/client/grooming-pre-registration.html');
  assert.deepEqual([...page.matchAll(/data-grooming-step="([^"]+)"/g)].map((match) => match[1]), sections.map((section) => section.dataset.groomingStep));
  for (let index = 0; index < 4; index++) {
    const section = page.split(`data-grooming-step="${sections[index].dataset.groomingStep}"`)[1].split('data-grooming-step="')[0];
    assert.ok(section.includes(`Step ${index + 1} of 4`));
    assert.ok(section.includes(`${(index + 1) * 25}% Complete`));
  }
  assert.match(read('scripts/components/booking-services-step.js'), /saveCurrentStepDraft\(\);\s+goToGroomingStep\("review"\)/);
  console.log('Grooming flow: step order, complete-selection guards, new-draft reset, no-picker draft recovery, Clinic Home initialization, and Back preservation passed.');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
