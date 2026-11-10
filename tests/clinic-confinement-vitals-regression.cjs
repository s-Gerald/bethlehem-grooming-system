const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const storage = new Map(), requests = [];
let timeout = false, serverErrors;
const sandbox = {
  console, crypto: webcrypto, Date, setTimeout, clearTimeout,
  document: {addEventListener() {}}, window: {dispatchEvent() {}}, CustomEvent: class {},
  sessionStorage: {setItem: (key, value) => storage.set(key, value), getItem: key => storage.get(key), removeItem: key => storage.delete(key)},
  API: {clinicRecordConfinementObservation: async (id, payload) => {
    requests.push(JSON.parse(JSON.stringify(payload)));
    if (serverErrors) throw Object.assign(Error('Invalid input'), {status: 422, errors: serverErrors});
    if (timeout) throw Error('Lost response');
    return {data: {id: 1}};
  }},
};
vm.createContext(sandbox);
for (const file of ['clinic-record-presentation.js', 'admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js']) {
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8'), sandbox);
}
const fields = ['temperature_c', 'heart_rate_bpm', 'respiratory_rate_bpm'];
function component(vet = true) {
  const state = sandbox.clinicConfinementWorkspace();
  state.$store = {clinicAccess: {user: {user_id: 1, role: vet ? 'staff' : 'admin', staff_type: 'clinic', staff_subrole: vet ? 'veterinarian' : null},
    permissions: {clinical: true}}, clinicFeedback: {notify() {}}};
  state.$nextTick = callback => callback();
  state.$el = {querySelector(selector) {
    state.focusSelector = selector;
    return {focus() {state.focused = true;}, scrollIntoView() {}};
  }};
  state.confinementCaseId = 1;
  state.confinementCase = {id: 1, status: 'in_consultation', pet: {species: 'Dog'},
    vitals: {heart_rate_bpm: '401', verification_history: [{field: 'heart_rate_bpm', value: '401'}]}};
  state.episode = {id: 2, discharged_at: null};
  state.loadObservations = async () => {};
  state.openMonitoring();
  return state;
}
function standard(state) {
  Object.assign(state.monitoringForm, {temperature_c: '38.5', heart_rate_bpm: '90', respiratory_rate_bpm: '20'});
}

