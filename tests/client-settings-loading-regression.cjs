const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(
  path.resolve(__dirname, "../scripts/components/client-settings.js"),
  "utf8",
);

function createPage(getMe, phoneApi = {}) {
  const elements = new Map();
  const windowEvents = new Map();
  const pageHtml = fs.readFileSync(
    path.resolve(__dirname, "../pages/client/settings.html"),
    "utf8",
  );
  assert.match(pageHtml, /id="settingsContent"[^>]*\binert\b/);
  assert.match(pageHtml, /id="settingsLoadingScreen"/);
  assert.ok(
    pageHtml.indexOf("</header>") < pageHtml.indexOf('id="settingsLoadingScreen"'),
    "The loading screen must be inside Settings below its header.",
  );
  assert.ok(
    pageHtml.indexOf('id="accountTab"') < pageHtml.indexOf('id="settingsLoadingScreen"')
      && pageHtml.indexOf('id="securityTab"') < pageHtml.indexOf('id="settingsLoadingScreen"'),
    "Settings tabs must remain visible above the loading screen.",
  );
  assert.doesNotMatch(pageHtml, /id="settingsLoadingScreen"[^>]*\bfixed\b/);

  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set(["settingsLoadingRetry", "settingsContent"].includes(id) ? ["hidden"] : []);
      const attributes = new Map(id === "settingsContent"
        ? [["inert", ""], ["aria-hidden", "true"]]
        : []);
      const listeners = new Map();
      elements.set(id, {
        id,
        value: "",
        textContent: id === "accountDisplayName" ? "Loading..." : "",
        classList: {
          add(name) { classes.add(name); },
          remove(name) { classes.delete(name); },
          toggle(name, force) {
            if (force) classes.add(name);
            else classes.delete(name);
          },
          contains(name) { return classes.has(name); },
        },
        setAttribute(name, value) { attributes.set(name, value); },
        removeAttribute(name) { attributes.delete(name); },
        getAttribute(name) { return attributes.get(name) ?? null; },
        hasAttribute(name) { return attributes.has(name); },
        addEventListener(name, callback) { listeners.set(name, [...(listeners.get(name) || []), callback]); },
        dispatch(name, event = { preventDefault() {} }) {
          return Promise.all((listeners.get(name) || []).map(callback => callback(event)));
        },
        dispatchEvent(event) {
          for (const callback of listeners.get(event.type) || []) callback(event);
          return true;
        },
        focus() {},
        showModal() { this.open = true; },
        close() { this.open = false; },
        querySelectorAll() { return []; },
      });
    }
    return elements.get(id);
  }

  const context = {
    TextEncoder,
    API: {
      hasAuthenticatedSession: () => true,
      getMe,
      getCustomerSecurityChange: async () => ({ challenge: null }),
      readCustomerCache: () => null,
      loadCustomerData: (endpoint, load, render) => load().then(render),
      isAuthenticationError: () => false,
      redirectToSignIn() {},
      ...phoneApi,
    },
    document: {
      body: { classList: { toggle() {} } },
      getElementById: element,
      querySelector() { return null; },
      querySelectorAll() { return []; },
      addEventListener(name, callback) {
        if (name === "DOMContentLoaded") callback();
      },
    },
    window: {
      addEventListener(name, callback) { windowEvents.set(name, callback); },
      matchMedia: () => ({ matches: false, addEventListener() {} }),
      requestAnimationFrame(callback) { callback(); },
      requestIdleCallback(callback) { callback(); },
      setTimeout(callback) { callback(); },
      setInterval() { return 1; },
      clearInterval() {},
    },
  };

  vm.createContext(context);
  context.Event = class { constructor(type) { this.type = type; } };
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../scripts/utils/human-name.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../scripts/utils/philippine-mobile.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../scripts/utils/credential-policy.js'), 'utf8'), context);
  for (const file of ['utils/account-email.js', 'auth/verification-code.js', 'components/customer-settings-confirmation.js']) {
    vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../scripts', file), 'utf8'), context);
  }
  vm.runInContext(source, context);
  element.emitVerified = (user) => windowEvents.get('customer-phone:verified')({ detail: { user } });
  element.pageHtml = pageHtml;
  element.emit = (type, detail) => windowEvents.get(type)({ detail });
  return element;
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

