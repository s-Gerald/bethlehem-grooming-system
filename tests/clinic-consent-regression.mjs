import assert from 'node:assert/strict';
import { mountClinicConsent } from '../scripts/components/clinic-consent.js';

class Element {
  checked = false;
  isConnected = true;
  value = '';
  style = {};
  listeners = new Map();
  children = [];
  addEventListener(type, handler) { this.listeners.set(type, handler); }
  emit(type, event = {}) { return this.listeners.get(type)?.({ preventDefault() {}, ...event }); }
  setAttribute(name, value) { this[name] = value; }
  focus() { this.focused = true; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this.children = children; }
  getContext() { return { clearRect() {}, beginPath() {}, moveTo() {}, lineTo() {}, stroke() {} }; }
  getBoundingClientRect() { return { left: 0, top: 0, width: 450, height: 180 }; }
  setPointerCapture(id) { this.pointer = id; }
  hasPointerCapture(id) { return this.pointer === id; }
  releasePointerCapture() { this.pointer = null; }
}

const storage = new Map();
globalThis.sessionStorage = {
  getItem: key => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, value),
  removeItem: key => storage.delete(key),
};
globalThis.document = { createElement: () => new Element() };
const agreement = hours => ({
  agreement: { version: 'clinic-v1', title: 'Clinic Care Agreement',
    acknowledgment: 'I have read and agree to the Clinic Care Agreement.',
    clauses: [{ heading: 'Visiting hours', text: `Pet visits are allowed during ${hours}.` }],
  },
  agreement_hash: hours,
});
const original = agreement('8:00 AM – 5:00 PM');
const revised = agreement('9:00 AM – 4:00 PM');
let response = Promise.resolve(original);
const reads = [];
globalThis.API = { getClinicCareAgreement: ({ staff }) => { reads.push(staff); return response; } };

function mount({ staff = false, scope = 'pets' } = {}) {
  const elements = new Map();
  const host = new Element();
  host.querySelector = id => {
    if (!elements.has(id)) elements.set(id, new Element());
    return elements.get(id);
  };
  const get = host.querySelector;
  const consent = mountClinicConsent({ host, storageKey: 'draft', scope, staff, signerName: 'Ana Cruz', onChange() {} });
  const checkbox = get('#clinicConsentCheckbox');
  const name = get('#clinicSignerName');
  const canvas = get('#clinicSignaturePad');
  function draw(pointerType, move = true) {
    const event = { pointerId: 1, pointerType, button: 0, clientX: 30, clientY: 30 };
    canvas.emit('pointerdown', event);
    if (move) canvas.emit('pointermove', { ...event, clientX: 50, clientY: 60 });
    canvas.emit('pointerup', event);
  }
  return { consent, get, checkbox, name, canvas, draw };
}

const first = mount();
const { consent, get, checkbox, name, draw } = first;
assert.equal(consent.valid(), false);
await consent.ready;
assert.equal(get('[data-agreement-clauses]').children[0].children[1].textContent, 'Pet visits are allowed during 8:00 AM – 5:00 PM.');
assert.equal(get('#clinicSignatureError').hidden, true);
assert.equal(name.value, 'Ana Cruz');
assert.equal(name['aria-invalid'], 'false');
assert.equal(consent.validate(), false);
assert.equal(checkbox.focused, true);
checkbox.checked = true;
checkbox.emit('change');
assert.equal(get('#clinicAcknowledgmentError').hidden, true);
assert.equal(get('#clinicSignatureError').hidden, false, 'An error revealed on submit persists until corrected.');

draw('touch', false);
assert.equal(consent.valid(), false, 'A tap is not a signature');
assert.equal(get('#clinicSignatureError').textContent, 'Please draw your signature.');
draw('touch');
assert.equal(get('#clinicSignatureError').hidden, true);
assert.equal(consent.valid(), true);
name.value = ' ';
name.emit('input');
assert.equal(consent.valid(), false);
assert.equal(get('#clinicSignerError').textContent, 'Enter the signer’s full name.');
name.value = 'authorized representative';
name.emit('input');
assert.equal(consent.valid(), true);
assert.deepEqual(consent.payload(), { terms_agreed: true, signer_name: 'authorized representative',
  signature_strokes: [[[60, 60], [100, 120]]], consent_version: 'clinic-v1', agreement_hash: original.agreement_hash });
assert.equal(reads.length, 1, 'Drawing and entering text use the same reviewed agreement.');

const restored = mount({ staff: true });
await restored.consent.ready;
assert.deepEqual(restored.consent.payload(), consent.payload());
assert.equal(restored.consent.valid(), true);
assert.deepEqual(reads, [false, true]);
const originalDraft = storage.get('draft');

response = Promise.resolve(revised);
assert.equal(await restored.consent.handleError({ code: 'clinic_agreement_changed' }), true);
assert.equal(restored.consent.valid(), false);
assert.equal(restored.checkbox.checked, false);
assert.deepEqual(restored.consent.payload().signature_strokes, []);
assert.equal(restored.name.value, 'authorized representative');
assert.equal(restored.consent.payload().agreement_hash, revised.agreement_hash);
assert.equal(storage.has('draft'), false);
assert.match(restored.get('[data-agreement-notice]').textContent, /Review and sign/);
assert.equal(restored.get('#clinicAgreementLoadStatus').focused, true);

storage.set('draft', originalDraft);
const stale = mount();
await stale.consent.ready;
assert.equal(stale.checkbox.checked, false);
assert.deepEqual(stale.consent.payload().signature_strokes, []);
assert.equal(storage.has('draft'), false);
assert.match(stale.get('[data-agreement-notice]').textContent, /changed/);

storage.set('draft', originalDraft);
response = Promise.resolve(original);
const edited = mount({ scope: 'changed pets' });
await edited.consent.ready;
assert.equal(edited.consent.valid(), false);
assert.equal(storage.has('draft'), false);

response = Promise.reject(new Error('offline'));
const failed = mount();
await failed.consent.ready;
assert.equal(failed.consent.valid(), false);
assert.equal(failed.get('[data-agreement-fields]').hidden, true);
assert.match(failed.get('[data-agreement-notice]').textContent, /could not be loaded/);
assert.equal(failed.get('[data-agreement-retry]').hidden, false);
response = Promise.resolve(revised);
await failed.get('[data-agreement-retry]').emit('click');
assert.equal(failed.get('[data-agreement-fields]').hidden, false);
assert.equal(failed.get('#clinicAgreementTitle').focused, true);
assert.equal(failed.consent.valid(), false);
failed.checkbox.checked = true;
failed.checkbox.emit('change');
failed.draw('mouse');
assert.equal(failed.consent.valid(), true);
await failed.consent.handleError({ errors: { 'signature_strokes.0': ['Invalid stroke'] } });
assert.equal(failed.consent.valid(), false);
assert.equal(failed.get('#clinicSignatureError').textContent, 'Invalid stroke');
assert.equal(failed.canvas.focused, true);
failed.get('[data-clear-signature]').emit('click');
assert.deepEqual(failed.consent.payload().signature_strokes, []);
failed.draw('pen');
assert.equal(failed.consent.valid(), true);
failed.consent.clearDraft();
assert.equal(storage.has('draft'), false);

console.log('Shared clinic consent validation, touch signature, scoped drafts, stale review, and retry passed.');
