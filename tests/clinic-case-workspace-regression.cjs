const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'pages/admin/clinic.html'), 'utf8');
const source = fs.readFileSync(path.join(root, 'scripts/components/admin-clinic-case-workspace.js'), 'utf8');
const fixed = {id: 1, name: 'Consultation', pricing_method: 'fixed', price: '500.00', billing_unit: 'visit'};
const range = {id: 2, name: 'X-Ray', pricing_method: 'range', min_price: '800.00', max_price: '1500.00'};
const starting = {id: 3, name: 'Procedure', pricing_method: 'starting_at', price: '1000.00'};
const tiered = {id: 4, name: 'Lab test', pricing_method: 'tiered'};
const services = [fixed, range, starting, tiered];
const storage = new Map();
const submissions = [], removals = [], events = [], notifications = [], confirmations = [];
let serviceLoads = 0, billingLoads = 0, confirmRemoval = true, delayedRemoval = null;
const billingScopes = [];
let role = 'staff', permission = {clinical: true, billing_view: true, payment: false};
let failSubmission = false, delayedOptions = null;
const preview = service => ({requires_final_price: ['range', 'starting_at'].includes(service.pricing_method),
  min_price: service.min_price || service.price, max_price: service.max_price || null, technical_max_price: 100000,
  resolved_price: service.pricing_method === 'tiered' ? '600.00' : service.price, pet_size: 'medium', pet_weight_kg: '18.40', error: null});
