const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { webcrypto } = require('node:crypto');

const root = path.resolve(__dirname, '..');
const stores = {};
const events = [];
let alpineInit;
let role = 'staff';
const focusTargets = new Map();
function focusTarget(id) {
  if (!focusTargets.has(id)) focusTargets.set(id, {
    isConnected: true, getClientRects: () => [{}],
    focus() { sandbox.document.activeElement = this; },
  });
  return focusTargets.get(id);
}
const sandbox = {
  document: { currentScript: null, activeElement: focusTarget('payment-trigger'), body: { style: { overflow: 'auto' } },
    addEventListener(name, callback) { if (name === 'alpine:init') alpineInit = callback; }, getElementById: focusTarget },
  window: { addEventListener() {}, dispatchEvent(event) { events.push(event); }, location: new URL('https://example.test/pages/admin/clinic.html') },
  Alpine: { store(name, value) { stores[name] = value; } },
  CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
  API: { getUserRole: () => role },
  crypto: webcrypto, URL, URLSearchParams, setTimeout, clearTimeout,
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), sandbox);
for (const file of ['clinic-feedback.js', 'cash-payment.js', 'admin-clinic.js', 'admin-clinic-case-workspace.js', 'admin-vaccinations.js', 'admin-sidebar.js']) {
  vm.runInContext(fs.readFileSync(path.join(root, 'scripts/components', file), 'utf8'), sandbox, {
    filename: path.join(root, 'scripts/components', file),
    importModuleDynamically: vm.constants.USE_MAIN_CONTEXT_DEFAULT_LOADER,
  });
}
alpineInit();
const feedback = { confirm: async () => true, notify() {} };
function component(factory) {
  const value = sandbox[factory]();
  value.$store = { clinicAccess: stores.clinicAccess, clinicFeedback: feedback };
  value.$refs = {};
  value.$el = { querySelector() { return null; } };
  value.$nextTick = (callback) => callback();
  return value;
}
function permissions(clinical, payment, queue, customers) {
  stores.clinicAccess.permissions = { access: true, intake: true, clinical, payment, queue, customers };
}

