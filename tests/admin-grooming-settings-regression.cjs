const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const catalogueResponse = JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8').replace(/^\uFEFF/, ''));
const catalogue = catalogueResponse.data;
const source = fs.readFileSync(path.join(__dirname, '../scripts/components/admin-settings.js'), 'utf8');

async function main() {
  const context = {API: {getUserRole: () => 'admin', loadAdminData: (key,load,render) => load().then(data=>{render(data);return data;})}, window: {}, setTimeout};
  vm.runInNewContext(source, context);
  const ui = context.adminSettings();
  ui.$nextTick = (callback) => callback();
  for (const service of ['clinic', 'grooming']) {
    ui.selectAvailabilityService(service);
    assert.equal(Object.hasOwn(ui.activeAvailability, 'pre_registration_cutoff_time'), false);
    let savedHours;
    context.API.adminUpdateAvailability = async (payload) => {
      savedHours = JSON.parse(JSON.stringify(payload));
      return { availability: { clinic: { open_time: '08:00', close_time: '17:00' },
        grooming: { open_time: '09:00', close_time: '18:00' } } };
    };
    await ui.saveAvailability();
    assert.deepEqual(Object.keys(savedHours).sort(), ['close_time', 'open_time', 'service']);
    assert.equal(savedHours.service, service);
    assert.equal(ui.availabilityError, '');
  }
  assert.equal(typeof ui.loadGroomingPrices, 'undefined', 'Grooming prices are managed in Services');
  const calls = [];
  let reject = false;
  let serverRows = catalogue;
  context.API.adminRequest = async (method, url, payload) => {
    calls.push({method, url, payload});
    if (reject) throw {message: 'Unable to save.', errors: {amount: ['Enter a reasonable price amount.']}};
    return method === 'GET' ? {...catalogueResponse, data: serverRows} : {data: {...catalogue[5], name: payload.name, isActive: payload.isActive}};
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-grooming-services.js'), 'utf8'), context);
  const services = context.adminGroomingServices();
  let opened = false;
  services.$refs = {editor: {showModal() {opened = true;}, close() {opened = false;}, querySelector: () => null},
    form: {reportValidity: () => true}, serviceName: {focus() {}}, formError: {focus() {}}};
  services.$nextTick = fn => fn();
  services.$store = {clinicFeedback: {notify() {}}};
  await services.activate();
  await services.activate();
  assert.equal(calls.length, 1, 'Already loaded catalog is reused');
  assert.equal(services.rows.length, 10);
  for (const kind of ['package', 'ala_carte']) {
    const service = catalogue.find(item => item.kind === kind);
    services.openEditor(kind, service);
    assert.equal(opened, true);
    assert.equal(Object.hasOwn(services.form, 'displayOrder'), false);
    assert.equal(services.form.priceRows.filter(row => row.enabled).length, service.priceOptions.length);
    const row = services.form.priceRows.filter(row => row.enabled).at(-1);
    for (const type of ['fixed', 'starting_at', 'range']) {
      row.pricingType = type;
      for (const invalid of ['', '0', '-1', '1e3', '+500', '500.001', '₱500', '1,000', 'NaN', '9999999']) {
        row.amount = invalid;
        const before = calls.length;
        await services.save();
        assert.equal(calls.length, before, 'Invalid price must not submit');
        assert.equal(row.amount, invalid, 'Validation must never clamp input');
        assert.match(Object.values(services.fieldErrors).join(' '), /reasonable price/);
      }
      row.amount = '1200';
      row.maximum = '1300';
      assert.equal(services.validatePricing(), true);
      if (type === 'range') {
        for (const max of ['', '1200', '1199', '500.001', '1e3']) {
          row.maximum = max;
          assert.equal(services.validatePricing(), false);
        }
        row.maximum = '1300';
      }
    }
    services.closeEditor();
  }
  const packageService = catalogue.find(item => item.id === 'regular_dog_grooming');
  services.openEditor('package', packageService);
  services.form.descriptionItems[0].text = 'Updated coat care';
  assert.notEqual(packageService.descriptionItems[0], 'Updated coat care', 'Edits stay local until saved');
  services.form.priceRows[0].amount = '600';
  services.restorePrices();
  assert.equal(services.form.priceRows[0].amount, '550');
  const first = services.form.priceRows[0], second = services.form.priceRows[1];
  first.amount = '550.001';
  services.priceInput();
  assert.equal(services.fieldErrors['sizes.small.amount'], undefined, 'Untouched fields stay neutral');
  services.validatePrice(first, 'amount');
  assert.match(services.fieldErrors['sizes.small.amount'], /reasonable price/);
  first.amount = '550';
  services.priceInput();
  assert.equal(services.fieldErrors['sizes.small.amount'], '');
  second.amount = '549';
  services.validatePrice(second, 'amount');
  assert.match(services.fieldErrors['sizes.medium.amount'], /preceding size/);
  first.enabled = false;
  services.priceInput();
  assert.equal(services.fieldErrors['sizes.medium.amount'], '', 'Disabled sizes do not constrain the next enabled size');
  services.closeEditor();
  services.openEditor('ala_carte', catalogue[5]);
  services.form.name = 'Nail Care';
  services.form.priceRows[0].pricingType = 'fixed';
  services.form.priceRows[0].amount = '180';
  serverRows = [...catalogue].reverse();
  await services.save();
  const saved = calls.findLast(call => call.method === 'PUT');
  assert.equal(saved.payload.name, 'Nail Care');
  assert.equal(Object.hasOwn(saved.payload, 'sizes'), false);
  assert.equal(Object.hasOwn(saved.payload, 'descriptionItems'), false);
  assert.equal(Object.hasOwn(saved.payload, 'displayOrder'), false);
  assert.equal(services.rows.map(row => row.id).join(','), serverRows.map(row => row.id).join(','), 'Save reloads authoritative API ordering');
  assert.equal(opened, false);
  services.openEditor('ala_carte', catalogue[5]);
  reject = true;
  await services.save();
  assert.equal(opened, true, 'Failed saves retain the form');
  assert.match(services.formError, /reasonable price/);
  services.destroy();
  console.log('Services grooming editor, validation, and unrelated Settings availability passed.');
}
main().catch(error => {console.error(error); process.exitCode = 1;});