async function flush() {
  await new Promise((resolve) => setImmediate(resolve));
}

async function testPasswordChangeSubmissionAndErrors() {
  const pending = deferred();
  let calls = 0, redirected = false;
  const element = createPage(async () => ({ user: { first_name: 'River', last_name: 'Customer' } }), {
    requestCustomerPasswordChange: async payload => {
      calls++;
      assert.equal(payload.current_password, 'old value');
      assert.equal(payload.password, 'violet meadow river');
      assert.equal(payload.password_confirmation, 'violet meadow river');
      return pending.promise;
    },
    async confirmCustomerSecurityChange(kind, id, code) {
      assert.deepEqual([kind, id, code], ['password', 'challenge', '012345']);
      return { status: 'completed' };
    },
    redirectToSignIn(options) { redirected = options.passwordChanged === true; },
  });
  await flush();
  element('currentPassword').value = 'old value';
  element('newPassword').value = element('confirmNewPassword').value = 'violet meadow river';
  const submission = element('passwordForm').dispatch('submit');
  assert.equal(calls, 1);
  assert.equal(element('changePasswordSubmit').disabled, true);
  assert.equal(element('changePasswordLabel').textContent, 'Requesting code…');
  await element('passwordForm').dispatch('submit');
  assert.equal(calls, 1);
  pending.resolve({ status: 'pending', challenge: { id: 'challenge', email: 'river@gmail.com', expires_at: '2099-01-01' } });
  await submission;
  assert.equal(redirected, false);
  assert.equal(element('settingsConfirmationTitle').textContent, 'Verify password change');
  element('settingsVerificationCode').value = '012345';
  await element('settingsVerificationCode').dispatch('input');
  await element('settingsConfirmationForm').dispatch('submit');
  assert.equal(redirected, true);
  assert.equal(element('currentPassword').value, '');
  assert.equal(element('newPassword').value, '');
  assert.equal(element('confirmNewPassword').value, '');

  for (const error of [
    Object.assign(new Error('Invalid'), { status: 422, errors: { current_password: ['Incorrect current password.'] } }),
    Object.assign(new Error('Wait'), { status: 429, retryAfter: 300 }),
    Object.assign(new Error('Lost'), { status: 0 }),
  ]) {
    let attempts = 0;
    const page = createPage(async () => ({ user: { first_name: 'River', last_name: 'Customer' } }), {
      requestCustomerPasswordChange: async () => { attempts++; throw error; },
    });
    await flush();
    page('currentPassword').value = 'old value';
    page('newPassword').value = page('confirmNewPassword').value = 'long meadow river passphrase';
    await page('passwordForm').dispatch('submit');
    assert.equal(attempts, 1);
    assert.equal(page('changePasswordSubmit').disabled, false);
    assert.equal(page('newPassword').value, 'long meadow river passphrase');
    if (error.status === 422) {
      assert.equal(page('currentPasswordError').textContent, 'Incorrect current password.');
      page('currentPassword').value = 'corrected current value';
      await page('currentPassword').dispatch('input');
      assert.equal(page('currentPasswordError').hidden, true);
      assert.equal(page('currentPassword').getAttribute('aria-invalid'), 'false');
    }
    if (error.status === 429) assert.match(page('settingsStatus').textContent, /300/);
    if (error.status === 0) assert.match(page('settingsStatus').textContent, /Unable to request a verification code/i);
  }
}