const sandbox = {
  console, setTimeout, clearTimeout, crypto: webcrypto,
  sessionStorage: {setItem: (key, value) => storage.set(key, value), getItem: key => storage.get(key) || null, removeItem: key => storage.delete(key)},
  CustomEvent: class {constructor(type, options) {this.type = type; this.detail = options?.detail;}},
  window: {dispatchEvent: event => events.push(event)},
  API: {
    getUserRole: () => role,
    getClinicCaseServices: async () => {++serviceLoads; return {data: [], current_page: 1, last_page: 1};},
    getClinicCaseCharges: async (id, page, scope) => {++billingLoads; billingScopes.push(scope); return {total_amount: '1734.00', charges: {data: [
      {id: 1, description: 'Performed consultation', clinic_case_service_id: 1, quantity: 1, unit_price: 500, amount: 500},
      {id: 2, description: 'Medicine', kind: 'medicine', quantity: 1, unit_price: 1234, amount: 1234},
    ], current_page: 1, last_page: 1}};},
    clinicCaseServiceOptions: async () => delayedOptions || ({data: services, current_page: 1, last_page: 1}),
    clinicCaseServicePricing: async (id, serviceId) => {const service = services.find(row => row.id === serviceId); return {data: service, pricing: preview(service)};},
    clinicCreateCaseService: async (id, payload) => {submissions.push({id, payload: {...payload}}); if (failSubmission) throw new Error('timeout'); return {data: {id: 5}};},
    clinicDeleteCaseService: async (id, serviceId) => {removals.push({id, serviceId}); return delayedRemoval || {message: 'Service removed.'};},
  },
};
vm.createContext(sandbox);
vm.runInContext(source, sandbox);
function component(id = 1) {
  const state = sandbox.clinicCaseWorkspace();
  state.$store = {clinicAccess: {permissions: permission, user: {user_id: 7}}, clinicFeedback: {notify: message => notifications.push(message), confirm: async options => {confirmations.push(options); return confirmRemoval;}}};
  state.$refs = {}; state.$nextTick = callback => callback();
  state.syncWorkspace(true, {id, status: 'in_consultation', vitals: {weight_kg: 18.4}, pet: {size: 'small', weight: 1}}, 'services');
  return state;
}
const settle = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const state = component();
  await state.selectService(fixed);
  assert.equal(state.needsFinalPrice, false);
  assert.equal(state.servicePricing.resolved_price, '500.00');
  state.serviceForm.notes = 'Provided during visit';
  state.serviceForm.applied_price = '400';
  await state.submitService();
  assert.equal('quantity' in submissions.at(-1).payload, false, 'The backend supplies quantity one');
  assert.equal('status' in submissions.at(-1).payload, false, 'The backend knows the service was performed');
  assert.equal(submissions.at(-1).payload.notes, 'Provided during visit');
  assert.equal(notifications.at(-1), 'Service added.');
  assert.ok(billingLoads > 0, 'Addition refreshes Billing');
  assert.equal('applied_price' in submissions.at(-1).payload, false, 'Fixed does not send a manual price');
  assert.equal(state.serviceForm, null);
  assert.equal(state.searchQuery, '');
  assert.equal(state.searchPage, 0, 'Clearing the form does not display a stale empty search result');

  await state.selectService(range);
  assert.equal(state.needsFinalPrice, true);
  assert.match(state.priceRule(), /800\.00.*1,500\.00/);
  for (const invalid of ['', '799.99', '1500.01', '-1', '1e3', '800.001']) {
    state.serviceForm.applied_price = invalid;
    const before = submissions.length;
    await state.submitService();
    assert.equal(submissions.length, before);
    assert.ok(state.serviceErrors.applied_price);
    assert.equal(state.serviceForm.applied_price, invalid, 'Validation retains entered values');
  }
  for (const valid of ['800', '1250', '1500']) {state.serviceForm.applied_price = valid; assert.equal(state.validateService(), true);}
  state.serviceForm.applied_price = '1250';
  await state.submitService();
  assert.equal(submissions.at(-1).payload.applied_price, '1250');

  await state.selectService(starting);
  assert.equal(state.needsFinalPrice, true);
  assert.match(state.priceRule(), /Starting at.*1,000\.00/);
  state.serviceForm.applied_price = '999.99';
  assert.equal(state.validateService(), false);
  assert.match(state.serviceErrors.applied_price, /at least/);
  state.serviceForm.applied_price = '1800';
  assert.equal(state.validateService(), true, 'No Grooming +500 limit');
  await state.submitService();
  assert.equal(submissions.at(-1).payload.applied_price, '1800');

  await state.selectService(tiered);
  assert.equal(state.needsFinalPrice, false);
  assert.equal(state.servicePricing.pet_weight_kg, '18.40');
  assert.equal(state.servicePricing.pet_size, 'medium');
  state.servicePricing.error = "Record the pet's weight in Vitals before performing this service.";
  const beforeTiered = submissions.length;
  await state.submitService();
  assert.equal(submissions.length, beforeTiered);
  state.servicePricing.error = null;
  await state.submitService();
  assert.equal('applied_price' in submissions.at(-1).payload, false, 'Tiered has no client price');

  await state.selectService(fixed);
  const key = state.serviceForm.request_key;
  failSubmission = true;
  await state.submitService();
  assert.ok(state.pendingPayload);
  assert.ok(storage.size);
  state.dismissService();
  assert.equal(state.serviceForm.request_key, key, 'Unconfirmed requests cannot be discarded and recreated');
  const reloaded = component();
  await settle();
  assert.equal(reloaded.serviceForm.request_key, key, 'Reload restores the UUID');
  assert.equal(reloaded.pendingPayload.request_key, key);
  failSubmission = false;
  await reloaded.submitService();
  assert.equal(submissions.at(-1).payload.request_key, submissions.at(-2).payload.request_key);
  assert.equal(storage.size, 0, 'Confirmed success clears the retry draft');

  await reloaded.selectService(fixed);
  const createService = sandbox.API.clinicCreateCaseService;
  let completeAdd;
  sandbox.API.clinicCreateCaseService = async (id, payload) => {
    submissions.push({id, payload}); return new Promise(resolve => {completeAdd = resolve;});
  };
  const adding = reloaded.submitService();
  const countWhileAdding = submissions.length;
  await reloaded.submitService();
  assert.equal(submissions.length, countWhileAdding, 'Duplicate clicks submit once');
  assert.equal(reloaded.serviceSaving, true);
  completeAdd({data: {id: 9}});
  await adding;
  assert.equal(reloaded.serviceSaving, false);
  sandbox.API.clinicCreateCaseService = createService;

  const entry = {id: 9, status: 'performed', notes: null, applied_price: '650.00', charge: {amount: '650.00'},
    service_snapshot: {name: 'Anti Rabies'}, confinement_exists: false};
  assert.equal(reloaded.serviceNotes(entry), '-');
  assert.equal(reloaded.serviceNotes({...entry, notes: ''}), '-');
  assert.equal(reloaded.serviceNotes({...entry, notes: '   '}), '-');
  assert.equal(reloaded.serviceNotes({...entry, notes: 'Blood test requested'}), 'Blood test requested');
  assert.equal(reloaded.canRemoveService({...entry, confinement_exists: true}), false, 'Historical Confinement stays protected');
  confirmRemoval = false;
  await reloaded.removeService(entry);
  assert.equal(removals.length, 0, 'Dismissing confirmation does not remove a service');
  assert.equal(reloaded.serviceRemovingId, null);
  assert.equal(confirmations.at(-1).title, 'Remove service?');
  assert.equal(confirmations.at(-1).message, `Remove "Anti Rabies" from this case? Its ${reloaded.money('650')} charge will also be removed from Billing.`);
  assert.equal(confirmations.at(-1).confirmLabel, 'Remove Service');
  confirmRemoval = true;
  let completeRemove;
  delayedRemoval = new Promise(resolve => {completeRemove = resolve;});
  const servicesBeforeRemove = serviceLoads, billingBeforeRemove = billingLoads;
  const removing = reloaded.removeService(entry);
  await settle();
  assert.equal(reloaded.serviceRemovingId, entry.id);
  await reloaded.removeService(entry);
  assert.equal(removals.length, 1, 'Duplicate removal clicks send one request');
  completeRemove({message: 'Service removed.'});
  await removing;
  assert.equal(reloaded.serviceRemovingId, null);
  assert.equal(serviceLoads, servicesBeforeRemove + 1);
  assert.equal(billingLoads, billingBeforeRemove + 1);
  assert.equal(notifications.at(-1), 'Service removed.');
  delayedRemoval = null;
  sandbox.API.clinicDeleteCaseService = async () => {throw new Error('Services cannot be removed at this case stage.');};
  await reloaded.removeService(entry);
  assert.equal(reloaded.serviceRemovingId, null, 'Failure unlocks the action');
  assert.match(reloaded.servicesError, /cannot be removed/);

  const historical = {service_snapshot: {name: 'Original name', pet_size: 'medium', pet_weight_kg: '18.40'}, clinic_service: {name: 'Renamed service'}};
  assert.equal(state.serviceInfo(historical).name, 'Original name');
  await state.loadBilling();
  assert.equal(state.billingTotal, '1734.00', 'Uses server total instead of calculating another total');
  assert.equal(billingScopes.at(-1), 'treatment', 'Billing requests Treatment charges independently from services');
  assert.equal(state.treatmentCharges.length, 1);
  assert.equal(state.treatmentCharges[0].description, 'Medicine');
  assert.equal(state.chargeRows[1].kind, 'medicine');
  state.requestCasePayment();
  assert.equal(events.some(event => event.type === 'clinic-review-payment'), false, 'Vet cannot open payment');
  permission = {clinical: false, billing_view: true, payment: true};
  const receptionist = component(2);
  assert.equal(receptionist.canMutateServices, false);
  const beforeReceptionist = submissions.length;
  await receptionist.selectService(fixed);
  await receptionist.submitService();
  await receptionist.removeService({id: 5, status: 'performed'});
  assert.equal(submissions.length, beforeReceptionist);
  receptionist.workspaceAppointment = {id: 2, status: 'for_payment', charges: []};
  await receptionist.loadBilling();
  await receptionist.requestCasePayment();
  assert.equal(billingScopes.at(-1), '', 'Payment still receives the complete charge breakdown');
  assert.equal(events.at(-1).type, 'clinic-review-payment');
  assert.equal(events.at(-1).detail.total_amount, '1734.00');
  assert.equal(events.at(-1).detail.charges.length, 2, 'Payment receives the reviewed charges instead of a stale case array');
  receptionist.chargeLastPage = 2;
  sandbox.API.getClinicCaseCharges = async (id, page) => ({total_amount: '2000.00', charges: {
    data: [{id: 3, description: 'Additional treatment', amount: '266.00'}], current_page: page, last_page: 2,
  }});
  await receptionist.requestCasePayment();
  assert.equal(events.at(-1).detail.charges.length, 3, 'Remaining pages are included in payment review');
  assert.equal(events.at(-1).detail.total_amount, '2000.00');
  permission = {clinical: true, billing_view: true, payment: true}; role = 'admin';
  const admin = component(3);
  for (const locked of [{status: 'checked_in'}, {status: 'waiting_to_arrive'}, {status: 'completed'}, {status: 'in_consultation', paid: true}, {status: 'in_consultation', archived_at: '2026-10-13'}]) {
    admin.workspaceAppointment = {id: 3, ...locked};
    assert.equal(admin.canMutateServices, false);
  }
  admin.workspaceAppointment = {id: 3, status: 'for_payment'};
  assert.equal(admin.canMutateServices, true, 'Intentional admin payment-stage corrections are preserved');

  permission = {clinical: true, billing_view: true, payment: false}; role = 'staff';
  const stale = component(4);
  let resolveOptions;
  delayedOptions = new Promise(resolve => {resolveOptions = resolve;});
  const pending = stale.loadOptions();
  stale.syncWorkspace(false);
  resolveOptions({data: [fixed], current_page: 1, last_page: 1});
  await pending;
  assert.equal(stale.searchRows.length, 0, 'Closed case ignores an in-flight catalogue response');

  const billingMarkup = page.slice(page.indexOf('<section id="case-billing-panel"'), page.indexOf('<section :inert="chargesOpen" id="case-confinement-panel"'));
  assert.equal(/<input|<select|Edit Price|Discount/.test(billingMarkup), false, 'Billing has no pricing editor');
  assert.match(page, /<template x-if="needsFinalPrice">/);
  assert.match(page, /Go to Vitals/);
  assert.match(page, /x-show="canRecordCasePayment"/);
  assert.match(page, /x-show="\$store\.clinicAccess\.permissions\.billing_view"/);
  const serviceHistory = page.slice(page.indexOf('aria-label="Case service history"'), page.indexOf('Load more services'));
  assert.equal(/>Qty<|>Status<|>Recorded<|Staff #|serviceDate|statusClass|categoryLabel/.test(serviceHistory), false);
  assert.match(serviceHistory, /x-if="serviceInfo\(entry\).billing_unit === 'shot'"/);
  assert.match(serviceHistory, /table-fixed/);
  assert.match(serviceHistory, /<colgroup>/);
  assert.match(serviceHistory, /x-text="serviceNotes\(entry\)"/);
  assert.match(serviceHistory, /x-text="money\(entry.applied_price\)"/);
  assert.match(serviceHistory, /@click="removeService\(entry\)"/);
  const servicesPanel = page.slice(page.indexOf('<section id="case-services-panel"'), page.indexOf('Load more services'));
  assert.equal(/Order for Later|Record Performed|Mark Performed|Cancel Order|serviceForm.status|categoryLabel|methodLabel|Saved case weight|Pricing tier|case-service-quantity/.test(servicesPanel), false);
  assert.match(servicesPanel, /x-show="selectedService\?\.billing_unit === 'shot'"/);
  assert.match(servicesPanel, /'Add Service'/);
  assert.match(servicesPanel, /No services added yet\./);
  const treatmentChargesMarkup = page.slice(page.indexOf('<section x-show="$store.clinicAccess.permissions.billing_view" aria-labelledby="treatment-charges-heading"'), page.indexOf('<div x-show="chargesOpen"'));
  assert.match(treatmentChargesMarkup, /Treatment Charges/);
  assert.match(treatmentChargesMarkup, /x-text="charge.description"/);
  assert.match(treatmentChargesMarkup, /x-text="money\(charge.amount\)"/);
  assert.doesNotMatch(treatmentChargesMarkup, /Remove|@click="remove|<input|<select|request_key|inventory_item_id|pricing_method/);
  assert.match(page, /phosphor\.svg(?:\?[^"#\s]+)?#clipboard-text[^\n]* Billing/);
  assert.equal(/role="tab"[^>]*aria-controls="case-billing-panel"/.test(page), false, 'Charges is contextual, not a clinical tab');
  assert.match(source, /service_snapshot \|\| entry.clinic_service/);
  assert.equal(/sizeFor|groomingSize|\+ 500|\+ 200/.test(source), false, 'JS does not recreate size or pricing rules');
  console.log('Clinic case workspace pricing, history, retry, billing and permission regressions passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
