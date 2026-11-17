const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {environment, deferred} = require('./admin-data-cache-regression.cjs');
const information = {
  primary_contact_number: '7007-3122', secondary_contact_number: '0917 113 1941',
  clinic_address: 'Saved Taytay address', map_directions_url: 'https://maps.example/clinic',
  facebook_url: 'https://www.facebook.com/2022BethlehemAC',
};
function setup(role = 'admin') {
  const e = environment();
  e.API.setUserRole(role);
  e.c.window.location.search = '';
  e.c.window.location.hash = '';
  e.respond(endpoint => endpoint === '/me' ? {user: {role}, sidebar_revision: 'general'} : {data: {...information}});
  vm.runInContext(fs.readFileSync('scripts/components/admin-settings.js', 'utf8'), e.c);
  const ui = e.c.adminSettings();
  ui.$nextTick = fn => fn();
  return {...e, ui};
}
const plain = value => JSON.parse(JSON.stringify(value));
async function main() {
  const e = setup();
  await e.ui.init();
  assert.deepEqual(plain(e.ui.generalInformation), information, 'General loads actual saved information');
  assert.equal(e.ui.generalLoaded, true);
  assert.equal(e.ui.generalLoading, false);
  assert.deepEqual(e.calls.map(x => x.endpoint), ['/me', '/settings/general']);
  await e.ui.selectSettingsTab('general');
  assert.equal(e.calls.length, 2, 'Revisiting loaded General does not refetch');
  e.ui.generalInformation.clinic_address = 'Unsaved address';
  e.ui.cancelGeneralInformation();
  assert.equal(e.ui.generalInformation.clinic_address, information.clinic_address);

  const pending = deferred();
  let payload;
  e.respond((endpoint, options) => {
    assert.equal(endpoint, '/settings/general');
    assert.equal(options.method, 'PATCH');
    payload = JSON.parse(options.body);
    return pending.promise;
  });
  e.ui.generalInformation.primary_contact_number = '+63 (2) 7007-3123';
  const saving = e.ui.saveGeneralInformation();
  assert.equal(e.ui.generalSaving, true);
  await e.ui.saveGeneralInformation();
  e.ui.cancelGeneralInformation();
  assert.equal(e.ui.generalInformation.primary_contact_number, '+63 (2) 7007-3123', 'Cancel cannot alter an in-flight save');
  assert.equal(e.calls.filter(x => x.method === 'PATCH').length, 1);
  pending.resolve({data: payload}); await saving;
  assert.equal(e.ui.generalSaving, false);
  assert.ok(e.ui.generalSuccess);
  e.ui.generalInformation.primary_contact_number = 'Unsaved'; e.ui.cancelGeneralInformation();
  assert.equal(e.ui.generalInformation.primary_contact_number, '+63 (2) 7007-3123', 'Cancel restores the latest successful save');

  e.respond(() => ({ok: false, status: 422, headers: {get: () => null}, json: async () => ({
    message: 'Invalid information.', errors: {facebook_url: ['Enter a valid URL.']},
  })}));
  e.ui.generalInformation.facebook_url = 'bad'; await e.ui.saveGeneralInformation();
  assert.equal(e.ui.generalErrors.facebook_url, 'Enter a valid URL.');
  assert.equal(e.ui.generalSuccess, '');
  e.ui.cancelGeneralInformation();
  assert.equal(e.ui.generalInformation.facebook_url, information.facebook_url);
  assert.deepEqual(plain(e.ui.generalErrors), {});

  e.respond(() => { throw Error('Could not save right now.'); });
  e.ui.generalInformation.clinic_address = 'Pending change';
  await e.ui.saveGeneralInformation();
  assert.equal(e.ui.generalSaving, false);
  assert.ok(e.ui.generalError);
  assert.equal(e.ui.generalInformation.clinic_address, 'Pending change', 'A failed save preserves the draft');
  e.ui.cancelGeneralInformation();
  assert.equal(e.ui.generalInformation.clinic_address, information.clinic_address);

  const loading = setup(), read = deferred();
  loading.respond(endpoint => endpoint === '/me' ? {user: {role: 'admin'}, sidebar_revision: 'loading'} : read.promise);
  const initializing = loading.ui.init();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(loading.ui.generalLoading, true);
  assert.equal(loading.ui.generalLoaded, false);
  await loading.ui.saveGeneralInformation();
  assert.equal(loading.calls.some(x => x.method === 'PATCH'), false);
  read.resolve({data: information}); await initializing;
  assert.equal(loading.ui.generalLoading, false);
  assert.deepEqual(plain(loading.ui.generalInformation), information);

  const staff = setup('staff'); await staff.ui.init();
  assert.deepEqual(plain(staff.ui.generalInformation), information);
  await staff.ui.saveGeneralInformation();
  assert.equal(staff.calls.some(x => x.method === 'PATCH'), false, 'Staff state cannot submit an update');

  const failed = setup();
  failed.respond(endpoint => {
    if (endpoint === '/me') return {user: {role: 'admin'}, sidebar_revision: 'general'};
    throw Error('Offline');
  });
  await failed.ui.init();
  assert.equal(failed.ui.generalLoaded, false);
  assert.equal(failed.ui.generalLoading, false);
  assert.ok(failed.ui.generalError);
  await failed.ui.saveGeneralInformation();
  assert.equal(failed.calls.some(x => x.method === 'PATCH'), false);
  failed.respond(() => ({data: {...information}}));
  const first = failed.ui.loadGeneralInformation(), second = failed.ui.loadGeneralInformation();
  await Promise.all([first, second]);
  assert.equal(failed.ui.generalLoaded, true);
  assert.equal(failed.ui.generalError, '');
  assert.equal(failed.calls.filter(x => x.endpoint === '/settings/general').length, 2, 'Concurrent retries share one read');

  const security = setup(); security.c.window.location.search = '?tab=security';
  security.ui.loadSecurityActivity = async () => {};
  security.ui.loadSecurityAccounts = async () => {};
  await security.ui.init();
  assert.equal(security.calls.some(x => x.endpoint === '/settings/general'), false, 'Opening Security does not load General');
  console.log('General settings load, role, save, validation, Cancel and request regressions passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