async function testEmailChangeCanResumeAndCancelWithoutStartingAnotherRequest() {
  let starts = 0, cancels = 0, redirected = 0;
  const challenge = { id: 'email-challenge', kind: 'email', email: 'new.customer@gmail.com', expires_at: '2099-01-01' };
  const page = createPage(async () => ({ user: { first_name: 'River', last_name: 'Customer', email: 'old.customer@gmail.com' } }), {
    async requestCustomerEmailChange(email, password) {
      assert.equal(email, challenge.email);
      assert.equal(password, 'current password');
      starts++;
      return { status: 'pending', challenge };
    },
    async cancelCustomerSecurityChange(kind, id) {
      assert.deepEqual([kind, id], ['email', challenge.id]);
      cancels++;
    },
    redirectToSignIn() { redirected++; },
  });
  await flush();
  await page('settingsEmailAction').dispatch('click');
  assert.equal(page('settingsConfirmationTitle').textContent, 'Change email address');
  page('settingsNewEmail').value = 'old.customer@gmail.com';
  page('settingsConfirmationPassword').value = 'current password';
  await page('settingsConfirmationForm').dispatch('submit');
  assert.equal(page('settingsNewEmailError').textContent, 'Enter a different email address.');
  assert.equal(starts, 0);
  page('settingsNewEmail').value = 'new.customer@GMAIL.COM';
  await page('settingsConfirmationForm').dispatch('submit');
  assert.equal(page('settingsConfirmationTitle').textContent, 'Verify new email');
  assert.equal(page('settingsConfirmationPassword').value, '');
  assert.equal(page('settingsEmail').value, 'old.customer@gmail.com');
  assert.equal(page('settingsEmailPending').hidden, false);
  assert.equal(redirected, 0);
  await page('settingsConfirmationClose').dispatch('click');
  assert.equal(page('settingsConfirmation').open, false);
  assert.equal(cancels, 0);
  assert.equal(page('settingsEmailAction').textContent, 'Continue');
  await page('settingsEmailAction').dispatch('click');
  assert.equal(page('settingsConfirmationTitle').textContent, 'Verify new email');
  assert.equal(starts, 1);
  await page('settingsConfirmationClose').dispatch('click');
  await page('settingsEmailCancel').dispatch('click');
  assert.equal(cancels, 1);
  assert.equal(page('settingsEmailPending').hidden, true);
  assert.equal(page('settingsEmailAction').textContent, 'Change');
}

async function testPasswordPolicyErrorsStayBesideTheirFields() {
  for (const [old, password, confirmation, field, message] of [
    ['', 'violet meadow river passphrase', 'violet meadow river passphrase', 'currentPassword', 'Enter your current password.'],
    ['old', 'short', 'short', 'newPassword', 'Use at least 8 characters.'],
    ['old', ' violet meadow river', ' violet meadow river', 'newPassword', 'Remove spaces at the beginning or end.'],
    ['old', 'violet meadow river ', 'violet meadow river ', 'newPassword', 'Remove spaces at the beginning or end.'],
    ['same meadow river password', 'same meadow river password', 'same meadow river password', 'newPassword', 'Choose a different password.'],
    ['old', 'violet meadow river passphrase', 'mismatch', 'confirmNewPassword', 'Passwords do not match.'],
    ['old', 'violet café', 'violet café', 'newPassword', 'Use English letters, numbers, or standard symbols only.'],
  ]) {
    let calls = 0;
    const page = createPage(async () => ({ user: { first_name: 'River', last_name: 'Customer' } }), {
      requestCustomerPasswordChange: async () => { calls++; },
    });
    await flush();
    page('currentPassword').value = old;
    page('newPassword').value = password;
    page('confirmNewPassword').value = confirmation;
    await page('passwordForm').dispatch('submit');
    assert.equal(calls, 0);
    assert.equal(page(field).getAttribute('aria-invalid'), 'true');
    assert.equal(page(`${field}Error`).hidden, false);
    assert.equal(page(`${field}Error`).textContent, message);
    assert.equal(page('newPassword').value, password);
  }
}

