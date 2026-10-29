const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const storage = new Map(), requests = [], events = [];
let episode = null, timeout = false, variable = false, confirmResult = true, saveFails = false;
const confirmations = [], prerequisiteSaves = [];
const service = {id: 10, name: 'Inpatient care', pricing_method: 'fixed', price: '500.00', billing_unit: 'day'};
const pricing = () => ({requires_final_price: variable, min_price: 500, max_price: 900, resolved_price: variable ? null : '500.00', error: null});
const sandbox = {console, crypto: webcrypto, Date, setTimeout, clearTimeout, document: {addEventListener() {}},
  sessionStorage: {setItem: (k, v) => storage.set(k, v), getItem: k => storage.get(k), removeItem: k => storage.delete(k)},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  window: {dispatchEvent: e => events.push(e)},
  API: {
    clinicSaveRecord: async (id, payload) => {
      prerequisiteSaves.push({id, payload: {...payload}});
      if (saveFails) throw Error('Record save unavailable');
      return {record: {primary_veterinarian_id: payload.primary_veterinarian_id, primary_veterinarian_name: 'Angela Santiago'}};
    },
    adminRequest: async (method, url, payload) => {
      if (method === 'GET') return {data: service, pricing: pricing()};
      requests.push({kind: 'extra', method, url, payload: {...payload}});
      if (timeout) throw Error('timeout');
      return {data: {}};
    },
    getClinicConfinement: async () => ({data: episode}),
    clinicConfinementOptions: async () => ({data: [service], total: 1, current_page: 1, last_page: 1}),
    clinicCaseServicePricing: async () => ({data: service, pricing: pricing()}),
    clinicAdmitConfinement: async (id, payload) => {requests.push({id, kind: 'admission', payload: {...payload}});
      if (timeout) throw Error('timeout');
      episode = {id: 5, admitted_at: '2026-10-13T10:00:00+08:00', discharged_at: null, case_service: {clinic_service: service}};
      return {data: episode};},
    getClinicConfinementObservations: async () => ({data: [], current_page: 1, last_page: 1}),
    clinicRecordConfinementObservation: async (id, payload) => {requests.push({id, kind: 'monitoring', payload: {...payload}}); if (timeout) throw Error('timeout'); return {data: {}};},
    clinicConfinementDischargePreview: async () => ({service: {...service, pricing_method: variable ? 'range' : 'fixed'}, pricing: pricing(), quantity: 2}),
    clinicDischargeConfinement: async (id, payload) => {requests.push({id, kind: 'discharge', payload: {...payload}});
      if (timeout) throw Error('timeout'); episode = {...episode, discharged_at: '2026-10-14T10:01:00+08:00'}; return {data: episode};},
  },
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), sandbox);
for (const file of ['admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-clinic-confinement.js'])
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), sandbox);
function component(permission = true, id = 1) {
  const state = sandbox.clinicConfinementWorkspace();
  state.$store = {clinicAccess: {permissions: {clinical: permission, billing: true}, user: {user_id: 7}},
    clinicFeedback: {notify() {}, confirm: async dialog => {confirmations.push(dialog); return confirmResult;}}};
  state.selectSection = section => {state.section = section;};
  state.confinementCase = {id, status: 'in_consultation', paid: false, pet: {name: 'Mochi'}}; state.confinementCaseId = id;
  const modal = sandbox.adminClinicModal(); modal.apptId = id; modal.currentAppt = state.confinementCase; modal.open = true;
  modal.form.primary_veterinarian_id = '9'; modal.form.weight_kg = '18.4';
  state.medicalForm = modal.form; state.selectedVeterinarians = [{user_id: 9, name: 'Angela Santiago'}];
  state.persistConfinementPrerequisites = () => modal.persistConfinementPrerequisites();
  state.modal = modal;
  return state;
}
(async () => {
  const invalid = component(true, 90); await invalid.openAdmission();
  invalid.selectedService.pricing_method = 'tiered'; invalid.medicalForm.primary_veterinarian_id = ''; invalid.medicalForm.weight_kg = '';
  await invalid.submitAdmission();
  assert.match(invalid.admissionErrors.primary_veterinarian_id, /Medical Record/);
  assert.match(invalid.admissionErrors.weight_kg, /Medical Record/); assert.equal(invalid.admissionErrors.reason, 'Enter a reason for admission.');
  assert.equal(confirmations.length, 0); assert.equal(requests.length, 0); assert.equal(prerequisiteSaves.length, 0);
  invalid.medicalForm.primary_veterinarian_id = '9'; invalid.medicalForm.weight_kg = '18.4'; invalid.admissionForm.reason = 'Observation';
  confirmResult = false; await invalid.submitAdmission();
  assert.equal(requests.length, 0); assert.equal(prerequisiteSaves.length, 0); assert.equal(invalid.admissionPending, null);
  assert.equal(confirmations.at(-1).danger, false); assert.match(confirmations.at(-1).message, /Angela Santiago/);
  invalid.admissionForm.reason = 'Observation '.repeat(150); await invalid.submitAdmission();
  assert.ok(confirmations.at(-1).message.length < 600, 'Confirmation summarizes long reasons so actions remain reachable');
  assert.equal(invalid.admissionForm.reason.length, 1800, 'Confirmation keeps the complete admission reason');
  invalid.admissionForm.reason = 'Observation';
  confirmResult = true; saveFails = true; await invalid.submitAdmission();
  assert.equal(requests.length, 0); assert.match(invalid.error, /Could not save the admission information/);
  assert.equal(invalid.admissionPending, null); assert.equal(invalid.modal.open, true); saveFails = false;
  storage.clear(); service.pricing_method = 'fixed';

  const double = component(true, 91); await double.openAdmission(); double.admissionForm.reason = 'Observe';
  let answer;
  double.$store.clinicFeedback.confirm = () => new Promise(resolve => {answer = resolve;});
  const firstClick = double.submitAdmission();
  assert.equal(double.confirming, true); const savesBefore = prerequisiteSaves.length;
  await double.submitAdmission(); assert.equal(prerequisiteSaves.length, savesBefore);
  answer(false); await firstClick; assert.equal(double.confirming, false);
  double.$store.clinicFeedback.confirm = async dialog => {confirmations.push(dialog); return true;};
  let finishSave;
  double.persistConfinementPrerequisites = () => new Promise(resolve => {finishSave = resolve;});
  const confirmedClick = double.submitAdmission(); await new Promise(resolve => setImmediate(resolve));
  assert.equal(double.busy, true); const requestsBefore = requests.length;
  await double.submitAdmission(); assert.equal(requests.length, requestsBefore);
  finishSave(); await confirmedClick;
  assert.equal(requests.length, requestsBefore + 1, 'Duplicate clicks during persistence create only one admission request');
  episode = null; storage.clear(); requests.length = 0;

  const returning = component(true, 92);
  returning.admissionErrors = {primary_veterinarian_id: 'Missing veterinarian', weight_kg: 'Missing weight'};
  returning.loadConfinement = () => {};
  returning.syncConfinement(true, returning.confinementCase, 'confinement', returning.medicalForm);
  assert.equal(Object.keys(returning.admissionErrors).length, 0, 'Returning from Medical Record clears stale prerequisite errors');

  const state = component();
  await state.openAdmission(); assert.equal(state.selectedService.id, 10, 'Base is resolved by server'); assert.equal('clinic_service_id' in state.admissionForm, false);
  state.admissionForm.reason = 'Observe'; timeout = true;
  await state.submitAdmission(); const original = JSON.stringify(requests.at(-1).payload);
  assert.equal(prerequisiteSaves.at(-1).payload.primary_veterinarian_id, '9');
  assert.equal(prerequisiteSaves.at(-1).payload.weight_kg, '18.4');
  assert.equal(prerequisiteSaves.at(-1).payload.finish_case, false);
  assert.equal('diagnostics' in prerequisiteSaves.at(-1).payload, false, 'Admission does not validate unrelated unsaved medical rows');
  assert.equal(state.modal.open, true, 'Automatic prerequisite save keeps Patient Record open');
  assert.ok(state.admissionPending); assert.equal(state.busy, false);
  const reload = component(); await reload.loadConfinement(); assert.equal(reload.admissionForm.reason, 'Observe');
  timeout = false; await reload.submitAdmission(); assert.equal(JSON.stringify(requests.at(-1).payload), original);
  assert.equal(reload.activeConfinement, true); assert.equal(storage.has(reload.confinementStoragePrefix + 'admission'), false);
  assert.ok(events.some(e => e.type === 'clinic-case-patch' && e.detail.confinement));

  reload.openMonitoring(); const count = requests.length; await reload.submitMonitoring(); assert.equal(requests.length, count, 'Empty monitoring is blocked');
  reload.monitoringForm.notes = 'Alert'; timeout = true; await reload.submitMonitoring();
  const monitoring = JSON.stringify(requests.at(-1).payload); timeout = false; await reload.submitMonitoring();
  assert.equal(JSON.stringify(requests.at(-1).payload), monitoring); assert.equal(reload.monitoringOpen, false);
  assert.equal('recorded_at' in requests.at(-1).payload, false, 'Monitoring time is server-owned');

  await reload.openExtra(); reload.extraForm.clinic_service_id = 10; await reload.previewExtra();
  timeout = true; await reload.submitExtra();
  const extraPayload = JSON.stringify(requests.at(-1).payload);
  assert.ok(reload.extraPending); assert.equal('quantity' in requests.at(-1).payload, false);
  assert.equal('applied_price' in requests.at(-1).payload, false, 'Daily start has no final-price input');
  timeout = false; await reload.submitExtra(); assert.equal(JSON.stringify(requests.at(-1).payload), extraPayload);
  assert.equal(reload.extraPending, null);
  const running = {id: 11, started_at: '2026-10-13T10:00:00Z', case_service: {clinic_service: service}, pricing: pricing()};
  await reload.openExtra(running);
  assert.match(requests.at(-1).url, /charges\/11\/stop$/);
  assert.equal('stopped_at' in requests.at(-1).payload, false, 'Stop time is server-owned');
  assert.equal(reload.extraForm, null, 'Fixed daily charges stop without an extra confirmation form');
  assert.equal(reload.billableDays('2026-10-13T00:00:00Z', '2026-10-14T00:01:00Z'), 2);

  await reload.openDischarge(); assert.equal(reload.dischargePreview.quantity, 2);
  confirmResult = false; const beforeCancel = requests.length; await reload.submitDischarge();
  assert.equal(requests.length, beforeCancel); assert.equal(reload.dischargePending, null); assert.equal(reload.activeConfinement, true);
  assert.equal(confirmations.at(-1).title, 'Discharge patient?'); assert.match(confirmations.at(-1).message, /Billable days: 2/);
  confirmResult = true;
  reload.dischargeForm.applied_price = '1'; timeout = true; await reload.submitDischarge();
  assert.equal('quantity' in requests.at(-1).payload, false); assert.equal('applied_price' in requests.at(-1).payload, false, 'Fixed never sends a manual price');
  const discharge = JSON.stringify(requests.at(-1).payload); timeout = false; await reload.submitDischarge();
  assert.equal(JSON.stringify(requests.at(-1).payload), discharge); assert.equal(reload.section, 'billing');
  assert.equal(reload.confinementCase.status, 'for_payment'); assert.equal(reload.activeConfinement, false);

  episode = {...episode, discharged_at: null}; variable = true;
  const range = component(true, 2); range.episode = episode; await range.openDischarge();
  for (const value of ['', '499.99', '900.01', '500.001', '1e3']) {
    range.dischargeForm.applied_price = value; const before = requests.length, dialogsBefore = confirmations.length; await range.submitDischarge();
    assert.equal(confirmations.length, dialogsBefore, 'Invalid discharge never asks for confirmation');
    assert.equal(requests.length, before); assert.ok(range.priceError); assert.equal(range.dischargeForm.applied_price, value);
  }
  range.dischargeForm.applied_price = '700'; await range.submitDischarge(); assert.equal(requests.at(-1).payload.applied_price, '700');

  const reception = component(false); reception.episode = {...episode, discharged_at: null};
  const before = requests.length; await reception.openAdmission(); reception.openMonitoring(); await reception.openDischarge();
  assert.equal(requests.length, before); assert.equal(reception.monitoringForm, null); assert.equal(reception.dischargeForm, null);
  assert.equal(reception.canCareForConfinement, false);
  const paid = component(); paid.confinementCase.paid = true; assert.equal(paid.canAdmitConfinement, false);
  const modal = sandbox.adminClinicModal(); modal.$store = state.$store;
  modal.currentAppt = {id: 1, status: 'in_consultation', confinement: {...episode, discharged_at: null}};
  assert.equal(modal.canFinish, false, 'Ordinary completion is hidden during active confinement');
  modal.currentAppt.confinement.discharged_at = '2026-10-14T10:01:00Z'; assert.equal(modal.canFinish, true);
  sandbox.API.getClinicConfinedCases = async () => ({data: [{id: 1, status: 'in_consultation', confinement: episode}], total: 23, current_page: 2, last_page: 2});
  const pageState = sandbox.adminClinicPage(); pageState.$store = {clinicAccess: {permissions: {access: true, clinical: true}}};
  await pageState.loadConfinedCases(2); assert.equal(pageState.confinedCount, 23); assert.equal(pageState.confinedPage, 2);
  assert.equal(pageState.confinedCases.length, 1, 'Count comes from the server, not loaded page length');
  pageState.openCase(pageState.confinedCases[0], 'confinement');
  assert.equal(events.at(-1).type, 'clinic-open-modal');
  assert.equal(events.at(-1).detail.appt, pageState.confinedCases[0], 'Open Case uses the same confined case');
  assert.equal(events.at(-1).detail.section, 'confinement');
  assert.equal(sandbox.clinicConfinementDuration('2026-10-13T00:00:00Z', '2026-10-14T01:02:00Z'), '1d 1h 2m');
  assert.equal(state.methodLabel('fixed'), 'Fixed', 'Shared formatting used in Confinement remains available');
  assert.equal(state.sizeLabel('medium'), 'Medium');
  const snapshotView = component();
  snapshotView.episode = {admitted_at: '2026-10-13T00:00:00Z', case_service: {applied_price: '1650.00',
    service_snapshot: {pet_size: 'medium', pet_weight_kg: '14.20'}}, confinement_charges: [
      {id: 1, charge_behavior: 'admission_charge'}, {id: 2, charge_behavior: 'additional_charge'},
      {id: 3, charge_behavior: 'admission_charge', removed_at: '2026-10-13T00:00:00Z'},
    ]};
  snapshotView.episodePricing = {resolved_price: '9999.00', pet_size: 'extra_large'};
  assert.equal(snapshotView.baseRate, '1650.00', 'Display uses the admission rate even while active');
  assert.equal(snapshotView.baseSize, 'medium');
  assert.equal(snapshotView.weightLabel('14.20'), '14.20 kg');
  assert.equal(snapshotView.admissionCharges.length, 1);
  assert.equal(snapshotView.admissionCharges[0].id, 1);
  assert.equal(snapshotView.additionalConfinementCharges.length, 1);
  assert.equal(snapshotView.additionalConfinementCharges[0].id, 2);
  snapshotView.episode.discharged_at = '2026-10-14T00:00:00Z';
  assert.equal(snapshotView.baseRate, '1650.00', 'Discharged view retains the same rate');
  assert.equal(state.cataloguePrice({pricing_method: 'fixed', price: '500.00'}), state.money(500));
  const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  assert.match(page, /Confined <span[^>]+x-text="confinedCount"/);
  assert.match(page, /clinicConfinementWorkspace\(\)/); assert.match(page, /charge\.vaccination_record_id \? 'Treatment'/);
  assert.match(page, />Admission Charges<\/h4>/);
  assert.match(page, />Additional Confinement Charges<\/h4>/);
  assert.doesNotMatch(page, /extra in visibleConfinementCharges/);
  assert.match(page, /dischargePreview\?\.pricing.requires_final_price/);
  assert.match(page, /x-show="canRemoveService\(entry\)"/);
  assert.equal(/x-model="(?:dischargeForm\.quantity|dischargeForm\.billable_days)"/.test(page), false);
  assert.equal(page.includes('!confinementCase?.record?.primary_veterinarian_id'), false, 'Unsaved attending veterinarian does not disable Admit Patient');
  console.log('Clinic confinement admission, monitoring, discharge, retry, pricing and permission regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
