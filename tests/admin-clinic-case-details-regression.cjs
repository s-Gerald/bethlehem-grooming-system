const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const owner = {id: 900001, recordType: 'registered', fullName: 'Sample Customer', phone: '09170000000', email: 'sample@example.test'};
const pet = {id: 900001, petName: 'Joseph (Sample)', species: 'Dog', breed: 'Mixed', gender: 'male', weight: 12.5, size: 'medium', isNeutered: false, isDeceased: false};
const cases = {};
for (let index = 1; index <= 4; index++) {
  const id = 900100 + index, reference = `CL-20261014-00${index}`;
  cases[id] = {case: {id, appointment_reference: reference, appointment_date: '2026-10-14', status: 'completed'},
    medical_record: {appointment_id: id, appointment_reference: reference, appointment_date: '2026-10-14', status: 'completed',
      primary_veterinarian_name: 'Dr. Sample Vet', recorded_by_name: 'Sample Staff',
      chief_complaint: 'Itchy skin', relevant_history: 'Signs started two days ago.', reported_signs: 'Scratching',
      physical_exam_findings: 'Mild skin redness', working_diagnosis: 'Dermatitis', differential_diagnoses: 'Allergic dermatitis',
      treatment_given: 'Skin care', vet_notes: 'Monitor skin response.',
      vitals: {temperature_c: 38.5, heart_rate_bpm: 90, respiratory_rate_bpm: 24, weight_kg: 12.5, body_condition_score: 5},
      diagnostics: [{key: 'sample-cbc', diagnostic: 'CBC', status: 'Completed', notes: 'Within reference range.'}],
      attachments: [], follow_up_date: '2026-10-21', follow_up_notes: 'Skin reassessment'},
    treatments: [], services: [], confinement: null};
  if ([2, 4].includes(index)) cases[id].treatments = [1, 2].map(n => ({id: id * 10 + n, clinic_appointment_id: id,
    clinic_appointment_reference: reference, treatment_type: n === 1 ? 'medicine' : 'vaccine', treatment_name: n === 1 ? 'Skin Care Medicine' : 'Rabies',
    inventory_item_id: n, inventory_item_name: n === 1 ? 'Skin Care Medicine' : 'Rabies', inventory_quantity: 1, inventory_unit: 'vial',
    dose_amount: 0.5, dose_unit: 'mL', route: 'subcutaneous', strength_concentration: '10 mg/mL', administration_site: 'Shoulder',
    batch_number: 'SAMPLE-LOT', product_expiry_date: '2027-10-01', administered_date: '2026-10-14', next_due_date: '2027-10-14',
    recorded_by_user_name: 'Sample Staff', state: 'published', notes: 'No adverse reaction.'}));
  if ([3, 4].includes(index)) cases[id].services = [1, 2].map(n => ({id: id * 10 + n, clinic_appointment_id: id,
    appointment: {id, appointment_reference: reference}, service_snapshot: {name: n === 1 ? 'Consultation' : 'Skin Scraping'},
    notes: n === 1 ? '' : 'Sample collected', applied_price: n === 1 ? 500 : 250, performed_at: '2026-10-14T10:00:00+08:00'}));
  if (index === 4) cases[id].confinement = {id: 900201, clinic_appointment_id: id,
    appointment: {id, appointment_reference: reference}, case_service: {service_snapshot: {name: 'Inpatient Care'}},
    admitted_at: '2026-10-14T10:00:00+08:00', discharged_at: '2026-10-15T10:00:00+08:00',
    attending_veterinarian_name: 'Dr. Sample Vet', reason: 'Observation', notes: 'Resting comfortably', discharge_note: 'Stable on discharge'};
}
const historical = {id: 900301, treatment_name: 'External vaccine', treatment_type: 'vaccine', clinic_appointment_id: null,
  administered_date: '2025-10-01', notes: 'Historical sample record', product_name: 'External vaccine', state: 'published'};
module.exports = {owner, pet, cases, historical};

function setup(reduced = false) {
  const calls = [], focus = [], scroll = [];
  const target = {focus: () => focus.push('entry'), getBoundingClientRect: () => ({top: 450})};
  const context = {console, setTimeout, clearTimeout, document: {addEventListener() {}, body: {style: {overflow: ''}}},
    window: {matchMedia: () => ({matches: reduced}), addEventListener() {}},
    API: {adminGetPetCaseDetails: async (petId, id) => {calls.push(['case', petId, id]); return structuredClone(cases[id]);},
      getClinicConfinementObservations: async (id, page) => {calls.push(['observations', id, page]); return {data: [{id: 1, notes: 'Comfortable'}], current_page: page, last_page: 2};}}};
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), context);
  for (const file of ['admin-clinic.js', 'admin-clinic-confinement.js', 'admin-vaccinations.js'])
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8'), context);
  const state = context.adminClinicPage();
  state.profile = {open: true, pet, owner, records: Object.values(cases).map(item => item.medical_record)};
  state.$nextTick = fn => fn(); state.$store = {clinicAccess: {permissions: {access: true}}};
  state.$refs = {profileBody: {scrollTop: 240, querySelector: selector => {scroll.push(selector); return target;},
    getBoundingClientRect: () => ({top: 100}), scrollTo: args => scroll.push(args)}, profileBack: {focus: () => focus.push('back')}};
  return {state, context, calls, focus, scroll};
}