async function testPasswordInputCorrectionRefreshesInlineErrors() {
  let calls = 0;
  const page = createPage(async () => ({ user: { first_name: 'River', last_name: 'Customer' } }), {
    requestCustomerPasswordChange: async () => { calls++; return { success: true }; },
  });
  await flush();
  page('newPassword').value = ' violet meadow river';
  page('confirmNewPassword').value = 'mismatch';
  await page('passwordForm').dispatch('submit');
  assert.equal(calls, 0);
  assert.equal(page('currentPasswordError').hidden, false);
  assert.equal(page('newPasswordError').textContent, 'Remove spaces at the beginning or end.');
  assert.equal(page('confirmNewPasswordError').textContent, 'Passwords do not match.');

  page('currentPassword').value = 'old value';
  await page('currentPassword').dispatch('input');
  assert.equal(page('currentPasswordError').hidden, true);
  assert.equal(page('currentPassword').getAttribute('aria-invalid'), 'false');

  page('newPassword').value = 'violet meadow river';
  await page('newPassword').dispatch('input');
  assert.equal(page('newPasswordError').hidden, true);
  assert.equal(page('newPassword').getAttribute('aria-invalid'), 'false');
  assert.equal(page('confirmNewPasswordError').textContent, 'Passwords do not match.');

  page('confirmNewPassword').value = 'violet meadow river';
  await page('confirmNewPassword').dispatch('input');
  assert.equal(page('confirmNewPasswordError').hidden, true);
  assert.equal(page('confirmNewPassword').getAttribute('aria-invalid'), 'false');
  await page('passwordForm').dispatch('submit');
  assert.equal(calls, 1);
}

async function testSettingsWaitsForProfile() {
  const request = deferred();
  const element = createPage(() => request.promise);

  assert.equal(element("settingsContent").hasAttribute("inert"), true);
  assert.equal(element("settingsContent").classList.contains("hidden"), true);
  assert.equal(element("settingsLoadingScreen").classList.contains("hidden"), false);
  assert.equal(element("accountDisplayName").textContent, "Loading...");

  request.resolve({ user: { first_name: "Gerald", last_name: "Senining", email: "gerald@example.com", phone: null } });
  await flush();

  assert.equal(element("accountDisplayName").textContent, "Gerald Senining");
  assert.equal(element("settingsFirstName").textContent, "Gerald");
  assert.equal(element("settingsPhone").value, "Not provided");
  assert.equal(element("settingsContent").hasAttribute("inert"), false);
  assert.equal(element("settingsContent").classList.contains("hidden"), false);
  assert.equal(element("settingsLoadingScreen").classList.contains("hidden"), true);
}

async function testSettingsFailureKeepsFormHiddenUntilRetry() {
  const first = deferred();
  const second = deferred();
  let attempts = 0;
  const element = createPage(() => (++attempts === 1 ? first.promise : second.promise));

  first.reject(new Error("Temporary server failure"));
  await flush();

  assert.equal(element("settingsContent").hasAttribute("inert"), true);
  assert.equal(element("settingsContent").classList.contains("hidden"), true);
  assert.equal(element("settingsLoadingScreen").classList.contains("hidden"), false);
  assert.equal(element("settingsLoadingRetry").classList.contains("hidden"), false);
  assert.equal(element("settingsLoadingScreen").getAttribute("role"), "alert");

  element("settingsLoadingRetry").dispatch("click");
  second.resolve({ user: { first_name: "Gerald", last_name: "Senining" } });
  await flush();

  assert.equal(attempts, 2);
  assert.equal(element("settingsContent").hasAttribute("inert"), false);
  assert.equal(element("settingsContent").classList.contains("hidden"), false);
  assert.equal(element("settingsLoadingScreen").classList.contains("hidden"), true);
}

async function testPhoneVerificationBadgesUseServerStatus() {
  for (const [phone, status, label] of [
    [null, 'not_added', 'Not added'],
    ['09171234567', 'unverified', 'Not verified'],
    ['09171234567', 'verified', 'Verified'],
    [null, 'verified', 'Not added'],
    ['09171234567', undefined, 'Not verified'],
  ]) {
    let requests = 0;
    const element = createPage(async () => {
      requests++;
      return { user: { phone, phone_verification_status: status } };
    });
    await flush();
    assert.equal(element('settingsPhoneStatus').textContent, label);
    assert.equal(element('settingsPhoneAction').textContent, !phone ? 'Add' : status === 'verified' ? 'Change' : 'Verify');
    assert.equal(element('settingsPhone').value, phone ? '0917 123 4567' : 'Not provided');
    assert.equal(requests, 1, 'Badge must reuse the Settings profile request.');
  }
}

