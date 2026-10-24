const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = name => fs.readFileSync(path.join(__dirname, '..', name), 'utf8');

function setup(permissions = {services_view: true, services_manage: true}, role = 'admin', subrole = null) {
  const calls = [], panels = [], listeners = new Map(), scripts = [];
  const clinicAccess = {permissions, user: {role, staff_subrole: subrole, clinic_permissions: permissions,
    staff_permissions: {grooming_services_view: role === 'admin' || subrole === 'clinic_receptionist', services_manage: role === 'admin'}}, init: async () => {}};
  let failMarkup = false;
  const context = {
    URLSearchParams, clearTimeout, setTimeout, console,
    location: {hash: '#clinic', replace(url) {calls.push(['redirect', url]);}}, history: {replaceState(_, __, hash) {context.location.hash = hash;}},
    scrollTo() {}, addEventListener: (name, listener) => listeners.set(name, listener),
    removeEventListener: name => listeners.delete(name),
    adminClinicPage: () => ({tab: 'active-cases', init() {calls.push('cases:init');}, loadAllRecords() {calls.push('records:load');}}),
    API: {getUserRole: () => role, getCachedStaffIdentity: () => ({user: clinicAccess.user}), loadAdminData: (key,load,render) => load().then(data=>{render(data);return data;}), adminRequest: async (method, url, payload) => {
      calls.push([method, url, payload]);
      return url.endsWith('/options') ? {categories: [{id: 2, name: 'Laboratory'}]}
        : method !== 'GET' ? {data: {}}
        : {data: [], total: 0, current_page: 1, last_page: 1};
    }},
    fetch: async () => {calls.push('services:markup'); return {ok: !failMarkup, text: async () => ''};},
    document: {title: '', head: {appendChild(script) {
      calls.push('services:script');
      scripts.push(script.src);
      if (!script.src.includes('inventory-money-input')) vm.runInContext(read('scripts/components/'+script.src.split('/').pop().split('?')[0]), context);
      queueMicrotask(script.onload);
    }}, createElement: () => ({style: {}, kind: '', setAttribute(name, value) {if (name === 'x-data') this.kind = value;}, querySelector: () => null, remove() {}})},
    Alpine: {mutateDom: fn => fn(), initTree(panel) {panel.state = panel.kind === 'adminClinicServices()' ? component(context) : {activate() {}, closeEditor() {}};}, $data: panel => panel.state},
    clinicAccess,
  };
  context.window = context;
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-clinic-shell.js'), context);
  vm.runInContext(read('scripts/components/admin-services.js'), context);
  const shell = context.adminServices();
  shell.showSection = async () => {await clinicAccess.init(); shell.applyIdentity(clinicAccess.user); return shell.showTab();};
  shell.$store = {clinicAccess};
  const cases = {state: context.adminClinicCasesSection()};
  const groomingPanels = [];
  shell.$refs = {cases: {firstElementChild: cases}, clinic: {get firstElementChild() {return panels[0];}, appendChild: panel => panels.push(panel)}, grooming: {get firstElementChild() {return groomingPanels[0];}, appendChild: panel => groomingPanels.push(panel)}};
  shell.$nextTick = fn => fn();
  return {context, shell, calls, panels, listeners, scripts, failMarkup: value => {failMarkup = value;},
    visit: async hash => {context.location.hash = hash; await shell.showSection();}};
}

function component(context) {
  const state = context.adminClinicServices();
  const dialog = () => ({open: false, showModal() {this.open = true;}, close() {this.open = false;}});
  state.$refs = {serviceDialog: dialog(), serviceForm: {querySelector: () => null, reportValidity: () => true}, detailsDialog: dialog(), detailsClose: {focus() {}}, serviceName: {focus() {}}, formError: {focus() {}}};
  state.$nextTick = fn => fn();
  state.$store = {clinicAccess: context.clinicAccess, clinicFeedback: {notify() {}, confirm: async () => true}};
  return state;
}

