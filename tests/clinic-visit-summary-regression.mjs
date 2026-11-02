import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { getClinicVisitSummaryMarkup } from '../scripts/components/clinic-visit-summary-card.js';

const nodes = new Map();
const get = id => {
  if (!nodes.has(id)) nodes.set(id, {
    textContent: id === 'submitClinicVisitBtn' ? 'Submit Queue Registration' : '',
    innerHTML: '', className: '', disabled: false, listeners: {},
    addEventListener(name, handler) { this.listeners[name] = handler; },
  });
  return nodes.get(id);
};
const draft = { pet: { id: 7, petName: 'Mochi', petType: 'cat' },
  chiefComplaint: 'Since yesterday', commonConcerns: ['Vomiting'] };
const saved = new Map([['draft', JSON.stringify(draft)], ['pets', 'saved pets']]);
let submitResponse, availabilityReads = 0;
const submissions = [];
const context = vm.createContext({
  document: { getElementById: get },
  window: { location: { href: '', replace() { throw Error('A valid draft should stay on review.'); } } },
  sessionStorage: { setItem: (key, value) => saved.set(key, value), removeItem: key => saved.delete(key) },
  CLINIC_VISIT_CONFIRMATION_KEY: 'confirmation', CLINIC_VISIT_DRAFT_KEY: 'draft', CLINIC_VISIT_PETS_KEY: 'pets',
  requireCustomerSession: async () => true, readClinicVisitDraft: () => draft,
  validateClinicVisitReason: () => '', formatClinicVisitReason: () => 'Vomiting\nSince yesterday',
  getClinicVisitSummaryMarkup,
  readReviewedServiceDay: () => '2026-11-03',
  registrationDayNotice: error => error.code === 'service_day_changed',
  renderRegistrationQueueInformation: async () => { availabilityReads++; return { registration_available: true }; },
  API: { submitClinicPreRegistration: async payload => { submissions.push(payload); return submitResponse(); } },
});
const source = fs.readFileSync(new URL('../scripts/components/clinic-visit-summary.js', import.meta.url), 'utf8')
  .replace(/^import[\s\S]*?from "[^"]+";\r?\n/gm, '');
await vm.runInContext(source, context);
assert.match(get('clinicVisitSummaryDetails').innerHTML, /Clinic Queue Registration/);
assert.match(get('clinicVisitSummaryDetails').innerHTML, /Mochi/);
const button = get('submitClinicVisitBtn');
assert.equal(button.disabled, false);

for (const [error, expected] of [
  [{ message: 'Please try later.' }, 'Please try later.'],
  [{}, 'Clinic queue registration could not be submitted. Please try again.'],
]) {
  submitResponse = async () => { throw error; };
  await button.listeners.click();
  assert.equal(button.textContent, 'Submit Queue Registration');
  assert.equal(button.disabled, false);
  assert.equal(get('clinicVisitSubmitStatus').textContent, expected);
  assert.equal(saved.has('draft'), true);
  assert.equal(context.window.location.href, '');
}
assert.equal(availabilityReads, 1, 'A failed submission adds no availability request');

submitResponse = async () => { throw { code: 'service_day_changed', message: 'Review the new visit day.' }; };
await button.listeners.click();
assert.equal(availabilityReads, 2, 'A changed day rechecks availability using the existing retry path');
assert.equal(button.textContent, 'Submit Queue Registration');
assert.equal(button.disabled, false);

let finish;
submitResponse = () => new Promise(resolve => { finish = resolve; });
const submitting = button.listeners.click();
assert.equal(button.disabled, true);
assert.equal(button.textContent, 'Submitting...');
const appointment = { appointment_reference: 'CL-ONLINE-7', queue_number: null, status: 'waiting_to_arrive' };
finish({ appointment });
await submitting;
assert.equal(JSON.stringify(submissions.at(-1)), JSON.stringify({ service_day: '2026-11-03',
  pet_id: 7, chief_complaint: draft.chiefComplaint, common_concerns: draft.commonConcerns }));
assert.deepEqual(JSON.parse(saved.get('confirmation')), appointment);
assert.equal(saved.has('draft'), false);
assert.equal(saved.has('pets'), false);
assert.equal(context.window.location.href, '/account/registration/clinic/confirmed');
console.log('Clinic review copy, failed submissions, retry labels, rollover and successful submission passed.');