async function testPendingReplacementKeepsAuthoritativePhoneVisible() {
  let requests = 0;
  const element = createPage(async () => {
    requests++;
    return { user: { phone: '09171234567', phone_verification_status: 'verified', phone_change_pending: true } };
  });
  await flush();
  assert.equal(element('settingsPhoneStatus').textContent, 'Pending');
  assert.equal(element('settingsPhoneAction').textContent, 'Continue verification');
  assert.equal(element('settingsPhoneCancel').hidden, false);
  assert.equal(element('settingsPhone').value, '0917 123 4567');
  assert.equal(requests, 1);
}

async function testCancelReplacementKeepsVerifiedPhoneAndRestoresChangeAction() {
  let canceled = 0;
  const element = createPage(async () => ({ user: {
    phone: '09171234567', phone_verification_status: 'verified', phone_change_pending: true,
  } }), {
    getPhoneVerificationState: async () => ({ challenge: { challenge_id: 'pending-change' } }),
    cancelPhoneVerification: async id => { assert.equal(id, 'pending-change'); canceled++; },
  });
  await flush();
  await element('settingsPhoneCancel').dispatch('click');
  assert.equal(canceled, 1);
  assert.equal(element('settingsPhone').value, '0917 123 4567');
  assert.equal(element('settingsPhoneAction').textContent, 'Change');
  assert.equal(element('settingsPhoneStatus').textContent, 'Verified');
  assert.equal(element('settingsPhoneCancel').hidden, true);
}

async function testConfirmedPhoneRefreshUpdatesExistingSettingsFields() {
  let requests = 0;
  const element = createPage(async () => {
    requests++;
    return { user: { phone: null, phone_verification_status: 'not_added' } };
  });
  await flush();
  element.emitVerified({ phone: '09171234567', phone_verification_status: 'verified' });
  assert.equal(element('settingsPhoneStatus').textContent, 'Verified');
  assert.equal(element('settingsPhone').value, '0917 123 4567');
  assert.equal(requests, 1, 'Settings reuses the refreshed server profile without another request.');
}

(async () => {
  const original = { first_name: 'GERALD', last_name: 'de la Cruz', role: 'customer' };
  const element = createPage(async () => ({ user: original }));
  await flush();
  assert.equal(element('settingsFirstName').textContent, 'GERALD');
  assert.equal(element('settingsLastName').textContent, 'de la Cruz');
  assert.doesNotMatch(element.pageHtml, /id="saveCustomerName"|<input[^>]+id="settings(?:First|Last)Name"/);
  assert.match(element.pageHtml, /To correct your name, please contact Bethlehem Animal Clinic\./);
  await element.emit('customer-profile:updated', { user: { first_name: 'María', last_name: 'Peña' } });
  assert.equal(element('settingsFirstName').textContent, 'María');
  assert.equal(element('accountDisplayName').textContent, 'María Peña');
  assert.equal(element('accountInitials').textContent, 'MP');

  await testSettingsWaitsForProfile();
  await testPasswordChangeSubmissionAndErrors();
  await testEmailChangeCanResumeAndCancelWithoutStartingAnotherRequest();
  await testPasswordPolicyErrorsStayBesideTheirFields();
  await testPasswordInputCorrectionRefreshesInlineErrors();
  await testSettingsFailureKeepsFormHiddenUntilRetry();
  await testPhoneVerificationBadgesUseServerStatus();
  await testConfirmedPhoneRefreshUpdatesExistingSettingsFields();
  await testPendingReplacementKeepsAuthoritativePhoneVisible();
  await testCancelReplacementKeepsVerifiedPhoneAndRestoresChangeAction();
  console.log("Client settings loading regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
