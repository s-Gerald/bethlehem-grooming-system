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
  context.URL = URL;
  context.FormData = FormData;
  let qrReads = 0, qrSaves = 0;
  context.API.getQrPaymentSettings = async () => { qrReads++; return {providers: {gcash: 'GCash', maya: 'Maya'}, settings: {enabled: false, version: 1, has_image: false}}; };
  context.API.saveQrPaymentSettings = async payload => {
    qrSaves++;
    assert.equal(payload.get('enabled'), '0');
    return {settings: {enabled: false, version: 2, has_image: false, merchant_name: 'Bethlehem', provider_name: 'Bank'}};
  };
  await ui.selectSettingsTab('general');
  await ui.selectSettingsTab('capacity');
  assert.equal(qrReads, 0, 'Unrelated tabs never request QR configuration');
  await ui.selectSettingsTab('qr-payments');
  await ui.selectSettingsTab('qr-payments');
  assert.equal(qrReads, 1, 'The QR tab reuses its loaded configuration');
  ui.qrSettings.providerChoice = 'Unlisted name'; ui.selectQrProvider();
  await ui.saveQrSettings();
  assert.equal(qrSaves, 0, 'Unlisted text needs the explicit fallback instead of erasing configuration');
  assert.match(ui.qrSettings.fieldErrors.provider_id, /Select/);
  ui.qrSettings.providerChoice = 'Maya';
  ui.selectQrProvider();
  assert.equal(ui.qrSettings.provider_id, 'maya');
  assert.equal(ui.qrSettings.provider_name, 'Maya');
  ui.qrSettings.providerChoice = 'Other supported provider'; ui.selectQrProvider();
  assert.equal(ui.qrSettings.provider_id, 'other');
  ui.qrSettings.merchant_name = 'Bethlehem';
  ui.qrSettings.provider_name = 'Bank';
  await ui.saveQrSettings();
  assert.equal(qrSaves, 1);
  assert.equal(ui.qrSettings.version, 2);
  assert.equal(ui.qrSettings.error, '');
  const approved = new Blob(['approved crop'], {type: 'image/png'});
  let imageReads = 0;
  context.API.getQrPaymentImage = async version => { imageReads++; assert.equal(version, 3); return {blob: new Blob(['persisted'])}; };
  context.API.saveQrPaymentSettings = async payload => {
    assert.equal(await payload.get('image').text(), 'approved crop', 'Only the approved result is submitted');
    return {message: 'Saved. QR Payments is disabled.', settings: {enabled: false, version: 3, has_image: true, status: 'disabled'}};
  };
  ui.acceptQrImage(approved);
  ui.qrSettings.officialConfirmed = true;
  await ui.saveQrSettings();
  assert.equal(imageReads, 1, 'The persisted version supplies the final preview');
  assert.equal(qrReads, 1, 'Saving does not fetch configuration again');
  assert.equal(ui.qrSettings.file, null);
  assert.equal(ui.qrSettings.status, 'disabled');
  assert.match(ui.qrSettings.success, /disabled/);
  const savedPreview = ui.qrSettings.preview;
  ui.qrCrop.open = true;
  let unexpectedSave = false;
  const save = context.API.saveQrPaymentSettings;
  context.API.saveQrPaymentSettings = async () => {unexpectedSave = true;};
  await ui.saveQrSettings();
  assert.equal(unexpectedSave, false, 'An unapproved edit cannot be saved');
  ui.cancelQrCrop();
  assert.equal(ui.qrSettings.preview, savedPreview, 'Cancelling keeps the approved preview');
  assert.equal(ui.qrCrop.open, false);
  await ui.editQrImage(new Blob(['invalid'], {type: 'image/svg+xml'}));
  assert.match(ui.qrSettings.error, /PNG/);
  assert.equal(ui.qrSettings.preview, savedPreview, 'An invalid replacement preserves the approved image');
  context.API.saveQrPaymentSettings = save;
  ui.isAdmin = false;
  await ui.saveQrSettings();
  assert.equal(unexpectedSave, false);
  ui.isAdmin = true;
  ui.acceptQrImage(approved);
  assert.equal(ui.qrSettings.officialConfirmed, false, 'Replacing a QR requires a new ownership acknowledgment');
  context.API.getQrPaymentImage = async () => { throw new Error('Image unavailable'); };
  await ui.saveQrSettings();
  assert.equal(ui.qrSettings.file, null, 'A preview failure must not resubmit an already-saved image');
  assert.equal(ui.qrSettings.version, 3);
  assert.equal(ui.qrSettings.preview, '', 'Never label a local preview as the saved image after a fetch failure');
  assert.match(ui.qrSettings.error, /Saved/);
  context.API.saveQrPaymentSettings = async () => { throw {errors: {merchant_name: ['Enter an account name.']}}; };
  ui.qrSettings.merchant_name = 'Unsaved name';
  await ui.saveQrSettings();
  assert.equal(ui.qrSettings.merchant_name, 'Unsaved name');
  assert.match(ui.qrSettings.fieldErrors.merchant_name, /account name/);
  context.API.saveQrPaymentSettings = async () => ({settings: {enabled: false, version: 4, has_image: false,
    status: 'not_ready', missing_fields: ['image', 'merchant_name', 'provider_name'], merchant_name: null, provider_name: null}});
  await ui.saveQrSettings();
  assert.equal(ui.qrSettings.merchant_name, '', 'Null account values reconcile to empty editable fields');
  assert.equal(ui.qrSettings.provider_name, '');
  context.API.saveQrPaymentSettings = async payload => {
    assert.equal(payload.get('merchant_name'), '', 'A saved missing account name must never serialize as the text null');
    assert.equal(payload.get('provider_name'), '', 'A saved missing bank must never serialize as the text null');
    return {settings: {enabled: false, version: 5, has_image: false}};
  };
  await ui.saveQrSettings();
  assert.equal(ui.qrSettings.version, 5);
  context.API.saveQrPaymentSettings = async () => { throw new Error('Save rejected'); };
  await ui.saveQrSettings();
  assert.match(ui.qrSettings.error, /rejected/);
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