async function run() {
  const app = setup();
  for (const id of Object.keys(cases)) {
    await app.state.viewDetail(cases[id].medical_record);
    assert.equal(app.state.profileDetailTitle, 'Case Details');
    assert.equal(app.state.detail.medical.appointment_id, Number(id));
    assert.equal(app.state.detail.treatments.length, cases[id].treatments.length);
    assert.equal(app.state.detail.services.length, cases[id].services.length);
    assert.equal(!!app.state.detail.confinement, !!cases[id].confinement);
    app.state.backToProfile();
  }
  const all = cases[900104];
  for (const [kind, record] of [['medical', all.medical_record], ['treatment', all.treatments[1]], ['services', all.services[1]], ['confinement', all.confinement]]) {
    await app.state.viewDetail(record, null, kind);
    assert.equal(app.state.detail.case.id, 900104);
    assert.equal(app.state.detail.treatments.length, 2);
    assert.equal(app.state.detail.services.length, 2);
    assert.equal(app.state.profileDetailSubtitle, 'CL-20261014-004 · Oct 14, 2026');
    assert.equal(app.focus.at(-1), 'entry');
    assert.ok(app.scroll.includes(`[data-case-entry="${kind}-${Number(record.id)}"]`));
    app.state.backToProfile();
  }
  assert.equal(app.calls.filter(call => call[0] === 'case' && call[2] === 900104).length, 1, 'All four origins reuse one case request');
  assert.equal(app.calls.filter(call => call[0] === 'observations' && call[1] === 900104).length, 1, 'Reopening a case reuses its monitoring page');
  await app.state.viewDetail(all.treatments[0], null, 'treatment');
  app.context.downloadClinicAttachment = async (id, attachment) => app.calls.push(['attachment', id, attachment.id]);
  await app.state.downloadDetailAttachment({id: 99});
  assert.equal(app.calls.at(-1)[1], 900104, 'Attachments use the shared case ID even when opened from Treatment');
  await app.state.viewDetail(historical, null, 'treatment');
  assert.equal(app.state.detail.caseId, null);
  assert.equal(app.state.profileDetailTitle, 'Treatment Record');
  assert.equal(app.state.historyCaseLabel(historical), 'Historical record');
  assert.equal(app.calls.filter(call => call[0] === 'case').length, 4, 'Historical treatment does not fetch or merge a case');
  const labels = app.state.caseTreatmentFields(all.treatments[0]).map(field => field.label);
  for (const label of ['Case', 'Recorded By', 'Administration Date', 'Published By']) assert.equal(labels.includes(label), false);
  for (const label of ['Dose', 'Product', 'Batch / Lot', 'Notes']) assert.equal(labels.includes(label), true);
  const reduced = setup(true);
  await reduced.state.viewDetail(all.services[1], null, 'services');
  assert.equal(reduced.scroll.at(-1).behavior, 'instant');
  assert.equal(app.scroll.find(item => item.behavior)?.behavior, 'smooth');
  const summary = setup();
  const summaryRow = {appointment_id: 900104, appointment_reference: 'CL-20261014-004', service_count: 2, service_total: '750.00'};
  summary.context.API.adminGetPetCaseDetails = async (petId, id) => {
    summary.calls.push(['case', petId, id]);
    const data = structuredClone(cases[id]);
    data.services.forEach(service => {service.service_snapshot.name = 'Repeated service';});
    return data;
  };
  summary.state.$refs.profileBody.querySelector = selector => {
    summary.scroll.push(selector);
    return selector === '[data-case-section="services"]'
      ? {focus: () => summary.focus.push('services'), getBoundingClientRect: () => ({top: 450})} : null;
  };
  await summary.state.viewDetail(summaryRow, null, 'services');
  assert.deepEqual(summary.calls[0], ['case', pet.id, 900104], 'Case summaries use the existing details endpoint');
  assert.equal(summary.state.profileDetailTitle, 'Case Details');
  assert.equal(summary.state.detail.services.length, 2, 'Repeated services remain separate in Case Details');
  assert.equal(summary.state.detail.services[0].service_snapshot.name, summary.state.detail.services[1].service_snapshot.name);
  assert.equal(summary.focus.at(-1), 'services', 'A case summary opens the Services section without targeting an individual service');
  const stale = setup(); let finish;
  stale.context.API.adminGetPetCaseDetails = () => new Promise(resolve => {finish = resolve;});
  const opening = stale.state.viewDetail(all.medical_record);
  stale.state.backToProfile(); finish(all); await opening;
  assert.equal(stale.state.detail.record, null, 'Late case responses cannot reopen detail');
  const failed = setup(); let attempts = 0;
  failed.context.API.adminGetPetCaseDetails = async () => {if (++attempts === 1) throw Error('Sample failure'); return all;};
  await failed.state.viewDetail(all.medical_record); assert.equal(failed.state.detail.error, 'Sample failure');
  await failed.state.loadCaseDetail(); assert.equal(failed.state.detail.error, ''); assert.equal(attempts, 2);
  const trap = setup(), controls = ['first', 'last'].map(name => ({getClientRects: () => [1], focus: () => trap.focus.push(name)}));
  trap.context.document.activeElement = {compareDocumentPosition: () => 2};
  let prevented = false;
  trap.state.trapProfileFocus({currentTarget: {querySelectorAll: () => controls}, shiftKey: false, preventDefault() {prevented = true;}});
  assert.equal(prevented, true, 'Tab from a programmatically focused final section stays inside the modal');
  assert.equal(trap.focus.at(-1), 'first');
  prevented = false;
  trap.state.trapProfileFocus({currentTarget: {querySelectorAll: () => controls}, shiftKey: true, preventDefault() {prevented = true;}});
  assert.equal(prevented, false, 'Shift-Tab can reach the preceding modal controls');
  console.log('Case Details combinations, shared requests, historical isolation, entry navigation, reduced motion, stale responses and retry passed');
}
if (require.main === module) run().catch(error => {console.error(error); process.exitCode = 1;});
