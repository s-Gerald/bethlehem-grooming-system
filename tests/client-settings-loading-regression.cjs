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
        addEventListener(name, callback) { listeners.set(name, callback); },
        dispatch(name, event = { preventDefault() {} }) { return listeners.get(name)?.(event); },
        dispatchEvent(event) { return listeners.get(event.type)?.(event); },
        focus() {},
        querySelectorAll() { return []; },
      });
    }
    return elements.get(id);
  }

  const context = {
    API: {
      hasAuthenticatedSession: () => true,
      getMe,
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
    },
  };

  vm.createContext(context);
  context.Event = class { constructor(type) { this.type = type; } };
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../scripts/utils/human-name.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.resolve(__dirname, '../scripts/utils/philippine-mobile.js'), 'utf8'), context);
  vm.runInContext(source, context);
  element.emitVerified = (user) => windowEvents.get('customer-phone:verified')({ detail: { user } });
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
  assert.equal(element("settingsFirstName").value, "Gerald");
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
  let saves = 0;
  const original = { first_name: 'GERALD', last_name: 'de la Cruz', role: 'customer' };
  const element = createPage(async () => ({ user: original }), {
    async updateCustomerName(payload) {
      saves++;
      if (payload.current_password !== 'correct') throw { errors: { current_password: ['Incorrect password.'] } };
      return { user: { ...original, first_name: payload.first_name, last_name: payload.last_name } };
    },
  });
  await flush();
  await element('editCustomerName').dispatch('click');
  assert.equal(element('settingsFirstName').value, 'GERALD', 'Opening the editor preserves historical casing.');
  assert.equal(element('settingsFirstName').readOnly, false);
  element('settingsFirstName').value = 'mcdonald';
  await element('accountForm').dispatch('submit');
  assert.equal(saves, 0, 'Missing password is blocked locally.');
  element('nameCurrentPassword').value = 'wrong';
  await element('accountForm').dispatch('submit');
  assert.equal(element('nameCurrentPasswordError').textContent, 'Incorrect password.');
  assert.equal(element('settingsFirstName').value, 'McDonald');
  element('nameCurrentPassword').value = 'correct';
  await element('accountForm').dispatch('submit');
  assert.equal(saves, 2);
  assert.equal(element('accountDisplayName').textContent, 'McDonald de la Cruz');
  assert.equal(element('accountInitials').textContent, 'MD');
  assert.equal(element('nameCurrentPassword').value, '');
  assert.equal(element('settingsFirstName').readOnly, true);
  await element('editCustomerName').dispatch('click');
  element('settingsFirstName').value = 'Discard'; element('nameCurrentPassword').value = 'secret';
  await element('cancelCustomerName').dispatch('click');
  assert.equal(element('settingsFirstName').value, 'McDonald');
  assert.equal(element('nameCurrentPassword').value, '');
  await element('editCustomerName').dispatch('click');
  await element('accountForm').dispatch('submit');
  assert.equal(saves, 2, 'Cancel and unchanged saves do not send requests.');
  await testSettingsWaitsForProfile();
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
