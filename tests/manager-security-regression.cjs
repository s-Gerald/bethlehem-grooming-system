const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
const manager = { user_id: 8, role: 'admin', admin_type: 'manager', first_name: 'Mara', last_name: 'Santos',
  username: 'MaraSantos', email: 'mara@gmail.com', is_active: true, staff_type: null, staff_subrole: null };
const requests = [], selectors = [];
const context = vm.createContext({ URL, URLSearchParams, console,
  document: { addEventListener() {}, getElementById() { return { focus() {} }; },
    querySelector(selector) { selectors.push(selector); return { focus() {}, scrollIntoView() {} }; } },
  window: { location: { origin: 'https://bethlehem.test', search: '?tab=security' }, clearInterval() {}, clearTimeout() {} },
  API: { getUserRole: () => 'admin', getCachedStaffIdentity: () => ({ user: manager }),
    getStaffIdentity: async () => ({ user: manager }),
    getSecurityActivity: async (focus, cursor) => cursor
      ? {incidents: [{id: 5, status: 'Needs review', first_occurred_at: '2026-11-15T06:00:00Z', last_occurred_at: '2026-11-15T06:00:00Z'}], has_more: false}
      : {incidents: [
        {id: 1, status: 'Needs review', first_occurred_at: '2026-11-17T06:00:00Z', last_occurred_at: '2026-11-17T06:00:00Z'},
        {id: 2, status: 'Recognized', first_occurred_at: '2026-11-16T06:00:00Z'},
        {id: 3, status: 'Reviewed', first_occurred_at: '2026-11-14T06:00:00Z'},
        {id: 4, status: 'Needs review', first_occurred_at: '2026-11-19T06:00:00Z', last_occurred_at: '2026-11-19T06:00:00Z'},
      ], has_more: true, next_cursor: 'older'},
    recognizeSecurityActivity: async id => ({incident: {id, status: 'Recognized', recognized_at: '2026-11-18T06:00:00Z'}}),
    confirmStaffPasswordChange: async () => ({requires_reauthentication: false, security_resolved_at: '2026-11-18T06:00:00Z'}),
    getSettingsSecurityAccount: async () => { requests.push('own'); return { account: manager }; },
    getAdminSecurityAccounts: async () => { requests.push('accounts'); throw Error('Manager must not load accounts'); } },
});
vm.runInContext(read('scripts/components/admin-settings.js'), context);
(async () => {
  const settings = context.adminSettings(); settings.$nextTick = fn => fn();
  await settings.init();
  assert.deepEqual(requests, ['own']);
  assert.equal(settings.isAdmin, true, 'Manager can edit operational settings');
  assert.equal(settings.isOwner, false);
  assert.equal(settings.staffAccount.roleLabel, 'Manager');
  assert.equal(settings.staffAccount.canChangeEmail, true);
  settings.secureAccount();
  assert.equal(selectors.at(-1), '[x-model="staffPassword.current"]');
  await settings.loadMoreSecurityActivity();
  assert.equal(settings.securityIncidents.length, 5);
  assert.equal(settings.securityActivityHasMore, false);
  await settings.recognizeSecurityIncident(settings.securityIncidents.find(incident => incident.id === 1));
  Object.assign(settings.securityVerification, {open: true, changeId: 1, digits: ['1', '2', '3', '4', '5', '6']});
  await settings.confirmSecurityCredentialChange();
  assert.equal(settings.staffPassword.success, 'Password updated successfully.');
  const status = id => settings.securityIncidents.find(incident => incident.id === id).status;
  assert.equal(status(1), 'Recognized');
  assert.equal(status(2), 'Recognized');
  assert.equal(status(3), 'Reviewed');
  assert.equal(status(4), 'Needs review');
  assert.equal(status(5), 'Resolved');
  assert.deepEqual(requests, ['own'], 'Self-service confirmation must not reload Owner Accounts');
  settings.chooseStaffType('manager');
  assert.equal(settings.addStaffModal.step, 'details');
  assert.equal(settings.addStaffModal.roleStage, 'main');
  console.log('Manager self-service Security, history and password-resolution regressions passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