async function navigation() {
  const app = setup();
  await Promise.all([app.shell.showSection(), app.shell.showSection()]);
  assert.equal(app.calls.includes('cases:init'), false, 'Services does not initialize Cases');
  assert.equal(app.panels.length, 1, 'Concurrent activation mounts one partial');
  assert.equal(app.scripts.some(src=>src.includes('admin-grooming-services')),false,'Inactive component script stays unloaded');
  assert.equal(app.calls.filter(call => Array.isArray(call) && call[1].startsWith('/admin/clinic-services?')).length, 1);
  const services = app.panels[0].state;
  services.q = 'CBC';
  services.openDetails({id: 1, name: 'CBC'}, {currentTarget: {focus() {}}});
  await app.visit('#grooming');
  assert.equal(services.$refs.detailsDialog.open, false);
  await app.visit('#clinic');
  assert.equal(app.scripts.filter(src=>src.includes('admin-clinic-services')).length,1,'Clinic component is loaded once');
  assert.equal(app.panels[0].state, services);
  assert.equal(services.q, 'CBC', 'Switching tabs preserves Clinic filters');
  const legacy = app.context.adminClinicShell();
  legacy.$store = {clinicAccess: app.context.clinicAccess};
  legacy.$refs = {cases: {firstElementChild: {state: app.context.adminClinicCasesSection()}}};
  app.context.location.hash = '#services';
  await legacy.showSection();
  assert.ok(app.calls.some(call => call[0] === 'redirect' && call[1] === './services.html#clinic'));
  app.context.location.hash = '#cases';
  await legacy.showSection(); await legacy.showSection();
  assert.equal(app.calls.filter(call => call === 'cases:init').length, 1, 'Clinic cases still initialize once');
  const retry = setup();
  retry.failMarkup(true);
  await retry.shell.showSection();
  assert.match(retry.shell.error, /Unable to load/);
  retry.failMarkup(false);
  await retry.shell.showSection();
  assert.equal(retry.shell.error, '');
  assert.equal(retry.panels.length, 1);
}

