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
  const grooming = await import('../scripts/services/grooming-service.js?v=grooming-pricing-20261006&grooming=cms-20261105&registration=fcfs-20261027-v1');
  const groomingAge = await import('../scripts/services/grooming-age.js');
  const { formatSavedWeight } = await import('../scripts/components/pet-weight-size.js');
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
  let ready, popstate, pageshow;
  let latestCatalogue = catalogue.data, catalogueFailed = false, availability;
  const entered = [];
  const location = { href: 'http://localhost/account/registration/grooming', search: '' };
  const context = vm.createContext({
    console, URL, URLSearchParams, sessionStorage: storage, ...grooming, ...groomingAge,
    document: {
      body: { classList: { toggle() {} } }, querySelectorAll: () => sections,
      addEventListener(event, handler) { if (event === 'DOMContentLoaded') ready = handler; },
    },
    window: {
      location, history: { pushState() {}, replaceState() {} }, scrollTo() {}, setInterval() {},
      addEventListener(event, handler) {
        if (event === 'popstate') popstate = handler;
        if (event === 'pageshow') pageshow = handler;
      },
    },
    initBookingFormAccessGuard: async () => false,
    loadGroomingCatalogue: async () => {
      if (catalogueFailed) throw new Error('Offline');
      grooming.applyGroomingCatalogue(latestCatalogue, catalogue.groomingPreferences);
    },
    loadPetGroomers: async () => [], hasPetGroomerSelection: () => true,
    initBookingPetStep: async () => entered.push('pets'), refreshBookingPetStep: async status => { availability = status; entered.push('pets'); },
    initBookingServicesStep: () => entered.push('services'), refreshBookingServicesStep: status => { availability = status; entered.push('services'); },
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
  assert.equal(entered.at(-1), 'review', 'A restored Review must have a matching pricing signature');
  storage.setItem('groomingReviewedPricing', grooming.selectedPricingSignature({ pets: [pet] }, [selection]));
  await context.navigate('consent');
  assert.equal(entered.at(-1), 'consent');
  const activeDraft = [...values.entries()];
  for (const step of ['review', 'schedule', 'services', 'pets']) await context.navigate(step);
  location.search = '';
  await popstate();
  assert.equal(entered.at(-1), 'pets', 'Browser Back without a step resolves to pets');
  assert.deepEqual([...values.entries()], activeDraft, 'Back navigation preserves the current draft');

  // Reloading an arrival deep link loads the shared service rules before checking selections.
  const restoreFromCache = pageshow;
  const changeHistoryEntry = popstate;
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

  const unavailableSmall = service => ({ ...service, priceOptions: service.priceOptions.filter(option => option.sizeKey !== 'small') });
  const regular = catalogue.data.find(service => service.id === selection.servicePackage);
  latestCatalogue = catalogue.data.map(service => service === regular ? unavailableSmall(service) : service);
  await context.navigate('services');
  assert.equal(entered.at(-1), 'services', 'A compatible alternate package keeps the pet eligible');
  await context.navigate('review');
  assert.equal(entered.at(-1), 'services', 'Review redirects after a saved package loses its matching price');
  location.search = '?step=consent';
  await changeHistoryEntry();
  assert.equal(entered.at(-1), 'services', 'Browser Forward checks the latest package size');
  latestCatalogue = catalogue.data.map(service => service.kind === 'package' && service.petType !== 'cat' ? unavailableSmall(service) : service);
  await restoreFromCache({ persisted: true });
  assert.equal(entered.at(-1), 'pets', 'Restoring from browser cache checks all applicable packages');
  assert.equal(JSON.parse(storage.getItem('bookingPets'))[0].id, pet.id);
  catalogueFailed = true;
  await context.navigate('services');
  assert.equal(entered.at(-1), 'pets');
  assert.equal(availability.catalogueReady, false);
  assert.equal(availability.error, grooming.GROOMING_AVAILABILITY_ERROR);
  catalogueFailed = false;
  latestCatalogue = catalogue.data;
  await context.navigate('services');
  assert.equal(entered.at(-1), 'services', 'A fresh catalogue can restore progression');

  // Clinic initialization sets Home navigation without changing either grooming entry point.
  const petSource = read('scripts/components/booking-pet-step.js');
  const petInitialization = petSource.slice(petSource.indexOf('function configurePageForFlow()'), petSource.indexOf('function syncGroomingAvailability('))
    .replace(/^export /gm, '');
  for (const clinic of [false, true]) {
    const elements = {
      backLink: { href: '/account/registration/grooming' }, backLabel: { textContent: 'Back to Schedule' },
      pageTitle: {}, savedPetsInstruction: {}, selectedPetsTitle: {},
    };
    const initialization = vm.createContext({
      IS_CLINIC_VISIT: clinic, elements, document: { title: 'Pet Information' },
      initBookingFormAccessGuard: async () => false, requireCustomerSession: () => true, bindEvents() {}, initStepState: async () => {},
    });
    vm.runInContext(petInitialization, initialization);
    for (let attempt = 0; attempt < 2; attempt++) {
      await initialization.initBookingPetStep();
      assert.equal(elements.backLink.href, clinic ? '/account' : '/account/registration/grooming');
      assert.equal(elements.backLabel.textContent, clinic ? 'Back to Home' : 'Back to Schedule');
    }
    if (!clinic) {
      elements.backLink.href = '/account';
      elements.backLabel.textContent = 'Back to Home';
      await initialization.initBookingPetStep();
      assert.equal(elements.backLink.href, '/account', 'Single-page grooming keeps its Home link');
      assert.equal(elements.backLabel.textContent, 'Back to Home');
    }
  }
  // Exercise the real in-form handlers independently of the upper-left link.
  const handlers = petSource.slice(petSource.indexOf('function handleBack()'), petSource.indexOf('function setFieldError('));
  let target, saved = false, error;
  const petContext = vm.createContext({ IS_CLINIC_VISIT: false, isGroomingSinglePage: () => true,
    ...groomingAge, ...grooming, groomingCatalogueStatus: 'ready', savedPetsLoading: false, petAvailabilityFailed: false, petSessionRestriction: () => "",
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
  assert.equal(petContext.window.location.href, '/account/registration');
  petContext.IS_CLINIC_VISIT = true;
  petContext.handleBack();
  assert.equal(petContext.window.location.href, '/account/registration', 'Clinic Back returns to service choice');

  const node = () => ({
    classList: { add() {}, remove() {}, toggle() {} },
    textContent: '', innerHTML: '', setAttribute(name, value) { this[name] = value; },
    appendChild(child) { this.innerHTML += child.innerHTML; }, append() {},
    addEventListener(name, callback) { this[name] = callback; }, closest() { return null; }, focus() {},
  });
  const petElements = Object.fromEntries([
    'petStepError', 'retryGroomingAvailability', 'groomingAvailabilityStatus', 'size', 'sizeError',
    'petType', 'selectedPetCards', 'selectedPetCount', 'selectedPetsEmptyState', 'removeAllSelectedPetsBtn', 'nextBtn',
  ].map(key => [key, node()]));
  petElements.petType.value = 'dog';
  const buddy = { ...pet, id: '102', petName: 'Buddy', size: 'XL' };
  const juno = { ...pet, id: '103', petName: 'Juno', size: 'large' };
  let selectedPets = [pet, buddy, juno], newSize = 'extra_large', removeBuddy, nextStep;
  const fields = vm.createContext({
    ...grooming, ...groomingAge, ...draft, elements: petElements,
    IS_CLINIC_VISIT: false, MAX_PETS_PER_BOOKING: 10, activePetSection: 'existing',
    groomingCatalogueStatus: 'ready', stepError: '', petAvailabilityFailed: false, savedPetsLoading: false,
    petSessionRestriction: () => '', getBookingPets: () => selectedPets, saveBookingPets: pets => { selectedPets = pets; },
    removePetFromBooking: id => { selectedPets = selectedPets.filter(item => item.id !== id); },
    saveStepTwoDraft() {}, showPetSelectionSection() {}, renderExistingPets() {}, renderBodyConditionChip: () => '',
    isGroomingSinglePage: () => true, goToGroomingStep: step => { nextStep = step; },
    birthdateField: { validate: () => true }, validatePetForm: () => '',
    getFormValues: () => ({ petType: 'dog', size: newSize }),
    petSaveConfirmation: { reset() {}, confirm: () => true, setSaving() {} },
    sizeCombobox: { getValue: () => newSize },
    document: {
      createElement: node, querySelectorAll: () => [{
        dataset: { removePetId: buddy.id }, addEventListener(_name, callback) { removeBuddy = callback; },
      }],
    },
  });
  const slice = (start, end) => petSource.slice(petSource.indexOf(start), petSource.indexOf(end));
  vm.runInContext(slice('function showStepError', 'function buildStepTwoDraft')
    + slice('function showExistingPetSection', 'function formatWeightLabel')
    + slice('function renderSelectedPets', 'function getFormValues')
    + slice('async function handleAddPetSubmit', 'function handleBack')
    + handlers + slice('function setFieldError', 'function bindEvents'), fields);
  const onlySmall = catalogue.data.map(service => service.kind === 'package' && service.petType === 'dog'
    ? { ...service, priceOptions: service.priceOptions.filter(option => option.sizeKey === 'small') } : service);
  grooming.applyGroomingCatalogue(onlySmall, catalogue.groomingPreferences);
  fields.renderSelectedPets();
  assert.match(petElements.selectedPetCards.innerHTML, /Extra Large pets\. Remove Buddy/);
  assert.match(petElements.selectedPetCards.innerHTML, /Large pets\. Remove Juno/);
  assert.equal(petElements.nextBtn.disabled, true);
  fields.handleNext();
  assert.equal(nextStep, undefined);
  fields.showAddPetSection();
  fields.validateGroomingFormSize();
  assert.equal(petElements.sizeError.textContent, 'Grooming is currently unavailable for Extra Large pets.');
  assert.equal(petElements.size['aria-invalid'], 'true');
  fields.showExistingPetSection();
  assert.match(petElements.petStepError.textContent, /highlighted pets/);
  newSize = 'small';
  fields.validateGroomingFormSize();
  assert.equal(petElements.sizeError.textContent, '');
  assert.equal(petElements.size['aria-invalid'], 'false');
  removeBuddy();
  assert.doesNotMatch(petElements.selectedPetCards.innerHTML, /Buddy/);
  assert.equal(petElements.nextBtn.disabled, true, 'Juno still needs a correction');
  grooming.applyGroomingCatalogue(catalogue.data, catalogue.groomingPreferences);
  fields.applyGroomingAvailability(true);
  assert.equal(petElements.nextBtn.disabled, false);
  assert.equal(petElements.petStepError.textContent, '');
  fields.handleNext();
  assert.equal(nextStep, 'services');
  grooming.applyGroomingCatalogue(onlySmall, catalogue.groomingPreferences);
  fields.applyGroomingAvailability(true);
  fields.handleRemoveAllSelectedPets();
  assert.equal(petElements.petStepError.textContent, '');
  assert.equal(petElements.nextBtn.disabled, true);
  fields.applyGroomingAvailability(false);
  assert.equal(petElements.petStepError.textContent, grooming.GROOMING_AVAILABILITY_ERROR);
  assert.equal(petElements.retryGroomingAvailability.hidden, false);
  grooming.applyGroomingCatalogue(catalogue.data, catalogue.groomingPreferences);
  fields.applyGroomingAvailability(true);
  newSize = 'extra_large';
  let profileSaves = 0;
  fields.API = { getCustomerToken: () => true, addPet: () => { profileSaves++; } };
  fields.loadGroomingCatalogue = async () => grooming.applyGroomingCatalogue(onlySmall, catalogue.groomingPreferences);
  await fields.handleAddPetSubmit({ preventDefault() {} });
  assert.equal(profileSaves, 0, 'A catalogue change before Confirm prevents adding the new pet');
  assert.equal(petElements.sizeError.textContent, 'Grooming is currently unavailable for Extra Large pets.');

  // Exercise actual Review rendering using the same normalized selections as navigation.
  const reviewElements = new Map();
  const reviewNode = id => {
    if (!reviewElements.has(id)) reviewElements.set(id, node());
    return reviewElements.get(id);
  };
  let reviewStep;
  const reviewContext = vm.createContext({
    ...draft, ...grooming, formatSavedWeight, sessionStorage: storage, document: { getElementById: reviewNode, createElement: node },
    hasPetGroomerSelection: () => true, petGroomerLabel: () => 'Any available groomer',
    renderRegistrationQueueInformation: async () => ({ registration_available: true }),
    renderBodyConditionChip: () => '', renderGroomingPreference: () => '', goToGroomingStep: step => { reviewStep = step; },
  });
  vm.runInContext(script('scripts/components/booking-review-step.js'), reviewContext);
  grooming.applyGroomingCatalogue(catalogue.data, catalogue.groomingPreferences);
  await reviewContext.initBookingReviewStep();
  assert.match(reviewNode('reviewSelections').innerHTML, /service-card__price-size">Small<\/span>/);
  assert.doesNotMatch(reviewNode('reviewSelections').innerHTML, /service-card__price-size">Medium<\/span>/);
  assert.equal(reviewNode('confirmBookingBtn').disabled, false);
  grooming.applyGroomingCatalogue(catalogue.data.map(service => service === regular ? unavailableSmall(service) : service), catalogue.groomingPreferences);
  await reviewContext.refreshBookingReviewStep();
  assert.match(reviewNode('reviewSelections').innerHTML, /Regular Dog Grooming is no longer available for Small pets.*Mochi/);
  assert.doesNotMatch(reviewNode('reviewSelections').innerHTML, /Size &amp; Price/);
  reviewNode('confirmBookingBtn').click();
  assert.equal(reviewStep, undefined);
  assert.equal(reviewNode('confirmBookingBtn').disabled, true);
  save('bookingStep3', { petSelections: [{ ...selection, servicePackage: '', groomingPreference: '', alaCarteServices: ['nail_clipping'] }] });
  await reviewContext.refreshBookingReviewStep();
  assert.match(reviewNode('reviewSelections').innerHTML, /Nail Clipping/);
  assert.equal(reviewNode('confirmBookingBtn').disabled, false, 'Eligible pets can review an à la carte-only registration');
  save('bookingPets', [{ ...pet, size: '' }]);
  save('bookingStep3', { petSelections: [selection] });
  await reviewContext.refreshBookingReviewStep();
  assert.match(reviewNode('reviewSelections').innerHTML, /service-card__price-size">Medium<\/span>/);
  assert.match(reviewNode('reviewSelections').innerHTML, /service-card__price-size">Extra Large<\/span>/);
  assert.equal(reviewNode('confirmBookingBtn').disabled, false, 'Optional sizes retain the clinic-confirmed package price display');

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
