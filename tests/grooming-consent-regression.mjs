import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { initGroomingConsent } from '../scripts/components/grooming-consent.js';
import { isEligibleForGrooming } from '../scripts/services/grooming-age.js';

const terms = JSON.parse(fs.readFileSync(new URL('../scripts/data/grooming-consent.json', import.meta.url)));
const values = new Map();
globalThis.sessionStorage = {
  getItem: key => values.get(key) ?? null,
  setItem: (key, value) => values.set(key, value),
};
globalThis.fetch = async () => ({ ok: true, json: async () => terms });

class Element {
  value = ''; checked = false; disabled = false; hidden = false; style = {}; children = []; listeners = {};
  classList = { add() {}, remove() {} };
  addEventListener(type, handler, options) {
    const listener = options?.once ? event => {
      this.listeners[type] = this.listeners[type].filter(item => item !== listener);
      return handler(event);
    } : handler;
    (this.listeners[type] ??= []).push(listener);
  }
  async emit(type, event = {}) { for (const handler of this.listeners[type] || []) await handler({ preventDefault() {}, ...event }); }
  setAttribute(name, value) { this[name] = value; }
  append(...children) { this.children.push(...children); }
  focus() { this.focused = true; } scrollIntoView() {}
  showModal() { this.open = true; this.openCount = (this.openCount || 0) + 1; }
  close(value = '') { this.open = false; this.returnValue = value; return this.emit('close'); }
  getContext() { return { clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, stroke() {} }; }
  getBoundingClientRect() { return { left: 20, top: 10, width: 450, height: 120 }; }
  setPointerCapture(id) { this.capture = id; }
  hasPointerCapture(id) { return this.capture === id; }
  releasePointerCapture() { this.capture = null; }
}