async function workflow() {
  const app = setup();
  await app.shell.showSection();
  const state = app.panels[0].state;
  assert.equal(state.status, 'active');
  state.q = 'CBC'; state.category = '2'; state.status = 'inactive';
  await state.load(2);
  assert.equal(state.filtersActive, true);
  assert.ok(app.calls.some(call => Array.isArray(call) && /q=CBC.*category_id=2/.test(call[1])));
  assert.equal(new URLSearchParams(app.calls.at(-1)[1].split('?')[1]).has('type'), false);
  await state.clearFilters();
  assert.equal(state.filtersActive, false);
  state.openModal();
  assert.equal('is_active' in state.form, false, 'Status is not part of the service form');
  assert.equal('is_active' in state.payload(), false, 'Creation uses the active database default');
  assert.equal('type' in state.form, false);
  assert.equal('type' in state.payload(), false);
  state.openModal({id: 1, name: 'CBC', type: 'Send Out'});
  assert.equal('type' in state.form, false, 'Legacy Type is ignored when editing');
  assert.equal('type' in state.payload(), false);
  const inactive = {id: 3, name: 'Boarding', is_active: false, pricing_method: 'tiered', tiers: [{size: 'small', price: 500}]};
  let focused = false;
  const beforeDetails = app.calls.length;
  state.openDetails(inactive, {currentTarget: {focus() {focused = true;}}});
  assert.equal(state.details, inactive, 'Details use the full catalog record already loaded');
  assert.equal(state.$refs.detailsDialog.open, true);
  state.closeDetails();
  assert.equal(state.$refs.detailsDialog.open, false);
  assert.equal(state.details, null);
  assert.equal(focused, true, 'Closing details restores focus to the triggering row');
  assert.equal(app.calls.length, beforeDetails, 'Viewing details does not make another network request');
  state.openModal(inactive);
  assert.equal('is_active' in state.form, false);
  assert.equal('is_active' in state.payload(), false, 'Editing cannot overwrite the saved status');
  state.form.tiers[0].price = 600;
  assert.equal(inactive.tiers[0].price, 500, 'Editing tiers does not mutate catalog details before saving');
  state.openModal(); state.form.name = 'Emergency Fee'; state.form.category_id = 1;
  state.form.pricing_method = 'range'; state.form.min_price = 500; state.form.max_price = 499;
  const before = app.calls.length;
  await state.save();
  assert.equal(app.calls.length, before);
  assert.match(state.priceErrors.max_price, /Maximum Price/);
  state.form.pricing_method = 'tiered'; state.form.tiers.forEach(tier => {tier.enabled = false;});
  await state.save(); assert.match(state.formError, /at least one/);
  state.form.tiers[0].enabled = true; state.form.tiers[0].price = -1;
  await state.save(); assert.match(state.priceErrors.small, /non-negative/);
  state.form.tiers[0].price = 0;
  await state.save();
  const payload = app.calls.find(call => Array.isArray(call) && call[0] === 'POST')[2];
  assert.equal(payload.tiers.length, 1);
  assert.equal(payload.price, undefined);
  await state.changeStatus({id: 1, name: 'CBC', is_active: true});
  assert.ok(app.calls.some(call => Array.isArray(call) && call[0] === 'PATCH' && call[2].is_active === false));
  await state.changeStatus({id: 1, name: 'CBC', is_active: false});
  assert.ok(app.calls.some(call => Array.isArray(call) && call[0] === 'PATCH' && call[2].is_active === true));

  let resolveOld;
  app.context.API.adminRequest = async (_, url) => url.includes('q=old')
    ? new Promise(resolve => {resolveOld = resolve;})
    : {data: [{name: 'New'}], total: 1, current_page: 1, last_page: 1};
  state.q = 'old'; const old = state.load();
  state.q = 'new'; await state.load();
  resolveOld({data: [{name: 'Old'}], total: 1, current_page: 1, last_page: 1});
  await old;
  assert.equal(state.rows[0].name, 'New', 'Late search responses cannot overwrite newer results');
  app.context.API.adminRequest = async () => {throw new Error('Offline');};
  await state.load(); assert.equal(state.error, 'Offline'); assert.equal(state.loading, false);
}

