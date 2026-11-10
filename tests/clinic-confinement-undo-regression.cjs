const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const storage = new Map(), events = [], listeners = new Map(), requests = [], stores = {};
let lostResponse = false, serverErrors, pendingResponse;
let loadedEpisode = {id: 9};
let returnedFocus = 0, reasonFocus = 0;
const sandbox = {console, crypto: webcrypto, Date, setTimeout, clearTimeout, queueMicrotask,
  document: {activeElement: {focus() {returnedFocus++;}}, getElementById() {return {focus() {reasonFocus++;}};},
    addEventListener(name, callback) {if (name === 'alpine:init') callback();}},
  Alpine: {store(name, value) {stores[name] = value;}},
  sessionStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  window: {addEventListener(name, callback) {listeners.set(name, callback);}, dispatchEvent(event) {events.push(event); listeners.get(event.type)?.(event);}},
  API: {
    clinicUndoConfinementAdmission: async (id, payload) => {
      requests.push({id, payload: {...payload}});
      if (pendingResponse) await pendingResponse;
      if (serverErrors) throw Object.assign(Error('Cannot undo'), {status: 422, errors: serverErrors});
      if (lostResponse) throw Error('Lost response');
      return {data: null, case: {id, status: 'in_consultation', total_amount: 1200, confinement: null}};
    },
    getClinicConfinement: async () => ({data: loadedEpisode, can_undo_admission: true}),
    getClinicConfinementObservations: async () => ({data: [], current_page: 1, last_page: 1}),
    clinicConfinementOptions: async () => ({data: [], current_page: 1, last_page: 1}),
  },
};
vm.createContext(sandbox);
for (const file of ['clinic-record-presentation.js', 'admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js', 'clinic-feedback.js']) {
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8'), sandbox);
}
const feedback = stores.clinicFeedback;
function component() {
  const state = sandbox.clinicConfinementWorkspace();
  state.$store = {clinicFeedback: feedback, clinicAccess: {permissions: {clinical: true}, user: {user_id: 1}}};
  state.$nextTick = callback => callback(); state.$refs = {};
  state.confinementCaseId = 7;
  state.confinementCase = {id: 7, status: 'in_consultation', total_amount: 1650, pet: {species: 'Dog'}};
  state.episode = {id: 9, discharged_at: null}; state.undoAllowed = true;
  state.medicalForm = {working_diagnosis: 'Unsaved medical edits'};
  return state;
}
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  let ordinary = feedback.confirm({title: 'Ordinary confirmation', message: 'Continue?'});
  feedback.answer(true); assert.equal(await ordinary, true, 'Existing confirmations retain boolean results');
  const reasonPromise = feedback.confirm({title: 'Undo confinement admission?', message: 'Correction', requireReason: true});
  let resolved = false; reasonPromise.then(() => {resolved = true;});
  for (const reason of ['', '   ', 'é'.repeat(201)]) {
    feedback.dialog.reason = reason; feedback.answer(true); await flush();
    assert.equal(resolved, false, 'Missing/overlong reason must leave confirmation open');
    assert.ok(feedback.dialog.reasonError);
    assert.equal(feedback.dialog.open, true);
  }
  feedback.dialog.reason = '  Correct patient admission  ';
  feedback.validateCorrectionReason('input'); assert.equal(feedback.dialog.reasonError, '');
  feedback.answer(true);
  assert.equal((await reasonPromise).reason, 'Correct patient admission');
  await new Promise(resolve => setTimeout(resolve, 0)); assert.ok(returnedFocus); assert.ok(reasonFocus);

  const cancelled = component(); const beforeCancel = requests.length;
  const cancelPromise = cancelled.undoConfinementAdmission(); feedback.answer(false); await cancelPromise;
  assert.equal(requests.length, beforeCancel); assert.equal(cancelled.episode.id, 9);
  const state = component();
  for (const kind of ['admission', 'monitoring', 'extra', 'discharge']) state.retain(kind, {draft: true});
  storage.set('unrelated-draft', 'keep');
  const promise = state.undoConfinementAdmission();
  assert.equal(feedback.dialog.title, 'Undo confinement admission?');
  assert.equal(feedback.dialog.cancelLabel, 'Keep Confinement');
  assert.equal(feedback.dialog.confirmLabel, 'Undo Admission');
  assert.equal(feedback.dialog.requireReason, true);
  const beforeDuplicate = requests.length;
  await state.undoConfinementAdmission(); assert.equal(requests.length, beforeDuplicate, 'Double click does not open another operation');
  feedback.dialog.reason = 'Wrong patient selected'; feedback.answer(true); await promise;
  assert.equal(requests.length, beforeDuplicate + 1);
  assert.equal(requests.at(-1).payload.confinement_id, 9);
  assert.equal(requests.at(-1).payload.reason, 'Wrong patient selected');
  assert.equal(state.episode, null); assert.equal(state.confinementCase.confinement, null);
  assert.equal(state.confinementCase.status, 'in_consultation'); assert.equal(state.confinementCase.total_amount, 1200);
  assert.equal(state.medicalForm.working_diagnosis, 'Unsaved medical edits');
  for (const kind of ['admission', 'monitoring', 'extra', 'discharge', 'undo']) assert.equal(storage.has(state.confinementStoragePrefix + kind), false);
  assert.equal(storage.get('unrelated-draft'), 'keep');
  assert.ok(events.some(event => event.type === 'clinic-case-patch' && event.detail.confinement === null && event.detail.total_amount === 1200));
  assert.ok(events.some(event => event.type === 'clinic-case-updated'));
  assert.equal(feedback.toast.message, 'Confinement admission undone. Patient returned to Active Cases.');

  const retry = component(); lostResponse = true;
  const attempt = retry.undoConfinementAdmission(); feedback.dialog.reason = 'Admitted by mistake'; feedback.answer(true); await attempt;
  assert.ok(retry.undoPending); assert.equal(retry.canCareForConfinement, false, 'Uncertain correction cannot be followed by new care');
  const retained = storage.get(retry.confinementStoragePrefix + 'undo');
  assert.equal(JSON.parse(retained).confinement_id, 9);
  const restored = component(); loadedEpisode = null;
  await restored.loadConfinement();
  assert.equal(restored.episode, null);
  assert.equal(restored.undoPending.confinement_id, 9, 'Reload can retry an uncertain Undo even after the episode is already gone');
  assert.equal(restored.canAdmitConfinement, false, 'Resolve the retained correction before readmission');
  lostResponse = false; await restored.undoConfinementAdmission();
  assert.equal(JSON.stringify(requests.at(-1)), JSON.stringify(requests.at(-2)), 'Retry targets the exact admission and reason');
  assert.equal(restored.undoPending, null);
  storage.set(restored.confinementStoragePrefix + 'undo', retained);
  loadedEpisode = {id: 10}; await restored.loadConfinement();
  assert.equal(restored.undoPending, null, 'Old retained correction is discarded when a different admission is loaded');
  assert.equal(restored.episode.id, 10); assert.equal(restored.canCareForConfinement, true);
  loadedEpisode = {id: 9};

  const rejected = component(); serverErrors = {confinement: ['Monitoring or confinement care has already been recorded.']};
  const rejectPromise = rejected.undoConfinementAdmission(); feedback.dialog.reason = 'Mistaken admission'; feedback.answer(true); await rejectPromise;
  assert.equal(rejected.episode.id, 9); assert.equal(rejected.undoAllowed, false);
  assert.match(rejected.error, /Monitoring or confinement care/); assert.equal(rejected.undoPending, null);
  serverErrors = undefined;

  const switched = component(); let release;
  pendingResponse = new Promise(resolve => {release = resolve;});
  const switching = switched.undoConfinementAdmission(); feedback.dialog.reason = 'Mistake'; feedback.answer(true); await flush();
  switched._generation++; switched.confinementCaseId = 8; switched.episode = {id: 10};
  release(); await switching; pendingResponse = null;
  assert.equal(switched.episode.id, 10, 'Response for an old case must not clear a new case');

  const modal = sandbox.adminClinicModal(); modal.init(); modal.open = true; modal.apptId = 7;
  modal.currentAppt = {id: 7, vitals: {verification_history: [{value: '401'}]}, record: {working_diagnosis: 'Saved diagnosis'}};
  sandbox.window.dispatchEvent(new sandbox.CustomEvent('clinic-cases-loaded', {detail: [{id: 7, status: 'in_consultation', confinement: null}]}));
  assert.equal(modal.open, true);
  assert.equal(modal.currentAppt.record.working_diagnosis, 'Saved diagnosis', 'List refresh must preserve loaded Medical Record data');
  assert.equal(modal.currentAppt.vitals.verification_history.length, 1);
  console.log('Undo confirmation/reasons, authorization eligibility, duplicate/retry safety, case refresh and Medical Record preservation passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