function setup() {
  const nodes = new Map();
  const get = id => { if (!nodes.has(id)) nodes.set(id, new Element()); return nodes.get(id); };
  const host = get('groomingConsentFields');
  host.querySelector = selector => get(selector === 'canvas' ? 'customerSignaturePad' : selector.replace(/^#/, ''));
  globalThis.document = { getElementById: get, createElement: () => new Element() };
  return { get, nodes };
}

async function draw(get, type = 'mouse', id = 1) {
  const canvas = get('customerSignaturePad');
  await canvas.emit('pointerdown', { pointerType: type, pointerId: id, button: 0, clientX: 50, clientY: 60 });
  await canvas.emit('pointermove', { pointerType: type, pointerId: id, clientX: 90, clientY: 30 });
  await canvas.emit('pointermove', { pointerType: type, pointerId: id, clientX: 120, clientY: 60 });
  await canvas.emit('pointerup', { pointerType: type, pointerId: id });
}

for (const pointerType of ['mouse', 'touch', 'pen']) {
  values.clear();
  const { get } = setup();
  let enabled;
  const consent = initGroomingConsent({ storageKey: 'draft', signerName: 'Ana Cruz', onChange: valid => { enabled = valid; } });
  await consent.ready;
  assert.equal(enabled, false);
  for (const id of ['groomingConsentError', 'sedationConsentError', 'customerSignatureError', 'consentSignerError']) {
    assert.equal(get(id).hidden, true, `${id} is hidden on load`);
  }
  assert.equal(get('sedationConsentYes').checked, false);
  assert.equal(get('sedationConsentNo').checked, false);
  assert.equal(get('[data-consent-sedation]').textContent, terms.sedation_paragraph);
  assert.deepEqual(get('[data-consent-conditions]').children.map(item => item.children[1].textContent), terms.grooming_conditions);
  get('mainConsentCheckbox').checked = true;
  await get('mainConsentCheckbox').emit('change');
  assert.equal(enabled, false, 'Agreement and a typed name alone never enable submission');
  assert.equal(get('customerSignatureError').hidden, true, 'Agreement interaction does not show untouched signature errors');
  await draw(get, pointerType);
  assert.equal(enabled, false, 'An explicit sedation choice is required');
  assert.equal(consent.payload().sedation_consent, null);
  assert.equal(get('sedationConsentError').hidden, true);
  assert.equal(await consent.confirmSubmission(), false);
  assert.equal(get('sedationConsentError').hidden, false, 'Attempting submission shows the missing choice');
  get('sedationConsentYes').checked = true;
  await get('sedationConsentYes').emit('change');
  assert.equal(enabled, true, `${pointerType} draws a valid signature`);
  assert.equal(get('customerSignaturePad').style.touchAction, 'none');
  assert.deepEqual(consent.payload().signature_strokes, [[[60, 150], [140, 60], [200, 150]]]);
  assert.equal(consent.payload().sedation_consent, true);
  assert.equal(get('[data-sedation-summary]').textContent, 'Authorized');
  assert.equal(await consent.confirmSubmission(), true);
  assert.equal(get('sedationDeclineDialog').openCount, undefined, 'Yes never needs a decline confirmation');
  get('consentSignerName').value = '  ';
  await get('consentSignerName').emit('input');
  assert.equal(enabled, false);
  assert.equal(get('consentSignerError').hidden, false);
  get('consentSignerName').value = 'Customer Representative';
  await get('consentSignerName').emit('input');
  consent.prefill('Account Owner');
  assert.equal(consent.payload().signer_name, 'Customer Representative', 'Prefill never replaces the signer’s edit');
  consent.setBusy(true);
  assert.equal(enabled, false);
  assert.equal(consent.valid, false);
  consent.setBusy(false);
  assert.equal(enabled, true);
  await get('clearCustomerSignature').emit('click');
  assert.equal(enabled, false);
  assert.deepEqual(consent.payload().signature_strokes, []);
  const canvas = get('customerSignaturePad');
  await canvas.emit('pointerdown', { pointerId: 2, button: 0, clientX: 60, clientY: 50 });
  await canvas.emit('pointerup', { pointerId: 2 });
  assert.equal(consent.valid, false, 'A tap cannot sign');
  await draw(get, pointerType);
  const restored = initGroomingConsent({ storageKey: 'draft' });
  await restored.ready;
  assert.equal(restored.valid, true, 'A current draft restores its drawn signature');
}

values.clear();
values.set('draft', JSON.stringify({ digitalSignature: 'Typed Only', groomingAgreementAccepted: true }));
setup();
const oldDraft = initGroomingConsent({ storageKey: 'draft' });
await oldDraft.ready;
assert.equal(oldDraft.valid, false, 'Old typed signatures are never treated as drawn signatures');

for (const previousCheckbox of [false, true]) {
  values.set('draft', JSON.stringify({ consent_version: terms.version, sedation_consent: previousCheckbox }));
  setup();
  const legacy = initGroomingConsent({ storageKey: 'draft' });
  await legacy.ready;
  assert.equal(legacy.payload().sedation_consent, null, 'Checkbox drafts require an intentional new radio decision');
}

values.clear();
{
  const { get } = setup();
  const consent = initGroomingConsent({ storageKey: 'draft', signerName: 'Ana Cruz' });
  await consent.ready;
  get('mainConsentCheckbox').checked = true;
  await get('mainConsentCheckbox').emit('change');
  await draw(get);
  const select = async yes => {
    get('sedationConsentYes').checked = yes;
    get('sedationConsentNo').checked = !yes;
    await get(yes ? 'sedationConsentYes' : 'sedationConsentNo').emit('change');
  };
  await select(false);
  assert.equal(get('[data-sedation-summary]').textContent, 'Not authorized');
  const dialog = get('sedationDeclineDialog');
  let pending = consent.confirmSubmission();
  assert.equal(dialog.open, true);
  assert.equal(await consent.confirmSubmission(), false, 'Repeated submit cannot open another confirmation');
  await get('reviewSedationChoice').emit('click');
  assert.equal(await pending, false);
  assert.equal(get('sedationConsentNo').focused, true);
  pending = consent.confirmSubmission();
  await dialog.close(); // Escape/cancel does not accept the decision.
  assert.equal(await pending, false);
  pending = consent.confirmSubmission();
  await get('confirmSedationDecline').emit('click');
  assert.equal(await pending, true);
  const count = dialog.openCount;
  assert.equal(await consent.confirmSubmission(), true, 'Retry keeps the acknowledged No');
  assert.equal(dialog.openCount, count);
  const restored = initGroomingConsent({ storageKey: 'draft' });
  await restored.ready;
  assert.equal(await restored.confirmSubmission(), true, 'Returning to a current No draft keeps its confirmation');
  assert.equal(dialog.openCount, count);
  await select(true);
  await select(false);
  pending = consent.confirmSubmission();
  assert.equal(dialog.openCount, count + 1, 'Changing away from No resets its confirmation');
  await get('confirmSedationDecline').emit('click');
  assert.equal(await pending, true);
}

function source(name) {
  return fs.readFileSync(new URL(`../scripts/components/${name}.js`, import.meta.url), 'utf8')
    .replace(/^import .*;\r?\n/gm, '').replace(/^export /gm, '');
}

for (const walkIn of [false, true]) {
  for (const sedation of [false, true]) {
    values.clear();
    const { get } = setup();
    const save = (key, value) => sessionStorage.setItem(key, JSON.stringify(value));
    save('bookingSchedule', { date: '2026-10-16', window_id: 1 });
    save('bookingPets', [{ id: 1, petName: 'Coco', petType: 'cat', size: 'Small', weight: 4 }]);
    save('walkInOwnerStep', { firstName: 'Ana', lastName: 'Cruz', phone: '09171234567' });
    save('walkInReviewStep', { items: [{ pet: { petName: 'Coco', petType: 'cat', size: 'Small' }, selection: {}, pricing: { lineItems: [{ serviceId: 'partial_grooming' }] } }] });
    let submitted, navigated, ready, submissions = 0;
    const api = {
      waitForSessionVerification: async () => true,
      getMe: async () => ({ user: { first_name: 'Ana', last_name: 'Cruz' } }),
      storeBooking: async payload => { submissions++; submitted = payload; return { booking: { booking_id: 1, pets: [] }, consent: { signer_name: payload.signer_name } }; },
      submitWalkIn: async payload => { submissions++; submitted = payload; return { booking_id: 1, consent: { signer_name: payload.signer_name } }; },
    };
    const doc = { ...document, addEventListener: (name, handler) => { ready = handler; } };
    const context = vm.createContext({
      readReviewedServiceDay: () => '2026-10-16', registrationDayNotice: () => false, document: doc, API: api, sessionStorage, console, Date,
      window: { location: { href: '' } }, crypto: globalThis.crypto,
      initGroomingConsent, getPetWeightSubmission: () => ({ weight: 4 }), getPetWeightSubmissionValidationMessage: () => '',
      loadGroomingCatalogue: async () => {}, getBookingDraft: () => ({}), normalizeStepThreeDraft: () => [],
      selectedPricingSignature: () => null, goToGroomingStep: step => { navigated = step; },
      isEligibleForGrooming,
    });
    vm.runInContext(source(walkIn ? 'walk-in-consent-step' : 'booking-consent-step'), context);
    if (walkIn) await ready(); else context.initBookingConsentStep();
    await new Promise(resolve => setImmediate(resolve));
    const form = get(walkIn ? 'walkInConsentForm' : 'bookingConsentForm');
    get('mainConsentCheckbox').checked = true;
    await get('mainConsentCheckbox').emit('change');
    get(sedation ? 'sedationConsentYes' : 'sedationConsentNo').checked = true;
    await get(sedation ? 'sedationConsentYes' : 'sedationConsentNo').emit('change');
    await form.emit('submit');
    assert.equal(submitted, undefined);
    await draw(get);
    get('consentSignerName').value = '';
    await get('consentSignerName').emit('input');
    assert.equal(get('submitBookingButton').disabled, true);
    await form.emit('submit');
    assert.equal(submitted, undefined);
    get('consentSignerName').value = 'Customer Representative';
    await get('consentSignerName').emit('input');
    assert.equal(get('submitBookingButton').disabled, false);
    if (!walkIn) {
      const originalPets = sessionStorage.getItem('bookingPets');
      save('bookingPets', [{ id: 1, birthdate: '2999-06-16' }]);
      await form.emit('submit');
      assert.equal(submitted, undefined, 'Submission rechecks stale underage selections');
      assert.equal(navigated, 'pets');
      sessionStorage.setItem('bookingPets', originalPets);
    }
    const submitting = form.emit('submit');
    const duplicate = form.emit('submit');
    if (!sedation) {
      assert.equal(get('sedationDeclineDialog').open, true);
      assert.equal(submitted, undefined, 'Declined consent pauses the API submission');
      assert.match(get('sedationDeclineMessage').textContent, walkIn ? /customer has chosen/ : /You have chosen/);
      await get('confirmSedationDecline').emit('click');
    }
    await submitting;
    await duplicate;
    assert.equal(submissions, 1, 'Repeated submits send the registration only once');
    assert.equal(submitted.terms_agreed, true);
    assert.equal(submitted.sedation_consent, sedation);
    assert.equal(submitted.signer_name, 'Customer Representative');
    assert.equal(submitted.signature_strokes.length, 1);
    assert.equal(submitted.consent_version, terms.version);
    if (walkIn) assert.match(context.window.location.href, /walk-in-booking-confirmed/);
    else assert.equal(navigated, 'confirmed');
  }
}

console.log('Grooming consent: mouse/touch/stylus, validation, clear, drafts, representative names, and both submit flows passed.');