async function readOnlyAccess() {
  const service = {id: 1, name: 'CBC', is_active: true, pricing_method: 'fixed', price: 500};
  for (const subrole of ['veterinarian', 'clinic_receptionist']) {
    const app = setup({services_view: true, services_manage: false}, 'staff', subrole);
    app.context.API.getUserRole = () => 'staff';
    await app.shell.showSection();
    assert.equal(app.shell.activeTab, 'clinic', subrole + ' can open Services directly');
    const state = app.panels[0].state;
    assert.equal(state.canManageServices, false);
    state.q = 'CBC'; state.category = '2'; state.status = 'all';
    await state.load(2);
    assert.ok(app.calls.some(call => Array.isArray(call) && /q=CBC.*category_id=2/.test(call[1])));
    state.openDetails(service, {currentTarget: {focus() {}}});
    assert.equal(state.$refs.detailsDialog.open, true);
    state.openModal(); state.openModal(service);
    await state.save(); await state.changeStatus(service);
    assert.equal(state.modalOpen, false);
    assert.equal(app.calls.some(call => Array.isArray(call) && call[0] !== 'GET'), false);
    if (subrole === 'clinic_receptionist') {
      await app.visit('#grooming');
      assert.equal(app.shell.activeTab, 'grooming', 'Receptionists can follow the Grooming deep link');
      const grooming = app.context.adminGroomingServices();
      grooming.$store = state.$store;
      await grooming.activate(); await grooming.activate();
      assert.equal(grooming.canManageServices, false);
      grooming.openEditor('package'); await grooming.save(); await grooming.changeStatus({serviceId: 1});
      assert.equal(grooming.form, null);
      assert.equal(app.calls.filter(call => Array.isArray(call) && call[1] === '/admin/grooming/services/catalogue').length, 1);
      assert.equal(app.calls.some(call => Array.isArray(call) && call[0] !== 'GET'), false);
      await app.visit('');
      assert.equal(app.shell.activeTab, 'clinic', 'Receptionists default back to Clinic Services');
    }
  }
  const denied = setup({services_view: false, services_manage: false});
  await denied.shell.showSection();
  assert.match(denied.shell.error, /do not have access/);
  assert.equal(denied.panels.length, 0);
  const page = read('pages/admin/clinic/sections/services.html');
  for (const action of ['openModal()', 'openModal(service)', 'changeStatus(service)']) {
    const tag = page.match(new RegExp('<button[^>]*@click(?:\\.stop)?="' + action.replace(/[()]/g, '\\$&') + '"[^>]*>'))?.[0];
    assert.ok(tag?.includes('x-show="canManageServices"'), action + ' must be hidden for readers');
  }
  assert.ok(page.includes('View clinic services and pricing.'));
  assert.ok(page.includes('View Details'));
  assert.ok(read('scripts/components/admin-sidebar.js').includes("services.setAttribute('x-show', 'isAdmin || clinicPermissions.services_view')"));

  const pending = setup({}, 'staff', 'clinic_receptionist');
  let finishIdentity;
  pending.context.clinicAccess.init = () => new Promise(resolve => {finishIdentity = resolve;});
  const opening = pending.shell.showSection();
  assert.equal(pending.context.location.hash, '#clinic', 'Do not redirect while permissions are loading');
  assert.equal(pending.panels.length, 0);
  pending.context.clinicAccess.permissions = {services_view: true, services_manage: false};
  pending.context.clinicAccess.user.clinic_permissions = pending.context.clinicAccess.permissions;
  finishIdentity();
  await opening;
  assert.equal(pending.shell.activeTab, 'clinic');

  const transition = setup();
  await transition.shell.init();
  transition.panels[0].state.openModal();
  assert.equal(transition.panels[0].state.modalOpen, true);
  transition.listeners.get('admin-navigation:invalidated')({detail: {reload: true}});
  assert.equal(transition.panels[0].state.modalOpen, false, 'Revoking access closes an existing service editor');
  assert.equal(transition.shell.isAdmin, false);
  assert.equal(transition.shell.canViewGrooming, false);
  assert.ok(transition.shell.error);
  transition.listeners.get('admin-navigation:updated')({detail: {user: {
    role: 'staff', clinic_permissions: {services_view: true, services_manage: false},
    staff_permissions: {grooming_services_view: true, services_manage: false},
  }}});
  await new Promise(setImmediate);
  assert.equal(transition.shell.isAdmin, false);
  assert.equal(transition.panels[0].state.canManageServices, false);
  assert.equal(transition.shell.canViewGrooming, true);
}