(async () => {
  const notesOnly = component(); notesOnly.monitoringForm.notes = 'Alert';
  await notesOnly.submitMonitoring();
  assert.equal(requests.length, 0, 'Notes alone cannot create a monitoring observation');
  const empty = component();
  assert.equal(Object.keys(empty.monitoringErrors).length, 0, 'Opening Monitoring has no red errors');
  for (const field of fields) empty.validateMonitoringField(field, 'blur');
  assert.equal(Object.keys(empty.monitoringErrors).length, 0, 'Empty untouched measurements do not show required errors');
  for (const field of fields) {
    const state = component(); standard(state); state.monitoringForm[field] = '';
    const before = requests.length; await state.submitMonitoring();
    assert.equal(requests.length, before, `Missing ${field} prevents the API request`);
    assert.equal(state.monitoringErrors[field][0], 'Required');
    assert.equal(state.monitoringError, 'Complete the required fields marked *.');
    assert.equal(state.focused, true, 'Failed submit focuses the first invalid control');
    assert.match(state.focusSelector, /aria-invalid/);
    standard(state); state.validateMonitoringField(field, 'input');
    assert.equal(state.monitoringErrors[field], undefined, 'Correcting input immediately clears its required state');
    assert.equal(state.monitoringError, '');
  }
  empty.monitoringForm.notes = 'Alert';
  const beforeNotes = requests.length; await empty.submitMonitoring();
  assert.equal(requests.length, beforeNotes, 'Notes alone cannot be submitted');

  for (const [field, values] of Object.entries({temperature_c: ['38.55', '3.85e1', '-1', '1000', 'abc'],
    heart_rate_bpm: ['90.1', '9e1', '-1', '65536'], respiratory_rate_bpm: ['20.5', '2e1', '-1', '65536']})) {
    for (const value of values) {
      const state = component(); standard(state); state.monitoringForm[field] = value;
      state.validateMonitoringField(field, 'input');
      assert.equal(state.monitoringErrors[field], undefined, 'Fresh typing does not flash errors');
      state.validateMonitoringField(field, 'blur');
      assert.ok(state.monitoringErrors[field], `${field} rejects ${value}`);
      const before = requests.length; await state.submitMonitoring();
      assert.equal(requests.length, before);
      assert.equal(state.monitoringError, 'Check the highlighted fields.');
      standard(state); state.validateMonitoringField(field, 'input');
      assert.equal(state.monitoringErrors[field], undefined);
    }
  }

  const unusual = component(); standard(unusual);
  unusual.monitoringForm.temperature_c = '40.5';
  assert.equal(unusual.monitoringWarning('temperature_c'), 'Unusual reading. Please confirm.');
  unusual.monitoringForm.heart_rate_bpm = '100';
  assert.equal(unusual.monitoringWarning('heart_rate_bpm'), '');
  unusual.confinementCase.pet.species = 'Cat';
  assert.equal(unusual.monitoringWarning('heart_rate_bpm'), 'Unusual reading. Please confirm.');
  assert.equal(unusual.monitoringWarning('respiratory_rate_bpm'), 'Unusual reading. Please confirm.');
  await unusual.submitMonitoring(); assert.equal(unusual.monitoringOpen, false, 'Unusual standard readings remain recordable');

  const staff = component(false); standard(staff); staff.monitoringForm.heart_rate_bpm = '401';
  staff.startMonitoringVerification('heart_rate_bpm');
  assert.equal(staff.monitoringForm.vital_exceptions.heart_rate_bpm, undefined, 'Clinical permission cannot authorize exceptions');
  staff.validateMonitoringField('heart_rate_bpm', 'blur');
  assert.equal(staff.monitoringErrors.heart_rate_bpm[0], 'This reading requires veterinarian verification.');
  const beforeStaff = requests.length; await staff.submitMonitoring(); assert.equal(requests.length, beforeStaff);

  const notes = component(); standard(notes); notes.monitoringForm.notes = 'x'.repeat(501);
  const beforeLongNotes = requests.length; await notes.submitMonitoring();
  assert.equal(requests.length, beforeLongNotes, 'Notes retain the 500-character limit');
  assert.ok(notes.monitoringErrors.notes);
  notes.monitoringForm.notes = 'Corrected'; notes.validateMonitoringNotes();
  assert.equal(notes.monitoringErrors.notes, undefined);

  const vet = component(); standard(vet); vet.monitoringForm.heart_rate_bpm = '401';
  const beforeVet = requests.length;
  await vet.submitMonitoring(); assert.equal(requests.length, beforeVet, 'Saved Medical Record evidence cannot authorize monitoring');
  vet.startMonitoringVerification('heart_rate_bpm');
  for (const reason of ['', 'abcd', '    a    ', 'x'.repeat(501)]) {
    vet.monitoringForm.vital_exceptions.heart_rate_bpm.reason = reason;
    await vet.submitMonitoring(); assert.equal(requests.length, beforeVet, 'Invalid clinical reason blocks the request');
  }
  vet.monitoringForm.vital_exceptions.heart_rate_bpm.reason = 'Confirmed by auscultation';
  vet.validateMonitoringField('heart_rate_bpm', 'input');
  assert.equal(vet.monitoringErrors.heart_rate_bpm, undefined);
  vet.monitoringForm.heart_rate_bpm = '402'; vet.validateMonitoringField('heart_rate_bpm', 'input');
  assert.equal(vet.monitoringForm.vital_exceptions.heart_rate_bpm, undefined, 'Changing a reading clears stale verification');
  vet.startMonitoringVerification('heart_rate_bpm');
  vet.monitoringForm.vital_exceptions.heart_rate_bpm.reason = 'Confirmed by auscultation';
  standard(vet); vet.validateMonitoringField('heart_rate_bpm', 'input');
  assert.equal(vet.monitoringForm.vital_exceptions.heart_rate_bpm, undefined, 'Standard values no longer need verification');
  vet.monitoringForm.heart_rate_bpm = '401'; vet.startMonitoringVerification('heart_rate_bpm');
  vet.monitoringForm.vital_exceptions.heart_rate_bpm.reason = 'x'.repeat(500);
  timeout = true; await vet.submitMonitoring();
  const payload = JSON.stringify(requests.at(-1));
  assert.ok(vet.monitoringPending);
  const retained = JSON.parse(storage.get(vet.confinementStoragePrefix + 'monitoring'));
  assert.equal(retained.form.vital_exceptions.heart_rate_bpm.reason.length, 500, 'Safe retry retains the exact verification reason');
  const retry = component();
  retry.monitoringForm = retained.form; retry.monitoringPending = retained.pending;
  timeout = false; await retry.submitMonitoring();
  assert.equal(JSON.stringify(requests.at(-1)), payload, 'Lost-response retry sends exactly the same request key and evidence');
  assert.equal(retry.monitoringOpen, false);

  const stale = component(); standard(stale);
  stale.monitoringPending = {request_key: stale.monitoringForm.request_key, notes: 'Legacy notes-only pending payload'};
  const beforeStale = requests.length; await stale.submitMonitoring();
  assert.equal(requests.length, beforeStale, 'Stale pending payload cannot bypass required-vital validation');
  assert.equal(stale.monitoringPending, null, 'Invalid retained request unlocks correction');

  const pendingException = component(); standard(pendingException);
  pendingException.monitoringPending = {...pendingException.monitoringForm, heart_rate_bpm: '401',
    vital_exceptions: {heart_rate_bpm: {value: '402', reason: 'Evidence for a different reading'}}};
  const beforePendingException = requests.length; await pendingException.submitMonitoring();
  assert.equal(requests.length, beforePendingException, 'Retained exceptional payload is independently checked for stale evidence');
  assert.equal(pendingException.monitoringPending, null);

  const server = component(); standard(server);
  serverErrors = {'vital_exceptions.heart_rate_bpm.reason': ['Reason is invalid']};
  await server.submitMonitoring();
  assert.ok(server.monitoringErrors.heart_rate_bpm, 'Nested API validation errors associate with the vital input');
  assert.equal(server.focused, true); assert.equal(server.monitoringPending, null);
  serverErrors = undefined;
  server.validateMonitoringField('heart_rate_bpm', 'input');
  assert.equal(server.monitoringErrors.heart_rate_bpm, undefined);
  console.log('Confinement required vitals, warnings, veterinarian evidence, validation timing and safe retry regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
