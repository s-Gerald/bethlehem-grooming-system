import assert from 'node:assert/strict';
import { initClinicVisitConfirmation } from '../scripts/components/clinic-visit-confirmed.js';
import { CLINIC_VISIT_DRAFT_KEY, readClinicVisitDraft } from '../scripts/services/clinic-visit-service.js';

const saved = new Map();
const root = { innerHTML: '', querySelector: () => ({ focus() {}, addEventListener() {} }) };
globalThis.sessionStorage = { getItem: key => saved.get(key) || null };
globalThis.API = { waitForSessionVerification: async () => true, waitForCustomerSession: async () => true };
globalThis.window = { API, location: { replace() { assert.fail('A valid confirmation should remain visible'); } } };
globalThis.document = {
  body: { dataset: {}, classList: { add() {} } },
  getElementById: id => id === 'confirmationRoot' ? root : null,
};

const patients = [
  { appointment_reference: 'CL-MOCHI', status: 'waiting_to_arrive', queue_number: null, pet: { name: 'Mochi', species: 'cat' } },
  { appointment_reference: 'CL-RIGBY', status: 'waiting_to_arrive', queue_number: null, pet: { name: 'Rigby', species: 'dog' } },
];
const session = { id: 7, service_day: '2026-11-11', owner: { name: 'Jamie Santos' }, patients };
saved.set('clinicVisitConfirmation', JSON.stringify(session));
await initClinicVisitConfirmation();
assert.match(root.innerHTML, /Queue Registration Received/);
assert.match(root.innerHTML, /2 pets registered/);
assert.equal((root.innerHTML.match(/Jamie Santos/g) || []).length, 1);
assert.match(root.innerHTML, /Mochi[\s\S]*CL-MOCHI/);
assert.match(root.innerHTML, /Rigby[\s\S]*CL-RIGBY/);
assert.equal((root.innerHTML.match(/Not assigned/g) || []).length, 2);

document.body.dataset.confirmationContext = 'walk-in';
saved.set('walkInClinicConfirmation', JSON.stringify({
  ...session, patients: patients.map((patient, index) => ({ ...patient, status: 'checked_in', queue_number: 21 + index })),
}));
await initClinicVisitConfirmation();
assert.match(root.innerHTML, /2 pets checked in/);
assert.match(root.innerHTML, /CL-MOCHI[\s\S]*>21</);
assert.match(root.innerHTML, /CL-RIGBY[\s\S]*>22</);

saved.set(CLINIC_VISIT_DRAFT_KEY, JSON.stringify({ patients: [
  { pet: { id: 11, petName: 'Mochi' }, commonConcerns: ['Routine check-up'] },
  { pet: { id: 12, petName: 'Rigby' }, commonConcerns: ['Limping / injury'], chiefComplaint: 'Left paw' },
] }));
assert.deepEqual(readClinicVisitDraft().patients.map(row => [row.pet.id, row.commonConcerns[0]]), [
  [11, 'Routine check-up'], [12, 'Limping / injury'],
]);