function formatting() {
  const markup = read('pages/admin/clinic/sections/services.html');
  assert.doesNotMatch(markup, /form\.type|service\.type|details\.type|All types|>Type</);
  assert.doesNotMatch(markup, /x-model="form\.is_active"/);
  for (const dialog of markup.matchAll(/<dialog\b[^>]*>/g)) {
    assert.ok(dialog[0].includes('@cancel.prevent @pointerdown.self.prevent'), 'Outside pointer events and Escape must not dismiss or blur');
    assert.ok(!dialog[0].includes('@click='), 'No outside-click dismissal handler');
  }
  for (const model of ['form.price', 'form.min_price', 'form.max_price', 'tier.price']) {
    const tag = [...markup.matchAll(/<input\b[^>]*>/g)].map(match => match[0]).find(tag => tag.includes(`x-model="${model}"`));
    assert.ok(tag.includes('data-money-input') && tag.includes('inputmode="decimal"'), model);
    assert.ok(tag.includes('@blur="validatePrice(') && tag.includes('@input="priceInput('), model);
    assert.ok(tag.includes(':aria-invalid=') && tag.includes('aria-describedby='), model);
  }
  assert.match(markup, /@click\.stop="openModal\(service\)"/);
  assert.match(markup, /@click\.stop="changeStatus\(service\)"/);
  assert.match(markup, /@keydown\.enter\.self\.prevent="openDetails/);
  assert.match(markup, /@keydown\.space\.self\.prevent="openDetails/);
  const context = {setTimeout, clearTimeout};
  vm.createContext(context);
  vm.runInContext(read('scripts/components/admin-clinic-services.js'), context);
  const format = context.clinicServicePricing;
  assert.equal(format({pricing_method: 'fixed', price: 990}), '₱990');
  assert.equal(format({pricing_method: 'range', min_price: 500, max_price: 1500}), '₱500–₱1,500');
  assert.equal(format({pricing_method: 'starting_at', price: 500, billing_unit: 'day'}), 'From ₱500 / day');
  assert.equal(format({pricing_method: 'fixed', price: 150, billing_unit: 'sample'}), '₱150 / sample');
  assert.equal(format({pricing_method: 'tiered', tiers: [{price: 800}, {price: 1050}]}), '₱800–₱1,050 by size');
}

async function priceValidation() {
  const app = setup();
  await app.shell.showSection();
  const state = app.panels[0].state;
  const maximumError = 'Price must be ₱100,000.00 or less.';
  for (const editing of [false, true]) {
    for (const method of ['fixed', 'starting_at', 'range', 'tiered']) {
      state.openModal(editing ? {id: 1, name: 'CBC'} : null);
      Object.assign(state.form, {name: 'CBC', category_id: 2, pricing_method: method, price: '100000.00', min_price: '100000.00', max_price: '100000.00'});
      state.form.tiers.forEach(tier => {tier.enabled = true; tier.price = '100000.00';});
      for (const field of state.priceFields()) {
        const target = method === 'tiered' ? state.form.tiers.find(tier => tier.size === field.key) : state.form;
        const property = method === 'tiered' ? 'price' : field.key;
        for (const invalid of ['100000.01', '1000000']) {
          state.priceErrors = {};
          target[property] = invalid;
          state.priceInput(field.key);
          assert.equal(state.priceErrors[field.key], undefined, 'Typing does not show an initial error');
          state.validatePrice(field.key);
          assert.equal(state.priceErrors[field.key], maximumError);
          assert.equal(target[property], invalid, 'Blur preserves the original value');
          state.priceErrors = {};
          const before = app.calls.length;
          await state.save();
          assert.equal(app.calls.length, before, 'Save blocks every over-limit pricing field');
          assert.equal(state.priceErrors[field.key], maximumError);
          assert.equal(target[property], invalid, 'Save never clamps');
          target[property] = '100000.00';
          state.priceInput(field.key);
          assert.equal(state.priceErrors[field.key], '', 'Correcting to the exact limit clears the inline error');
        }
      }
      const before = app.calls.length;
      await state.save();
      assert.ok(app.calls.slice(before).some(call => call[0] === (editing ? 'PUT' : 'POST')), 'The exact limit saves for every method');
    }
  }
  state.openModal();
  for (const invalid of ['-0.01', '1.001', '100.000', '', 'abc']) {
    state.form.price = invalid;
    const before = app.calls.length;
    await state.save();
    assert.ok(state.priceErrors.price);
    assert.equal(app.calls.length, before);
  }
  state.form.price = '0';
  assert.equal(state.validatePrice('price'), true, 'Zero remains valid');
  state.form.pricing_method = 'range';
  state.form.min_price = '500'; state.form.max_price = '499';
  state.validatePrice('max_price');
  assert.match(state.priceErrors.max_price, /Maximum Price/);
  state.form.min_price = '499'; state.priceInput('min_price');
  assert.equal(state.priceErrors.max_price, '', 'Correcting the minimum clears the dependent range error');
  state.openModal();
  assert.equal(Object.keys(state.priceErrors).length, 0, 'Reopening clears prior errors');
  Object.assign(state.form, {name: 'CBC', category_id: 2, pricing_method: 'tiered', price: '1000000'});
  state.form.tiers.forEach(tier => {tier.enabled = false; tier.price = '1000000';});
  state.form.tiers[1].enabled = true;
  state.form.tiers[1].price = '500.00';
  const before = app.calls.length;
  await state.save();
  assert.ok(app.calls.slice(before).some(call => call[0] === 'POST'), 'Unused prices and disabled tiers do not block saving');
}

async function confinementConfiguration() {
  const app = setup();
  await app.shell.showSection();
  const state = app.context.adminClinicServices();
  state.categories = [{id: 6, name: 'Confinement'}];
  state.form.category_id = '6'; state.form.charge_behavior = 'base_confinement';
  state.form.pricing_method = 'fixed'; state.form.billing_unit = 'sample';
  state.form.tiers.forEach(tier => {tier.enabled = false; tier.price = '100';});
  state.configureConfinement();
  assert.equal(state.form.pricing_method, 'tiered'); assert.equal(state.form.billing_unit, 'day');
  assert.equal(state.form.tier_basis, 'weight_kg'); assert.equal(state.payload().tiers.length, 4);
  assert.equal(state.validateCutoffs(), false, 'No default clinic cutoffs are invented');
  state.form.tiers.forEach((tier, i) => {tier.max_weight_kg = [8.5, 22, 38, ''][i];});
  assert.equal(state.validateCutoffs(), true);
  assert.equal(state.payload().tiers[3].max_weight_kg, null);
  for (const cutoff of ['', '0', '-1', 'invalid', '8.5', '7']) {
    state.form.tiers[1].max_weight_kg = cutoff;
    assert.equal(state.validateCutoffs(), false);
    assert.ok(state.weightErrors.medium);
  }
  state.form.tiers[1].max_weight_kg = 22;
  assert.equal(state.validateCutoffs(), true);
  assert.equal(state.payload().charge_behavior, 'base_confinement');
  state.form.category_id = 2; state.configureConfinement();
  assert.equal('charge_behavior' in state.payload(), false);
  assert.equal(state.payload().tier_basis, 'pet_size', 'Leaving Base Confinement restores existing size pricing');
  assert.equal('max_weight_kg' in state.payload().tiers[0], false, 'Other services do not receive confinement cutoffs');
}

async function confirmationDismissal() {
  const stores = {};
  const context = {document: {addEventListener: (_, callback) => callback()}, Alpine: {store: (name, store) => {stores[name] = store;}}};
  vm.createContext(context);
  vm.runInContext(read('scripts/components/clinic-feedback.js'), context);
  const feedback = stores.clinicFeedback;
  let answer = feedback.confirm({title: 'Service status', dismissOnEscape: false});
  assert.equal(feedback.dialog.dismissOnEscape, false);
  feedback.answer(false);
  assert.equal(await answer, false, 'Cancel still completes confirmation');
  answer = feedback.confirm({title: 'Case action'});
  assert.equal(feedback.dialog.dismissOnEscape, true, 'Other clinic confirmations retain their existing Escape behavior');
  feedback.answer(true);
  assert.equal(await answer, true);
}

(async () => {await navigation(); await workflow(); await readOnlyAccess(); await priceValidation(); await confirmationDismissal(); await confinementConfiguration(); formatting(); console.log('Clinic Services navigation, pricing and workflow regressions passed');})().catch(error => {console.error(error); process.exitCode = 1;});