(async () => {
  let calls = [];
  for (const name of ['clinicSaveRecord', 'clinicUploadAttachment', 'clinicDeleteAttachment', 'startClinicCase', 'clinicRecordPayment', 'clinicCancel', 'createAdminPetVaccination', 'publishAdminPetVaccination']) {
    sandbox.API[name] = async () => { calls.push(name); };
  }
  permissions(false, true, true, true);
  const receptionistRecord = component('adminClinicModal');
  receptionistRecord.currentAppt = { status: 'in_consultation' };
  assert.equal(receptionistRecord.canEditClinical, false);
  await receptionistRecord.saveRecord(true);
  const receptionistImaging = component('clinicImagingWorkspace');
  receptionistImaging.canEditClinical = receptionistRecord.canEditClinical;
  await receptionistImaging.uploadImagingAttachment({}, {target: {files: [{name: 'image.png', size: 100}]}});
  await receptionistImaging.removeImagingService({});
  const receptionist = component('adminClinicPage');
  await receptionist.startCase({ id: 1 });
  const eventCount = events.length;
  for (const status of ['checked_in', 'in_consultation', 'for_payment']) {
    receptionist.openCase({id: 1, status});
    assert.equal(events.length, eventCount, 'Reception cannot open a clinical workspace');
    receptionistRecord.currentAppt = {id: 1, status};
    assert.equal(receptionistRecord.canEditClinical, false);
    assert.equal(receptionistRecord.canFinish, false);
  }
  receptionist.profile = { pet: { id: 7, petName: 'Mochi' }, owner: { fullName: 'Ana Santos' }, open: true };
  let openedPet;
  receptionist.activeCases = [{id: 1, status: 'checked_in', pet: {id: 7}}];
  sandbox.API.getCurrentPetClinicCase = async () => ({case: receptionist.activeCases[0]});
  await receptionist.loadProfileVisit();
  receptionist.openCase = appt => {openedPet = appt.pet.id;};
  sandbox.API.createClinicCase = async () => { throw new Error('Viewing must not create a case'); };
  receptionist.openProfileCase();
  assert.equal(openedPet, undefined, 'Reception views history in Patient Records');
  assert.equal(receptionist.caseActionLabel(receptionist.activeCases[0]), '');
  assert.equal(receptionist.profileActionLabel, '');
  assert.equal(receptionist.profileStatusLabel, 'Checked In');
  receptionist.activeCases[0].status = 'in_consultation';
  receptionist.openProfileCase();
  assert.equal(openedPet, undefined);
  assert.equal(receptionist.profileStatusLabel, 'In Consultation');
  assert.equal(receptionist.canEditPatient, false);
  await receptionist.editPatient();
  assert.equal(receptionist.profileEditing, false);
  for (const status of ['waiting_to_arrive', 'checked_in', 'in_consultation', 'for_payment', 'completed']) {
    assert.equal(receptionist.canCancelCase({status}), status === 'checked_in');
  }
  let cancellations = 0, refreshes = 0, profileRefreshes = 0;
  sandbox.API.clinicCancel = async () => {cancellations++; throw new Error('Case already started');};
  receptionist.loadActiveCases = async ({force} = {}) => {assert.equal(force, true); refreshes++;};
  receptionist.loadProfileVisit = async () => {profileRefreshes++;};
  receptionist.profileVisit = {id: 1};
  await receptionist.cancelCase({id: 1, status: 'in_consultation'});
  assert.equal(cancellations, 0);
  await receptionist.cancelCase({id: 1, status: 'checked_in'});
  assert.equal(cancellations, 1);
  assert.equal(refreshes, 1, 'A stale cancellation forces current case data to reload');
  assert.equal(profileRefreshes, 1);
  sandbox.API.clinicCancel = async () => {};
  const receptionistVaccines = component('adminClinicVaccinations');
  receptionistVaccines.pet = { id: 1 };
  receptionistVaccines.appointment = { id: 1, status: 'in_consultation' };
  receptionistVaccines.openCreateForm();
  await receptionistVaccines.saveTreatment();
  await receptionistVaccines.removeTreatment({});
  assert.equal(receptionistVaccines.formModal.open, false);
  assert.deepEqual(calls, []);

  let checkIns = 0;
  sandbox.API.clinicCheckIn = async () => { checkIns++; return { appointment: { id: 1, status: 'checked_in' } }; };
  receptionist.loadActiveCases = async () => {};
  await receptionist.checkInCase({ id: 1, status: 'waiting_to_arrive' });
  assert.equal(checkIns, 1);
  const paymentCase = { id: 1, status: 'for_payment', total_amount: '600.00', charges: [{ description: 'Exam', amount: '600.00' }] };
  receptionist.reviewPayment(paymentCase);
  assert.equal(receptionist.payment.open, true);
  assert.equal(sandbox.document.body.style.overflow, 'hidden');
  assert.equal(sandbox.document.activeElement, focusTarget('clinic-cash-received'));
  let payments = 0;
  sandbox.API.clinicRecordPayment = async (id, payload) => {
    payments++;
    assert.equal(id, 1);
    assert.equal(payload.total_amount, '600.00');
    assert.equal(payload.payment_method, 'cash');
    assert.equal(payload.amount_paid, 700);
    return { appointment: { ...paymentCase, status: 'completed', paid: true,
      payment_method: 'cash', cash_received: '700.00', change: 100 } };
  };
  assert.equal(receptionist.canConfirmPayment, false);
  receptionist.payment.amountPaid = '700';
  await receptionist.recordPayment();
  assert.equal(payments, 1);
  assert.equal(receptionist.payment.open, true, 'The recorded receipt stays open until Done');
  assert.equal(receptionist.payment.recorded, true);
  assert.equal(receptionist.payment.error, '');
  receptionist.closePayment();
  assert.equal(receptionist.payment.open, false);
  assert.equal(sandbox.document.body.style.overflow, 'auto');
  assert.equal(sandbox.document.activeElement, focusTarget('payment-trigger'));

  permissions(true, true, true, true);
  const vet = component('adminClinicPage');
  vet.loadActiveCases = async () => {};
  await vet.checkInCase({ id: 2, status: 'waiting_to_arrive' });
  assert.equal(checkIns, 2);
  vet.reviewPayment(paymentCase);
  assert.equal(vet.payment.open, true);
  vet.payment.amountPaid = '700';
  await vet.recordPayment();
  assert.equal(payments, 2);
  assert.equal(vet.payment.recorded, true);
  vet.closePayment();
  assert.equal(vet.payment.open, false);
  assert.equal(sandbox.document.body.style.overflow, 'auto');
  assert.equal(sandbox.document.activeElement, focusTarget('payment-trigger'));
  await vet.cancelCase({ id: 1 });
  sandbox.API.startClinicCase = async () => ({ case: { id: 1, status: 'in_consultation' } });
  let openedCase;
  vet.openCase = (item) => { openedCase = item; };
  await vet.startCase({ id: 1, status: 'checked_in' });
  assert.equal(openedCase.status, 'in_consultation');
  assert.equal(vet.caseStatus({ status: 'for_payment' }), 'For Payment');

  const record = component('adminClinicModal');
  record.currentAppt = { status: 'in_consultation' };
  record.apptId = 1;
  Object.assign(record.form, { primary_veterinarian_id: '1', weight_kg: '4.20', temperature_c: 38.5, heart_rate_bpm: 80, respiratory_rate_bpm: 20 });
  let recordSaveCalls = 0;
  sandbox.API.clinicSaveRecord = async (id, payload) => {
    recordSaveCalls++;
    assert.equal(id, 1);
    assert.equal(payload.finish_case, true);
    assert.equal('services' in payload, false);
    assert.equal('medications' in payload, false);
    assert.equal(payload.weight_kg, '4.20');
  };
  await record.saveRecord(true);
  assert.equal(recordSaveCalls, 1);
  for (const weight of ['4.780', '4.200000', '10.555']) {
    record.form.weight_kg = weight;
    await record.saveRecord(true);
    assert.equal(recordSaveCalls, 1, 'Invalid precision cannot reach the clinic API');
    assert.equal(record.form.weight_kg, weight);
    assert.equal(record.fieldError('weight_kg'), 'Enter weight using no more than 2 decimal places.');
  }
  record.form.weight_kg = '4.20';
  await record.saveRecord(true);
  assert.equal(record.fieldError('weight_kg'), '');
  assert.equal(recordSaveCalls, 2);
  assert.equal(record.modalError, '');
  assert.equal('addMedication' in record, false);
  assert.equal('finalizeProduct' in record, false);
  assert.equal('loadClinicalItems' in record, false);
  record.currentAppt.status = 'for_payment';
  assert.equal(record.canEditClinical, false);
  role = 'admin';
  assert.equal(record.canEditClinical, true);
  assert.equal(record.canFinish, false);

  role = 'staff';
  for (const [staff_type, staff_subrole, label] of [
    ['clinic', 'veterinarian', 'Veterinarian'], ['clinic', 'clinic_receptionist', 'Clinic Receptionist'],
    ['grooming', 'grooming_receptionist', 'Grooming Receptionist'], ['grooming', null, 'Staff'], [null, null, 'Staff'],
  ]) {
    const canViewServices = ['veterinarian', 'clinic_receptionist', 'grooming_receptionist'].includes(staff_subrole);
    const clinic_permissions = {services_view: canViewServices, services_manage: false};
    sandbox.API.getMe = sandbox.API.getStaffIdentity = async () => ({ user: { first_name: 'Ana', last_name: 'Santos', staff_type, staff_subrole, clinic_permissions } });
    const sidebar = sandbox.adminSidebar();
    await sidebar.loadLoggedInIdentity();
    assert.equal(sidebar.staffRoleLabel, label);
    assert.equal(sidebar.clinicPermissions.services_view, canViewServices);
  }
  const adminSidebar = sandbox.adminSidebar();
  adminSidebar.isAdmin = true;
  sandbox.API.getStaffIdentity = async () => ({user: {clinic_permissions: {services_view: true, services_manage: true}}});
  await adminSidebar.loadLoggedInIdentity();
  assert.equal(adminSidebar.clinicPermissions.services_view, true);
  const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
  const filters = page.slice(page.indexOf('aria-label="Case status filters"'), page.indexOf('<section x-show="[\'active-cases\''));
  const filterOrder = [...filters.matchAll(/@click="setTab\('([^']+)'\)"/g)].map(match => match[1]);
  assert.deepEqual(filterOrder, ['expected', 'active-cases', 'confined', 'for-payment']);
  assert.match(filters, /id="clinic-for-payment-filter"[^>]*:aria-pressed="tab === 'for-payment'"[^>]*@click="setTab\('for-payment'\)"/);
  assert.equal(filters.includes('justify-between'), false, 'Small screens use natural spacing and wrapping');
  assert.equal((page.match(/Save &amp; Send to Payment/g) || []).length, 1);
  assert.match(page, /x-show="caseItem.status === 'for_payment'"[^>]*>[\s\S]*?x-text="money\(caseItem.total_amount\)"/);
  for (const label of ['Medications', 'Clinical Products Used', 'Finalize Use', 'productBusy']) {
    assert.equal(page.includes(label), false, `${label} must be removed`);
  }
  assert.match(page, /aria-label="Billing"/);
  assert.match(fs.readFileSync(path.join(root, 'scripts/components/clinic-record-presentation.js'), 'utf8'), /Attachments<\/p>/);
  assert.match(page, /Follow-up<\/h3>/);
  assert.equal((page.match(/x-for="caseItem in caseRows"/g) || []).length, 1);
  receptionist.clinicToday = '2026-10-24';
  assert.equal(receptionist.caseActionLabel({status: 'waiting_to_arrive', appointment_date: '2026-10-25'}), '');
  assert.equal(receptionist.caseActionLabel({status: 'waiting_to_arrive', appointment_date: '2026-10-24'}), 'Check In');
  permissions(true, true, true, true);
  const casePage = component('adminClinicPage');
  const actions = [];
  casePage.checkInCase = item => actions.push(['check-in', item.id]);
  casePage.startCase = item => actions.push(['start', item.id]);
  casePage.reviewPayment = item => actions.push(['payment', item.id]);
  casePage.openCase = (item, section) => actions.push(['open', item.id, section]);
  for (const [status, label] of Object.entries({waiting_to_arrive: 'Check In', checked_in: 'Start Consultation', in_consultation: 'Open Case', for_payment: 'Review & Collect Payment'})) {
    assert.equal(casePage.caseActionLabel({status}), label);
    casePage.runCaseAction({id: 1, status});
  }
  assert.equal(actions[0][0], 'check-in');
  assert.equal(actions[1][0], 'start');
  assert.equal(actions[2][0], 'open');
  assert.equal(actions[3][0], 'payment', 'Payment action opens the existing payment review directly');
  casePage.activeCases = [{id: 1, status: 'in_consultation'}, {id: 2, status: 'for_payment'},
    {id: 3, status: 'in_consultation', confinement: {discharged_at: null}}];
  assert.equal(casePage.activeCount, 1);
  assert.equal(casePage.forPaymentCount, 1);
  assert.equal(casePage.visibleActiveCases[0].id, 1);
  casePage.tab = 'for-payment';
  assert.equal(casePage.visibleActiveCases.length, 1);
  assert.equal(casePage.visibleActiveCases[0].id, 2);
  permissions(false, false, false, false);
  assert.equal(casePage.caseActionLabel({status: 'for_payment'}), '');
  assert.equal(casePage.caseActionLabel({status: 'checked_in'}), '');
  const actionCount = actions.length;
  casePage.runCaseAction({id: 1, status: 'checked_in'});
  casePage.runCaseAction({id: 1, status: 'for_payment'});
  assert.equal(actions.length, actionCount, 'Clinic access alone cannot open the clinical workspace');
  const chargesModal = component('adminClinicModal');
  chargesModal.$nextTick = () => {};
  chargesModal.currentAppt = {id: 1, status: 'for_payment'};
  chargesModal.activeSection = 'services';
  chargesModal.selectSection('billing');
  assert.equal(chargesModal.chargesOpen, false, 'Charges requires billing permission');
  stores.clinicAccess.permissions.billing_view = true;
  chargesModal.selectSection('billing');
  assert.equal(chargesModal.chargesOpen, true);
  assert.equal(chargesModal.activeSection, 'services', 'Charges preserves the clinical section');
  chargesModal.closeCharges();
  assert.equal(chargesModal.activeSection, 'services');
  assert.equal(chargesModal.chargesOpen, false);
  console.log('Clinic subrole workflow regression checks passed');
})().catch((error) => { console.error(error); process.exitCode = 1; });
