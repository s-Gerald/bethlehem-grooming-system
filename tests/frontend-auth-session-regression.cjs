const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const projectRoot = path.resolve(__dirname, "..");
const apiSource = fs.readFileSync(path.join(projectRoot, "scripts/api.js"), "utf8");

class MemoryStorage {
  constructor(seed = {}) {
    this.values = new Map(Object.entries(seed));
  }

  getItem(key) {
    return this.values.has(key) ? this.values.get(key) : null;
  }

  setItem(key, value) {
    this.values.set(key, String(value));
  }

  removeItem(key) {
    this.values.delete(key);
  }
}

function jsonResponse(status, data) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => data,
    headers: { get: () => null },
  };
}

function createBrowser({
  pathname = "/pages/client/sign-in.html",
  local = {},
  session = {},
  elements = {},
  fetchImpl = async () => jsonResponse(200, {}),
} = {}) {
  const localStorage = local instanceof MemoryStorage ? local : new MemoryStorage(local);
  const sessionStorage = session instanceof MemoryStorage ? session : new MemoryStorage(session);
  const listeners = new Map();
  const replacements = [];
  const location = {
    protocol: "http:",
    hostname: "127.0.0.1",
    port: "8000",
    origin: "http://127.0.0.1:8000",
    pathname,
    href: "",
    replace(target) {
      replacements.push(target);
      this.href = target;
    },
  };
  const window = {
    location,
    dispatchEvent(event) {
      for (const handler of listeners.get(event.type) || []) handler(event);
    },
    addEventListener(name, handler) {
      const handlers = listeners.get(name) || [];
      handlers.push(handler);
      listeners.set(name, handlers);
    },
  };
  const context = {
    AbortController,
    FormData: class FormData {},
    URL,
    URLSearchParams,
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
    clearTimeout,
    console,
    document: {
      readyState: "loading",
      querySelector: () => null,
      querySelectorAll: () => [],
      getElementById: (id) => elements[id] || null,
      addEventListener() {},
    },
    fetch: (...args) => fetchImpl(...args),
    localStorage,
    sessionStorage,
    setTimeout,
    window,
  };

  vm.createContext(context);
  vm.runInContext(apiSource, context, { filename: "scripts/api.js" });

  return {
    API: context.API,
    document: context.document,
    localStorage,
    sessionStorage,
    location,
    replacements,
    sidebar() {
      window.API = context.API;
      vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/components/admin-sidebar.js'), 'utf8'), context);
      return context.adminSidebar();
    },
    dispatch(name, event = {}) {
      for (const handler of listeners.get(name) || []) handler(event);
    },
  };
}

// One profile shares localStorage and storage events between documents. Each
// tab owns sessionStorage; navigation preserves that tab's store. Profiles
// have no shared storage/event channel, as with normal and incognito windows.
function createBrowserProfile(fetchImpl) {
  const values = new Map(), tabs = [];
  return {
    page(session = new MemoryStorage(), pathname = '/pages/client/sign-in.html') {
      const local = new MemoryStorage();
      local.values = values;
      const broadcast = (key, oldValue, newValue) => {
        if (oldValue === newValue) return;
        for (const tab of tabs) {
          if (tab.localStorage === local) continue;
          queueMicrotask(() => tab.dispatch('storage', { storageArea: tab.localStorage, key, oldValue, newValue }));
        }
      };
      local.setItem = (key, value) => {
        const oldValue = local.getItem(key);
        values.set(key, String(value));
        broadcast(key, oldValue, String(value));
      };
      local.removeItem = key => {
        const oldValue = local.getItem(key);
        values.delete(key);
        broadcast(key, oldValue, null);
      };
      const tab = createBrowser({ local, session, pathname, fetchImpl });
      tabs.push(tab);
      return tab;
    },
  };
}

async function testLoginCodeHonorsRememberMeStorage() {
  const requests = [];
  const browser = createBrowser({
    local: { customer_token: "obsolete-local-token", user_role: "customer" },
    session: {
      pending_login_poll_token: "login-poll-token",
      pending_login_confirmation_email: "customer@example.com",
    },
    fetchImpl: async (url, options) => {
      requests.push({ url, body: JSON.parse(options.body) });
      if (url.endsWith("/email/login/complete")) {
        return jsonResponse(200, { success: true });
      }

      return jsonResponse(200, {
        approved: true,
        session_established: true,
        token: "new-session-token",
        remember_me: false,
        user: { role: "customer" },
      });
    },
  });

  await browser.API.confirmLoginCode("012345");

  assert.equal(browser.API.getBaseUrl(), "http://127.0.0.1:8000/api");
  assert.equal(browser.localStorage.getItem("customer_token"), null);
  assert.equal(browser.localStorage.getItem("user_role"), null);
  assert.equal(browser.sessionStorage.getItem("customer_token"), "new-session-token");
  assert.equal(browser.sessionStorage.getItem("user_role"), "customer");
  assert.equal(browser.API.getCustomerToken(), "new-session-token");
  assert.deepEqual(requests, [
    {
      url: "http://127.0.0.1:8000/api/email/login/confirm",
      body: { poll_token: "login-poll-token", code: "012345" },
    },
    {
      url: "http://127.0.0.1:8000/api/email/login/complete",
      body: { poll_token: "login-poll-token" },
    },
  ]);
}

async function testProfileNameLivesAndDiesWithTheSession() {
  const profileKey = "bethlehem.customer.profile_name";
  const user = { role: "customer", first_name: "Ridge", last_name: "Marino" };
  const confirm = (rememberMe) => createBrowser({
    session: {
      pending_login_poll_token: "login-poll-token",
      pending_login_confirmation_email: "customer@example.com",
    },
    fetchImpl: async (url) => url.endsWith("/email/login/complete")
      ? jsonResponse(200, { success: true })
      : jsonResponse(200, {
        approved: true,
        session_established: true,
        token: "session-token",
        remember_me: rememberMe,
        user,
      }),
  });

  const sessionOnly = confirm(false);
  await sessionOnly.API.confirmLoginCode("012345");
  assert.ok(sessionOnly.sessionStorage.getItem(profileKey), "Name should be stored with a session-only login.");
  assert.equal(sessionOnly.localStorage.getItem(profileKey), null);
  sessionOnly.API.clearAuthState();
  assert.equal(sessionOnly.sessionStorage.getItem(profileKey), null);

  const remembered = confirm(true);
  await remembered.API.confirmLoginCode("012345");
  assert.ok(remembered.localStorage.getItem(profileKey), "Name should be stored with a remembered login.");
  assert.equal(remembered.sessionStorage.getItem(profileKey), null);
  remembered.API.clearAuthState();
  assert.equal(remembered.localStorage.getItem(profileKey), null);
}

function testProfileNameIsAppliedWithoutWaitingForDomContentLoaded() {
  const nameEl = { textContent: "Loading..." };
  const initialsEl = { textContent: "--" };
  createBrowser({
    pathname: "/pages/client/my-pets.html",
    local: {
      customer_token: "token",
      user_role: "customer",
      "bethlehem.customer.profile_name": JSON.stringify({ first: "Ridge", last: "Marino" }),
    },
    elements: { clientProfileName: nameEl, clientProfileInitials: initialsEl },
  });
  assert.equal(nameEl.textContent, "Ridge Marino");
  assert.equal(initialsEl.textContent, "RM");

  const otherName = { textContent: "Loading..." };
  createBrowser({
    pathname: "/pages/client/my-pets.html",
    local: { "bethlehem.customer.profile_name": JSON.stringify({ first: "Ridge", last: "Marino" }) },
    elements: { clientProfileName: otherName },
  });
  assert.equal(otherName.textContent, "Loading...", "A cached name must not show without a session token.");
}

async function testCustomerPasswordStepDoesNotCreateBrowserSession() {
  let submittedRequest = null;
  const browser = createBrowser({
    fetchImpl: async (url, options) => {
      submittedRequest = { url, body: JSON.parse(options.body) };
      return jsonResponse(202, {
        success: true,
        requires_login_confirmation: true,
        login_poll_token: "pending-poll-token",
        email: "customer@example.com",
      });
    },
  });

  const response = await browser.API.signIn(
    "customer@example.com",
    "password",
    false,
  );

  assert.equal(response.requires_login_confirmation, true);
  assert.equal(submittedRequest.url, "http://127.0.0.1:8000/api/sign-in");
  assert.equal(submittedRequest.body.remember, false);
  assert.equal(browser.API.getCustomerToken(), null);
  assert.equal(browser.API.getUserRole(), null);
  assert.equal(
    browser.sessionStorage.getItem("pending_login_poll_token"),
    "pending-poll-token",
  );
  assert.equal(
    browser.API.getPendingLoginConfirmationEmail(),
    "customer@example.com",
  );
}

async function testTemporaryServerFailureDoesNotRedirect() {
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "valid-looking-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(500, { message: "Temporary failure" }),
  });

  await assert.rejects(() => browser.API.getMe("customer"), /Temporary failure/);
  assert.deepEqual(browser.replacements, []);
  assert.equal(browser.API.getCustomerToken(), "valid-looking-token");
}

async function testReal401ClearsAndUsesNestedSafePath() {
  const browser = createBrowser({
    pathname: "/bethlehem/pages/admin/inventory/pos.html",
    local: { admin_token: "expired-admin-token", user_role: "admin" },
    fetchImpl: async () => jsonResponse(401, { message: "Unauthenticated." }),
  });

  await assert.rejects(() => browser.API.getAdminVaccinationOptions(), /Unauthenticated/);
  assert.equal(browser.API.getUserRole(), null);
  assert.equal(browser.localStorage.getItem("admin_token"), null);
  assert.equal(
    browser.replacements.at(-1),
    "/bethlehem/pages/client/sign-in.html",
  );
}

async function testAdminRequestUsesTheCurrentAdminSession() {
  let request = null;
  const browser = createBrowser({
    local: { admin_token: "inventory-admin-token", user_role: "admin" },
    fetchImpl: async (url, options) => {
      request = { url, options };
      return jsonResponse(200, { data: [] });
    },
  });

  await browser.API.adminRequest("GET", "/inventory/items");

  assert.equal(request.url, "http://127.0.0.1:8000/api/inventory/items");
  assert.equal(request.options.headers.Authorization, "Bearer inventory-admin-token");
}

async function testStale401CannotClearANewerLogin() {
  let resolveRequest;
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "old-token", user_role: "customer" },
    fetchImpl: () => new Promise((resolve) => {
      resolveRequest = resolve;
    }),
  });

  const oldRequest = browser.API.getMe("customer");
  browser.API.setCustomerToken("new-token", true);
  resolveRequest(jsonResponse(401, { message: "Old token expired" }));

  await assert.rejects(() => oldRequest, /Old token expired/);
  assert.equal(browser.API.getCustomerToken(), "new-token");
  assert.deepEqual(browser.replacements, []);
}

async function testOnlyExplicit403AuthCodesInvalidateSession() {
  const generalForbidden = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "customer-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(403, { message: "Forbidden", code: "forbidden" }),
  });

  await assert.rejects(() => generalForbidden.API.getMe("customer"), /Forbidden/);
  assert.equal(generalForbidden.API.getCustomerToken(), "customer-token");
  assert.deepEqual(generalForbidden.replacements, []);

  const disabled = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "disabled-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(403, {
      message: "Account disabled",
      code: "account_disabled",
    }),
  });

  await assert.rejects(() => disabled.API.getMe("customer"), /Account disabled/);
  assert.equal(disabled.API.getCustomerToken(), null);
  assert.equal(disabled.replacements.at(-1), "/pages/client/sign-in.html");
}

async function testLogoutClearsBeforeNetworkAndSynchronizesCustomerTabs() {
  let stateAtRequest = null;
  let browser;
  browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    session: { customer_token: "101|session-secret", user_role: "customer" },
    fetchImpl: async () => {
      stateAtRequest = {
        token: browser.sessionStorage.getItem("customer_token"),
        role: browser.sessionStorage.getItem("user_role"),
      };
      return jsonResponse(200, { success: true });
    },
  });

  await browser.API.logout("customer");
  assert.deepEqual(stateAtRequest, { token: null, role: null });
  const logoutEvent = JSON.parse(browser.localStorage.getItem("bethlehem.auth.logout"));
  assert.equal(logoutEvent.sessionId, "customer:101");
  assert.equal(JSON.stringify(logoutEvent).includes("session-secret"), false);

  const otherTab = createBrowser({
    pathname: "/pages/client/settings.html",
    session: { customer_token: "101|session-secret", user_role: "customer" },
  });
  otherTab.dispatch("storage", {
    storageArea: otherTab.localStorage,
    key: "bethlehem.auth.logout",
    newValue: JSON.stringify(logoutEvent),
  });
  assert.equal(otherTab.API.getCustomerToken(), null);
  assert.equal(otherTab.replacements.at(-1), "/pages/client/sign-in.html");

  const adminTab = createBrowser({
    pathname: "/pages/admin/dashboard.html",
    local: { admin_token: "102|staff-secret", user_role: "staff" },
  });
  adminTab.dispatch("storage", {
    storageArea: adminTab.localStorage,
    key: "bethlehem.auth.logout",
    newValue: JSON.stringify({ role: "staff", sessionId: "staff:102", reason: "logout" }),
  });
  assert.equal(adminTab.API.getAdminToken(), null);
  assert.equal(adminTab.replacements.at(-1), "/pages/client/sign-in.html");
}

function testTokenReplacementRemovalEventCannotClearFreshLogin() {
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "fresh-token", user_role: "customer" },
  });

  browser.dispatch("storage", {
    storageArea: browser.localStorage,
    key: "customer_token",
    oldValue: "old-token",
    newValue: null,
  });

  assert.equal(browser.API.getCustomerToken(), "fresh-token");
  assert.deepEqual(browser.replacements, []);
}

function testLogoutFromAnotherSessionCannotClearTheCurrentLogin() {
  for (const role of ["admin", "customer"]) {
    const browser = createBrowser({
      pathname: `/pages/${role === "customer" ? "client" : "admin"}/dashboard.html`,
      local: { [role === "customer" ? "customer_token" : "admin_token"]: "202|current-secret", user_role: role },
    });
    for (const event of [
      { role: role === "admin" ? "customer" : "admin", sessionId: "customer:101", reason: "logout" },
      { role, sessionId: `${role}:101`, reason: "expired" },
      { role, sessionId: `${role}:101`, reason: "inactivity" },
      { role, reason: "logout" }, // An old cached page cannot identify this session.
    ]) {
      browser.dispatch("storage", {
        storageArea: browser.localStorage, key: "bethlehem.auth.logout",
        newValue: JSON.stringify(event),
      });
      assert.equal(browser.API.getUserRole(), role, "Unrelated or delayed logout must not erase a newer session");
      assert.equal(browser.API.hasAuthenticatedSession(), true);
      assert.deepEqual(browser.replacements, []);
    }
  }
}

async function testIndependentBrowserContextsInBothLoginOrders() {
  for (const roles of [["admin", "customer"], ["customer", "admin"],
    ['clinic_receptionist', 'grooming_receptionist'], ['grooming_receptionist', 'clinic_receptionist']]) {
    // Normal/Private profiles (and different browsers) have independent stores,
    // but make requests to the same server/token catalogue.
    const tokens = new Map(), challenges = new Map(), calls = [];
    let sequence = 300;
    const fetchImpl = async (url, options) => {
      calls.push(url);
      if (url.endsWith("/sign-in")) {
        const identifier = JSON.parse(options.body).identifier;
        const receptionist = identifier.endsWith('_receptionist');
        const user = { user_id: ++sequence, role: receptionist ? 'staff' : identifier,
          staff_subrole: receptionist ? identifier : null,
          staff_type: receptionist ? (identifier === 'clinic_receptionist' ? 'clinic' : 'grooming') : null,
          clinic_permissions: {access: true, services_view: true, clinical: false},
          staff_permissions: {grooming: true, grooming_services_view: true, services_manage: false} };
        challenges.set(identifier, user);
        return jsonResponse(202, { requires_login_confirmation: true, login_poll_token: identifier });
      }
      if (url.endsWith('/email/login/confirm')) {
        const user = challenges.get(JSON.parse(options.body).poll_token);
        const token = `${++sequence}|${user.user_id}-secret`;
        tokens.set(token, user);
        return jsonResponse(200, { token, user });
      }
      const token = options.headers.Authorization?.replace(/^Bearer /, "");
      const user = tokens.get(token);
      if (!user) return jsonResponse(401, { message: "Unauthenticated." });
      if (url.endsWith("/logout")) {
        tokens.delete(token);
        return jsonResponse(200, { success: true });
      }
      if (url.endsWith("/grooming/services")) {
        assert.equal(options.cache, "no-store");
        return jsonResponse(200, { data: [] });
      }
      return jsonResponse(200, { user, sidebar_revision: `revision-${user.user_id}` });
    };
    const normalProfile = createBrowserProfile(fetchImpl), privateProfile = createBrowserProfile(fetchImpl);
    const normal = normalProfile.page();
    const privateWindow = privateProfile.page();
    await normal.API.signIn(roles[0], "password");
    await normal.API.confirmLoginCode('012345');
    const normalToken = normal.API.getAdminToken() || normal.API.getCustomerToken();
    await privateWindow.API.signIn(roles[1], "password", false);
    await privateWindow.API.confirmLoginCode('012345');
    const expectedNormal = tokens.get(normalToken);
    assert.equal(normal.API.getUserRole(), expectedNormal.role);
    for (const [browser, role] of [[normal, roles[0]], [privateWindow, roles[1]]]) {
      const identity = await browser.API.getMe(role.endsWith('_receptionist') ? 'staff' : role);
      assert.equal(identity.user.staff_subrole || identity.user.role, role);
      const profile = browser === normal ? normalProfile : privateProfile;
      const navigation = profile.page(browser.sessionStorage, identity.user.role === 'customer'
        ? '/pages/client/dashboard.html' : '/pages/admin/clinic.html');
      assert.deepEqual(navigation.replacements, []);
      if (role.endsWith('_receptionist')) {
        const reads = calls.filter(url => url.endsWith('/me')).length;
        assert.equal((await navigation.API.getStaffIdentity()).user.staff_subrole, role);
        assert.equal(calls.filter(url => url.endsWith('/me')).length, reads, 'Navigation restores owned identity without another /me');
        assert.equal(navigation.sidebar().staffRoleLabel, role === 'clinic_receptionist' ? 'Clinic Receptionist' : 'Grooming Receptionist');
        const otherTab = profile.page(new MemoryStorage(), '/pages/admin/services.html');
        assert.equal((await otherTab.API.getStaffIdentity()).user.staff_subrole, role);
      }
      await browser.API.getGroomingCatalogue();
      assert.deepEqual(browser.replacements, []);
    }
    await privateWindow.API.logout(roles[1]);
    assert.equal((await normal.API.getMe(expectedNormal.role, { force: true })).user.user_id, expectedNormal.user_id);
    assert.equal(tokens.has(normalToken), true);
    assert.equal(tokens.size, 1);
    assert.deepEqual(normal.replacements, []);
  }
}

async function testDelayedStaffLoginCannotReplaceNewerSession() {
  let release;
  const browser = createBrowser({
    session: {pending_login_poll_token: 'older-clinic-challenge'},
    fetchImpl: async url => url.endsWith('/email/login/confirm')
      ? new Promise(resolve => { release = () => resolve(jsonResponse(200, {
        token: '501|old-clinic-secret', user: {role: 'staff', staff_subrole: 'clinic_receptionist'},
      })); })
      : jsonResponse(200, {success: true}),
  });
  const pending = browser.API.confirmLoginCode('012345');
  browser.API.setAdminToken('502|new-grooming-secret');
  browser.API.setUserRole('staff');
  release();
  await assert.rejects(pending, /changed|superseded|ended/i);
  assert.equal(browser.API.getAdminToken(), '502|new-grooming-secret');
}

async function testDelayedRevocationCannotClearNewerStaffSession() {
  let release;
  const browser = createBrowser({
    local: {admin_token: '601|clinic-secret', user_role: 'staff'},
    fetchImpl: async () => new Promise(resolve => { release = () => resolve(jsonResponse(200, {success: true})); }),
  });
  const pending = browser.API.logout('staff', {requireRevocation: true});
  browser.API.setAdminToken('602|grooming-secret');
  browser.API.setUserRole('staff');
  release();
  await pending;
  assert.equal(browser.API.getAdminToken(), '602|grooming-secret', 'Old-token revocation must preserve the replacement login');
}

async function testDelayedPasswordResponseCannotClearNewerStaffSession() {
  let release;
  const browser = createBrowser({fetchImpl: async url => {
    if (url.endsWith('/sign-in')) return new Promise(resolve => {
      release = () => resolve(jsonResponse(202, {requires_login_confirmation: true, login_poll_token: 'older-challenge'}));
    });
    return jsonResponse(200, {});
  }});
  const pending = browser.API.signIn('clinic@example.test', 'password');
  browser.API.setAdminToken('603|grooming-secret');
  browser.API.setUserRole('staff');
  release();
  await assert.rejects(pending, /changed|superseded|ended/i);
  assert.equal(browser.API.getAdminToken(), '603|grooming-secret');
  assert.equal(browser.API.hasPendingLoginConfirmation(), false);
}

async function testRecoveryLinksNeverSendOrReplaceTheExistingBrowserSession() {
  const requests = [];
  const browser = createBrowser({ pathname: '/pages/client/confirm-email-recovery.html',
    local: { admin_token: '920|admin-secret', user_role: 'admin' },
    fetchImpl: async (url, options) => { requests.push({ url, options }); return jsonResponse(200, {status: 'completed'}); },
  });
  await browser.API.verifyStaffEmailRecovery('a'.repeat(64));
  await browser.API.completeStaffEmailRecovery('a'.repeat(64), 'CurrentStaff!234');
  for (const { url, options } of requests) {
    assert.equal(options.headers.Authorization, undefined, 'Recovery proof does not forward the unrelated admin bearer');
    assert.equal(url.includes('a'.repeat(64)), false, 'Recovery token stays out of URLs');
  }
  assert.equal(browser.API.getAdminToken(), '920|admin-secret');
  assert.equal(browser.replacements.length, 0);
  assert.equal(browser.localStorage.getItem('bethlehem.auth.logout'), null);
}

async function testVerifiedEmailChangeClearsOnlyItsSessionWithoutEarlyRedirect() {
  let release;
  const browser = createBrowser({ pathname: '/pages/admin/settings.html',
    local: { admin_token: '910|staff-secret', user_role: 'staff' },
    fetchImpl: async (url, options) => {
      if (!url.endsWith('/confirm')) return jsonResponse(401, {message: 'Session ended.'});
      assert.equal(JSON.parse(options.body).stage, 'new');
      return new Promise(resolve => { release = () => resolve(jsonResponse(200, { completed: true })); });
    },
  });
  const completion = browser.API.confirmStaffEmailChange('request', 'new', '123456');
  const backgroundAccount = browser.API.getSettingsSecurityAccount().catch(error => error);
  await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(browser.replacements.length, 0, 'A background 401 must not preempt the final success response');
  release();
  assert.equal((await completion).completed, true);
  assert.equal((await backgroundAccount).status, 401);
  assert.equal(browser.API.getAdminToken(), null);
  assert.equal(browser.replacements.length, 0, 'The Email Updated state must render before navigation');
  const logout = JSON.parse(browser.localStorage.getItem('bethlehem.auth.logout'));
  assert.equal(logout.reason, 'email_changed');
  assert.equal(logout.sessionId, 'staff:910');
  browser.API.redirectToSignIn({replace: true});
  assert.equal(browser.replacements.length, 1);

  const switched = createBrowser({ local: { admin_token: '911|old-staff', user_role: 'staff' },
    fetchImpl: async () => new Promise(resolve => { release = () => resolve(jsonResponse(200, {completed: true})); }),
  });
  const oldCompletion = switched.API.confirmStaffEmailChange('request', 'new', '123456');
  switched.API.setAdminToken('912|new-staff'); switched.API.setUserRole('staff');
  release();
  await assert.rejects(oldCompletion, /session.*changed/i);
  assert.equal(switched.API.getAdminToken(), '912|new-staff');

  const rejected = createBrowser({ pathname: '/pages/admin/settings.html',
    local: { admin_token: '913|expired-staff', user_role: 'staff' },
    fetchImpl: async url => url.endsWith('/confirm')
      ? new Promise(resolve => { release = () => resolve(jsonResponse(422, { message: 'The code is incorrect.' })); })
      : jsonResponse(401, { message: 'Session expired.' }),
  });
  const failedConfirmation = rejected.API.confirmStaffEmailChange('request', 'new', '123456').catch(error => error);
  const expiredRead = rejected.API.getSettingsSecurityAccount().catch(error => error);
  await new Promise(resolve => setTimeout(resolve, 0));
  assert.equal(rejected.replacements.length, 0);
  release();
  assert.equal((await failedConfirmation).status, 422);
  assert.equal((await expiredRead).status, 401);
  assert.equal(rejected.replacements.length, 1, 'A real auth failure redirects after a failed final operation');
  assert.equal(rejected.API.getAdminToken(), null);
}

async function testReceptionistAccountSwitchSynchronizesSharedTabs() {
  for (const roles of [['clinic_receptionist', 'grooming_receptionist'], ['grooming_receptionist', 'clinic_receptionist']]) {
    let sequence = 800, release;
    const users = new Map();
    let pauseIdentity = false;
    const profile = createBrowserProfile(async (url, options) => {
      if (url.endsWith('/sign-in')) {
        const staff_subrole = JSON.parse(options.body).identifier;
        const user = {user_id: ++sequence, role: 'staff', staff_subrole,
          staff_type: staff_subrole === 'clinic_receptionist' ? 'clinic' : 'grooming',
          clinic_permissions: {access: true, services_view: true}, staff_permissions: {grooming: true}};
        const token = `${sequence}|${staff_subrole}-secret`;
        users.set(token, user);
        return jsonResponse(200, {token, user});
      }
      const user = users.get(options.headers.Authorization?.replace(/^Bearer /, ''));
      const result = jsonResponse(200, {user, sidebar_revision: `revision-${user?.user_id}`});
      if (url.endsWith('/me') && pauseIdentity) return new Promise(resolve => { release = () => resolve(result); });
      return result;
    });
    const first = profile.page();
    await first.API.signIn(roles[0], 'password');
    const sibling = profile.page(new MemoryStorage(), '/pages/admin/clinic.html');
    const cached = await sibling.API.getStaffIdentity();
    assert.equal(cached.user.staff_subrole, roles[0]);
    // An uncached sibling starts an old-token identity read before the switch.
    const pendingTab = profile.page();
    pauseIdentity = true;
    const pending = pendingTab.API.getStaffIdentity();
    await first.API.signIn(roles[1], 'password');
    assert.equal(sibling.API.getCachedStaffIdentity(), null, 'Shared token replacement invalidates the former identity');
    release();
    await assert.rejects(pending, /changed|superseded|ended/i);
    pauseIdentity = false;
    assert.equal((await sibling.API.getStaffIdentity()).user.staff_subrole, roles[1]);
    assert.equal(sibling.API.getAdminToken(), first.API.getAdminToken());
    await first.API.logout('staff');
    await Promise.resolve();
    assert.equal(sibling.API.getAdminToken(), null);
    assert.equal(sibling.API.getCachedStaffIdentity(), null);
    assert.equal(sibling.replacements.at(-1), '/pages/client/sign-in.html');
  }
}

function testSharedPersistentLogoutDoesNotErasePendingConfirmation() {
  const browser = createBrowser({
    pathname: "/pages/client/verify-email.html",
    session: { pending_login_poll_token: "new-login-challenge" },
  });
  browser.dispatch("storage", {
    storageArea: browser.localStorage, key: "bethlehem.auth.logout",
    newValue: JSON.stringify({ role: "customer", sessionId: "customer:101" }),
  });
  assert.equal(browser.sessionStorage.getItem("pending_login_poll_token"), "new-login-challenge");
  assert.deepEqual(browser.replacements, []);

  browser.location.pathname = "/pages/admin/dashboard.html";
  browser.dispatch("storage", {
    storageArea: browser.localStorage, key: "bethlehem.auth.logout",
    newValue: JSON.stringify({ role: "admin", sessionId: "admin:101" }),
  });
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
}

async function testPricingAuthFailuresStayLocalToTheRequestingSession() {
  for (const role of ["admin", "customer"]) {
    const tokenKey = role === "admin" ? "admin_token" : "customer_token";
    for (const status of [401, 419]) {
      const browser = createBrowser({
        local: { [tokenKey]: "401|request-secret", user_role: role },
        fetchImpl: async () => jsonResponse(status, { message: "Request rejected" }),
      });
      const other = createBrowser({
        local: { [tokenKey]: "402|other-secret", user_role: role },
      });
      await assert.rejects(browser.API.getGroomingCatalogue(), /Request rejected/);
      const logoutEvent = browser.localStorage.getItem("bethlehem.auth.logout");
      if (logoutEvent) {
        other.dispatch("storage", {
          storageArea: other.localStorage, key: "bethlehem.auth.logout", newValue: logoutEvent,
        });
        assert.equal(JSON.parse(logoutEvent).sessionId, `${role}:401`);
      }
      assert.equal(browser.API.hasAuthenticatedSession(), status !== 401,
        "A current-token 401 ends that session; a 419 must not erase bearer auth");
      assert.equal(other.API.hasAuthenticatedSession(), true);
      assert.deepEqual(other.replacements, []);
    }
  }
}

function testBackForwardCacheGuard() {
  for (const role of ["admin", "staff", "customer"]) {
    const browser = createBrowser({
      pathname: `/pages/${role === "customer" ? "client" : "admin"}/dashboard.html`,
      local: { [role === "customer" ? "customer_token" : "admin_token"]: "token", user_role: role },
    });
    browser.dispatch("pageshow", { persisted: false });
    browser.dispatch("focus");
    assert.deepEqual(browser.replacements, [], "Ordinary lifecycle events preserve an authenticated page");
    browser.API.clearAuthState();
    browser.dispatch("pageshow", { persisted: true });
    assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html", "BFCache restoration cannot restore signed-out access");
  }
}

function verificationPageHarness({ search, hash, pendingLogin = false, role = 'customer', deliveryStatus = null }) {
  const source = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/verify-email.js"),
    "utf8",
  );
  const events = [];
  const elements = new Map();
  const elementListeners = new Map();
  const intervals = new Map();
  let nextIntervalId = 0;
  let now = 100000;
  let onReady = null;

  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set();
      elements.set(id, {
        id,
        classList: {
          add(name) { classes.add(name); },
          remove(name) { classes.delete(name); },
          contains(name) { return classes.has(name); },
          toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        },
        addEventListener(name, handler) {
          elementListeners.set(`${id}:${name}`, handler);
        },
        setAttribute(name, value) { this[name] = value; },
        setSelectionRange() {},
        querySelectorAll() { return Array.from({ length: 6 }, (_, i) => element(`cell${i}`)); },
        className: "",
        disabled: id === "loginCodeSubmit",
        textContent: "",
        value: "",
        focus() {
          events.push({ type: "focus", id });
        },
      });
    }
    return elements.get(id);
  }

  const pathname = "/pages/client/verify-email.html";
  const location = {
    hash,
    href: `https://clinic.example${pathname}${search}${hash}`,
    pathname,
    search,
    replace(target) {
      events.push({ type: "redirect", target });
    },
  };
  const context = {
    API: {
      verifyEmail(token) {
        events.push({ type: "verify", token });
        return new Promise(() => {});
      },
      async confirmLoginCode(code) {
        events.push({ type: "confirm-login-code", code });
        return { token: "confirmed-token", user: { role } };
      },
      hasPendingLoginConfirmation: () => pendingLogin,
      getEmailDeliveryToken: () => deliveryStatus ? 'private-delivery-receipt' : '',
      emailDeliveryStatus: async () => ({ status: deliveryStatus }),
      getPendingLoginConfirmationEmail: () => "customer@example.com",
      resendVerification: async () => ({}),
      async resendLoginCode() {
        events.push({ type: "resend-login-code" });
      },
    },
    URL,
    URLSearchParams,
    Date: { now: () => now },
    console,
    document: {
      addEventListener(name, handler) {
        if (name === "DOMContentLoaded") onReady = handler;
      },
      getElementById: element,
    },
    history: {
      replaceState(_state, _title, target) {
        events.push({ type: "scrub", target });
      },
    },
    sessionStorage: new MemoryStorage(),
    setTimeout(callback) { callback(); },
    setInterval(callback) {
      const id = ++nextIntervalId;
      intervals.set(id, callback);
      return id;
    },
    clearInterval(id) { intervals.delete(id); },
    window: { location, clearTimeout() {}, setTimeout() {} },
  };

  context.sessionStorage.setItem("pendingLoginResendUntil", String(now + 60000));
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/verification-code.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/email-delivery.js'), 'utf8'), context);
  vm.runInContext(source, context, {
    filename: "scripts/auth/client/verify-email.js",
  });
  assert.equal(typeof onReady, "function");
  onReady();
  return {
    elements,
    events,
    API: context.API,
    storage: context.sessionStorage,
    advanceSeconds(seconds) {
      for (let second = 0; second < seconds; second++) {
        now += 1000;
        for (const callback of [...intervals.values()]) callback();
      }
    },
    async dispatch(id, name, extra = {}) {
      const handler = elementListeners.get(`${id}:${name}`);
      assert.equal(typeof handler, "function", `Missing ${name} handler for ${id}`);
      return handler({ preventDefault() {}, ...extra });
    },
  };
}

function testVerificationCredentialsAreScrubbedBeforeUse() {
  const fragmentEvents = verificationPageHarness({
    search: "?token=legacy-token&campaign=welcome",
    hash: "#token=fragment-token",
  });
  assert.deepEqual(fragmentEvents.events, [
    { type: "scrub", target: "/pages/client/verify-email.html?campaign=welcome" },
    { type: "verify", token: "fragment-token" },
  ]);

  const legacyEvents = verificationPageHarness({
    search: "?token=legacy-token",
    hash: "",
  });
  assert.deepEqual(legacyEvents.events, [
    { type: "scrub", target: "/pages/client/verify-email.html" },
    { type: "verify", token: "legacy-token" },
  ]);
}

async function testLoginCodeIsEnteredOnTheOriginalTab() {
  const page = verificationPageHarness({
    search: "?mode=login",
    hash: "",
    pendingLogin: true,
  });
  const input = page.elements.get("loginCode");
  const submit = page.elements.get("loginCodeSubmit");

  assert.equal(page.elements.get("loginCodeEmail").textContent, "c***@example.com");
  assert.equal(page.elements.get("stateLoginCode").classList.contains("hidden"), false);
  assert.equal(submit.disabled, true);

  input.value = "01a2345";
  await page.dispatch("loginCode", "input");
  assert.equal(input.value, "012345");
  assert.equal(submit.disabled, false);

  await page.dispatch("loginCodeForm", "submit");
  assert.ok(page.events.some((event) => (
    event.type === "confirm-login-code" && event.code === "012345"
  )));
  assert.ok(page.events.some((event) => (
    event.type === "redirect" && event.target === "./dashboard.html"
  )));
}

async function testLoginCodeResendCooldownAndLimit() {
  const page = verificationPageHarness({
    search: "?mode=login",
    hash: "",
    pendingLogin: true,
  });
  const resend = page.elements.get("loginCodeResendBtn");

  assert.equal(resend.disabled, true);
  page.advanceSeconds(59);
  assert.equal(resend.disabled, true);
  page.advanceSeconds(1);
  assert.equal(resend.disabled, false);

  for (const digits of ['123456', '12']) {
    const input = page.elements.get('loginCode');
    input.value = digits;
    await page.dispatch('loginCode', 'input');
    let reject;
    let requests = 0;
    const original = page.API.resendLoginCode;
    page.API.resendLoginCode = () => { requests++; return new Promise((_resolve, fail) => { reject = fail; }); };
    const sending = page.dispatch('loginCodeResendBtn', 'click');
    assert.equal(input.value, '', 'Resend clears login input immediately');
    for (let i = 0; i < 6; i++) assert.equal(page.elements.get(`cell${i}`).textContent, '');
    assert.equal(page.elements.get('loginCodeSubmit').disabled, true);
    await page.dispatch('loginCodeResendBtn', 'click');
    assert.equal(requests, 1);
    reject({ status: 503 });
    await sending;
    assert.equal(input.value, '');
    page.API.resendLoginCode = original;
  }

  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.dispatch("loginCodeResendBtn", "click");
    assert.equal(resend.disabled, true);
    assert.equal(
      page.events.filter((event) => event.type === "resend-login-code").length,
      attempt,
    );
    page.advanceSeconds(60);
    assert.equal(resend.disabled, false);
  }
  assert.equal(page.elements.get("loginCodeMessage").textContent, "");
  page.API.resendLoginCode = async () => { throw { status: 429, retryAfter: 128 }; };
  await page.dispatch("loginCodeResendBtn", "click");
  assert.equal(page.elements.get("loginCodeResendCountdown").textContent, "Resend in 2:08");
  page.advanceSeconds(127);
  assert.equal(resend.disabled, true);
  page.advanceSeconds(1);
  assert.equal(resend.disabled, false, "Server throttling must not permanently lock resends");
}

async function testLoginDeliveryErrorsAndPrivilegedRedirects() {
  const failed = verificationPageHarness({ search: '?mode=login', hash: '', pendingLogin: true, deliveryStatus: 'failed' });
  await new Promise(resolve => setImmediate(resolve));
  assert.match(failed.elements.get('loginCodeMessage').textContent, /Couldn't send the code/);
  assert.equal(failed.events.some(event => event.type === 'redirect'), false);
  for (const status of ['queued', 'sending', 'retrying', 'sent', 'delayed']) {
    const quiet = verificationPageHarness({ search: '?mode=login', hash: '', pendingLogin: true, deliveryStatus: status });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(quiet.elements.get('loginCodeMessage').textContent, '', `${status} must remain quiet`);
    await quiet.dispatch('loginCode', 'paste', { clipboardData: { getData: () => '01 23 45' } });
    assert.equal(quiet.elements.get('loginCode').value, '012345');
    assert.equal(quiet.elements.get('loginCodeSubmit').disabled, false);
    assert.deepEqual(Array.from({ length: 6 }, (_, i) => quiet.elements.get(`cell${i}`).textContent), ['0', '1', '2', '3', '4', '5']);
  }
  for (const role of ['staff', 'admin']) {
    const page = verificationPageHarness({ search: '?mode=login', hash: '', pendingLogin: true, role });
    page.elements.get('loginCode').value = '012345';
    await page.dispatch('loginCodeForm', 'submit');
    assert.ok(page.events.some(event => event.type === 'redirect' && event.target === '../admin/dashboard.html'));
  }
}

async function testPasswordResetRecovery() {
  const key = "pendingPasswordResetEmail";
  const storage = new MemoryStorage();
  const requests = [];
  let resetRequestError = null;
  let maskedResponse = "c***@example.com";
  let resetResendAfter = 75;
  function loadPage(script) {
    let now = 100000;
    const intervals = new Map();
    const lifecycle = {};
    const elements = new Map();
    const getElement = (id) => {
      if (!elements.has(id)) {
        const classes = new Set();
        elements.set(id, {
          value: "", textContent: "", innerHTML: "", disabled: false, type: "password",
          get className() { return [...classes].join(' '); },
          set className(value) { classes.clear(); value.split(/\s+/).filter(Boolean).forEach(c => classes.add(c)); },
          classList: {
            toggle(name, force) { const enabled = force ?? !classes.has(name); if (enabled) classes.add(name); else classes.delete(name); },
            contains(name) { return classes.has(name); },
          },
          handlers: {},
          addEventListener(name, handler) { this.handlers[name] = handler; },
          attributes: {},
          setAttribute(name, value) { this.attributes[name] = value; },
          focus() { if (!this.disabled) { context.document.activeElement = this; this.handlers.focus?.(); } },
          setSelectionRange() {},
        });
      }
      return elements.get(id);
    };
    const location = { search: "", href: "http://localhost/pages/client/sign-in.html", replace(target) { this.href = target; } };
    const context = {
      URLSearchParams,
      Date: { now: () => now },
      sessionStorage: storage,
      window: { location, addEventListener(name, handler) { lifecycle[name] = handler; }, setInterval(callback) { intervals.set(1, callback); return 1; }, clearInterval(id) { intervals.delete(id); } },
      API: {
        async signIn() { throw { message: "Invalid credentials", status: 401 }; },
        async requestPasswordReset(email) {
          requests.push(email);
          if (resetRequestError) throw resetRequestError;
          return { email: maskedResponse, resend_after: resetResendAfter, expires_in: 900 };
        },
        async verifyPasswordResetCode() { return { token: 'g'.repeat(64), expires_in: 900 }; },
        async resetPassword() { return { success: true }; },
      },
      document: {
        getElementById: getElement,
        querySelector: (selector) => script.includes('forgot-password') ? getElement(selector) : null,
        querySelectorAll: () => script.includes('forgot-password') ? Array.from({ length: 6 }, (_, i) => getElement(`cell${i}`)) : [],
        createElement: () => ({
          value: "",
          // Stand-in for the browser's native email constraint validation.
          checkValidity() { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value); },
        }),
        addEventListener(name, handler) { if (name === "DOMContentLoaded") handler(); },
      },
    };
    vm.runInNewContext(fs.readFileSync(path.join(projectRoot, "scripts/auth/verification-code.js"), "utf8"), context);
    vm.runInNewContext(fs.readFileSync(path.join(projectRoot, script), "utf8"), context);
    return { getElement, location, API: context.API, lifecycle, intervals, activeElement: () => context.document.activeElement, advance(ms) { now += ms; for (const tick of [...intervals.values()]) tick(); } };
  }

  const signIn = loadPage("scripts/auth/sign-in.js");
  const identifier = signIn.getElement("identifier");
  const clickForgot = () => signIn.getElement("forgotPasswordLink").handlers.click();
  const openForgot = () => loadPage("scripts/auth/forgot-password.js");
  identifier.value = "  Customer@example.com  ";
  clickForgot();
  assert.equal(storage.getItem(key), "Customer@example.com");
  const forgot = openForgot();
  assert.equal(forgot.getElement("resetEmail").value, "Customer@example.com");
  assert.equal(storage.getItem(key), null, "Prefill must be consumed immediately");
  assert.deepEqual(requests, [], "Prefilling must not send a code");
  assert.equal(openForgot().getElement("resetEmail").value, "", "Reload must not reuse the email");
  await forgot.getElement("forgotPasswordForm").handlers.submit({ preventDefault() {} });
  assert.deepEqual(requests, ["customer@example.com"], "Send Code still submits normally");
  assert.equal(forgot.getElement('verifyCodeForm').classList.contains('hidden'), true, 'Generic success must not automatically open reset OTP entry');
  assert.equal(forgot.getElement('passwordResetConfirmation').classList.contains('hidden'), false);
  assert.equal(forgot.activeElement(), forgot.getElement('enterResetCode'), 'Confirmation must focus its enabled next action');
  assert.equal(forgot.getElement('registrationRecovery').classList.contains('hidden'), false);
  const confirmed = openForgot();
  assert.equal(confirmed.getElement('passwordResetConfirmation').classList.contains('hidden'), false, 'Reload preserves neutral confirmation');
  assert.equal(confirmed.getElement('verifyCodeForm').classList.contains('hidden'), true);
  confirmed.getElement('forgotBack').handlers.click();
  assert.equal(confirmed.getElement('forgotPasswordForm').classList.contains('hidden'), false);
  assert.equal(confirmed.getElement('forgotPasswordSubmit').disabled, true, 'Changing email preserves the resend cooldown');
  assert.deepEqual(requests, ['customer@example.com'], 'Leaving confirmation never requests another code');
  forgot.getElement('enterResetCode').handlers.click();
  assert.deepEqual(requests, ['customer@example.com'], 'Entering a received code needs no extra request');
  assert.equal(forgot.getElement("verifyEmail").textContent, "c***@example.com");
  assert.equal(forgot.getElement('resendCode').textContent, 'Resend in 1:15');
  forgot.advance(74000);
  assert.equal(forgot.getElement('resendCode').disabled, true);
  forgot.advance(1000);
  assert.equal(forgot.getElement('resendCode').disabled, false);
  for (const digits of ['123456', '12']) {
    const input = forgot.getElement('verificationCode');
    input.value = digits;
    input.handlers.input();
    forgot.getElement('resendCode').disabled = false;
    let reject;
    const original = forgot.API.requestPasswordReset;
    forgot.API.requestPasswordReset = () => new Promise((_resolve, fail) => { reject = fail; });
    const sending = forgot.getElement('resendCode').handlers.click();
    assert.equal(input.value, '', 'Recovery input clears before the response');
    for (let i = 0; i < 6; i++) assert.equal(forgot.getElement(`cell${i}`).textContent, '');
    assert.equal(forgot.getElement('verifyCodeSubmit').disabled, true);
    reject({ status: 503 });
    await sending;
    assert.equal(input.value, '');
    forgot.API.requestPasswordReset = original;
  }

  signIn.getElement("password").value = "wrong-password";
  await signIn.getElement("signin").handlers.submit({ preventDefault() {} });
  assert.equal(signIn.getElement("sharedSignInMessage").textContent, "Invalid credentials");
  clickForgot();
  assert.equal(openForgot().getElement("resetEmail").value, "Customer@example.com",
    "The entered email must still carry over after Invalid credentials");
  identifier.value = "updated@example.com";
  clickForgot();
  assert.equal(openForgot().getElement("resetEmail").value, "updated@example.com",
    "Use the current identifier after a failed sign-in");
  for (const value of ["customer", "09171234567", "invalid@", "a@@example.com", ""]) {
    storage.removeItem('passwordResetRecovery');
    storage.setItem(key, "stale@example.com");
    identifier.value = value;
    clickForgot();
    assert.equal(storage.getItem(key), null);
    assert.equal(openForgot().getElement("resetEmail").value, "");
  }
  assert.equal(signIn.location.href, "http://localhost/pages/client/sign-in.html");
  const html = fs.readFileSync(path.join(projectRoot, "pages/client/sign-in.html"), "utf8");
  assert.match(html, /id="forgotPasswordLink" href="\.\/forgot-password\.html"/);
  storage.removeItem('passwordResetRecovery');
  resetRequestError = { status: 429, retryAfter: 120 };
  const throttled = openForgot();
  throttled.getElement("resetEmail").value = "unknown@example.com";
  await throttled.getElement("forgotPasswordForm").handlers.submit({ preventDefault() {} });
  assert.equal(throttled.getElement('verifyCodeForm').classList.contains('hidden'), true, 'Failed requests stay on email');
  assert.equal(throttled.getElement('resetEmail').value, 'unknown@example.com');
  assert.match(throttled.getElement('forgotPasswordMessage').textContent, /wait/i);
  assert.equal(throttled.getElement('forgotPasswordSubmit').disabled, true);
  const beforeRetry = requests.length;
  await throttled.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(requests.length, beforeRetry, 'Do not repeat throttled requests');
  throttled.advance(120000);
  resetRequestError = null;
  maskedResponse = "u***@example.com";
  await throttled.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(throttled.getElement('verifyCodeForm').classList.contains('hidden'), true);
  throttled.getElement('enterResetCode').handlers.click();
  assert.equal(throttled.getElement("verifyEmail").textContent, "u***@example.com");
  assert.equal(requests.at(-1), "unknown@example.com", "Masking must not alter the reset request identity");

  storage.removeItem('passwordResetRecovery');
  resetRequestError = { status: 0, message: 'Unable to reach the server. Check your connection.' };
  const offline = openForgot();
  offline.getElement('resetEmail').value = 'customer@example.com';
  await offline.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(offline.getElement('forgotPasswordForm').classList.contains('hidden'), false);
  assert.equal(offline.getElement('forgotPasswordSubmit').disabled, false);
  assert.match(offline.getElement('forgotPasswordMessage').textContent, /connection/i);
  resetRequestError = { status: 503 };
  await offline.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(offline.getElement('forgotPasswordForm').classList.contains('hidden'), false, 'HTTP failures also stay on email');
  let finishRequest;
  let requestCalls = 0;
  offline.API.requestPasswordReset = () => { requestCalls++; return new Promise(resolve => { finishRequest = resolve; }); };
  const pendingRequest = offline.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  await offline.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(requestCalls, 1, 'Initial requests cannot be double submitted');
  finishRequest({ email: 'c***@example.com', resend_after: 45, expires_in: 900 });
  await pendingRequest;
  resetRequestError = null;

  async function requestRecovery() {
    storage.removeItem('passwordResetRecovery');
    const page = openForgot();
    page.getElement('resetEmail').value = 'customer@example.com';
    await page.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
    assert.equal(page.getElement('verifyCodeForm').classList.contains('hidden'), true);
    page.getElement('enterResetCode').handlers.click();
    return page;
  }
  function enterCode(page, value = '123456') {
    page.getElement('verificationCode').value = value;
    page.getElement('verificationCode').handlers.input();
  }
  const recovering = await requestRecovery();
  assert.equal(recovering.getElement('forgotTitle').textContent, 'Check Your Email');
  assert.equal(recovering.getElement('forgotBack').classList.contains('hidden'), false);
  await recovering.getElement('verificationCode').handlers.paste({ preventDefault() {}, clipboardData: { getData: () => '01 23a45' } });
  assert.equal(recovering.getElement('verificationCode').value, '012345');
  assert.equal(recovering.getElement('verifyCodeSubmit').disabled, false);
  assert.deepEqual(Array.from({ length: 6 }, (_, i) => recovering.getElement(`cell${i}`).textContent), ['0', '1', '2', '3', '4', '5']);
  let finishVerification;
  let verificationCalls = 0;
  recovering.API.verifyPasswordResetCode = () => { verificationCalls++; return new Promise(resolve => { finishVerification = resolve; }); };
  const verifying = recovering.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  await recovering.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  assert.equal(verificationCalls, 1);
  finishVerification({ token: 'g'.repeat(64), expires_in: 60 });
  await verifying;
  assert.equal(recovering.getElement('resetPasswordForm').classList.contains('hidden'), false);
  assert.equal(recovering.getElement('forgotBack').classList.contains('hidden'), true, 'Consumed OTP cannot be revisited');
  recovering.getElement('forgotBack').handlers.click();
  assert.equal(recovering.getElement('resetPasswordForm').classList.contains('hidden'), false);
  const afterVerifyRequests = requests.length;
  await recovering.getElement('resendCode').handlers.click();
  assert.equal(requests.length, afterVerifyRequests, 'Consumed OTP cannot be resent');
  const resumed = openForgot();
  assert.equal(resumed.getElement('resetPasswordForm').classList.contains('hidden'), false, 'Reload preserves the grant');
  assert.equal(resumed.getElement('newPassword').value, '', 'Passwords are never stored');
  resumed.getElement('newPassword').value = 'UpdatedPass!234';
  resumed.getElement('confirmNewPassword').value = 'DifferentPass!234';
  await resumed.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  assert.match(resumed.getElement('confirmPasswordMessage').textContent, /do not match/);
  assert.equal(resumed.getElement('newPassword').value, 'UpdatedPass!234');
  resumed.getElement('confirmNewPassword').value = 'UpdatedPass!234';
  let resetCalls = 0;
  let finishReset;
  resumed.API.resetPassword = (grant, value, confirm) => {
    assert.equal(grant, 'g'.repeat(64)); assert.equal(value, confirm);
    resetCalls++; return new Promise(resolve => { finishReset = resolve; });
  };
  const resetting = resumed.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  await resumed.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(resetCalls, 1);
  assert.match(resumed.getElement('resetPasswordSubmit').textContent, /Resetting/);
  finishReset({ success: true }); await resetting;
  assert.equal(resumed.location.href, './sign-in.html?password_reset=1');
  assert.equal(storage.getItem('passwordResetRecovery'), null);
  resumed.lifecycle.pageshow();
  assert.equal(resumed.getElement('forgotPasswordForm').classList.contains('hidden'), false, 'Back/forward cache cannot restore a consumed grant');
  resumed.advance(75000);
  await resumed.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  resumed.getElement('enterResetCode').handlers.click();
  enterCode(resumed);
  await resumed.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  resumed.getElement('newPassword').value = resumed.getElement('confirmNewPassword').value = 'AnotherPass!234';
  let restartedResetCalls = 0;
  resumed.API.resetPassword = async () => { restartedResetCalls++; return { success: true }; };
  await resumed.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(restartedResetCalls, 1, 'A fresh recovery still works after restoring a completed page');

  const expired = await requestRecovery();
  enterCode(expired);
  await expired.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  expired.API.resetPassword = async () => { throw { status: 422, expired: true }; };
  expired.getElement('newPassword').value = expired.getElement('confirmNewPassword').value = 'UpdatedPass!234';
  await expired.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(expired.getElement('forgotPasswordForm').classList.contains('hidden'), false);
  assert.match(expired.getElement('forgotPasswordMessage').textContent, /session expired/i);
  expired.advance(900000);
  await expired.getElement('forgotPasswordForm').handlers.submit({ preventDefault() {} });
  expired.getElement('enterResetCode').handlers.click();
  assert.equal(expired.getElement('verifyCodeForm').classList.contains('hidden'), false);

  const locked = await requestRecovery();
  locked.API.verifyPasswordResetCode = async () => { throw { status: 422 }; };
  for (let i = 0; i < 5; i++) {
    enterCode(locked);
    await locked.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
    assert.equal(locked.getElement('verificationCode').attributes['aria-invalid'], 'true');
  }
  assert.equal(locked.getElement('resendCode').disabled, true, 'Five failures cannot be bypassed with resend');
  assert.match(locked.getElement('verifyCodeMessage').textContent, /too many/i);
  const stillLocked = openForgot();
  assert.equal(stillLocked.getElement('resendCode').disabled, true, 'Reload must retain the lockout');
  stillLocked.advance(900000);
  assert.equal(stillLocked.getElement('resendCode').disabled, false, 'Expiry permits a new request');
  await stillLocked.getElement('resendCode').handlers.click();
  assert.equal(stillLocked.getElement('verificationCode').disabled, false);
  assert.equal(stillLocked.getElement('verifyCodeMessage').textContent, '');
  enterCode(stillLocked, 'abc12');
  assert.equal(stillLocked.getElement('verificationCode').value, '12');
  assert.equal(stillLocked.getElement('verifyCodeSubmit').disabled, true);
  stillLocked.lifecycle.pagehide?.();
  assert.equal(stillLocked.intervals.size, 0, 'Leaving the page cleans up timers');

  const codeExpired = await requestRecovery();
  codeExpired.advance(900000);
  assert.match(codeExpired.getElement('verifyCodeMessage').textContent, /code expired/i);
  assert.equal(codeExpired.getElement('verificationCode').disabled, true);
  assert.equal(codeExpired.getElement('resendCode').disabled, false);
  await codeExpired.getElement('resendCode').handlers.click();
  assert.equal(codeExpired.getElement('verificationCode').disabled, false);

  const resendLimited = await requestRecovery();
  resendLimited.advance(75000);
  resetRequestError = { status: 429, retryAfter: 510 };
  await resendLimited.getElement('resendCode').handlers.click();
  assert.match(resendLimited.getElement('verifyCodeMessage').textContent, /wait/i);
  assert.equal(resendLimited.getElement('resendCode').textContent, 'Resend in 8:30');
  const limitedRequests = requests.length;
  await resendLimited.getElement('resendCode').handlers.click();
  assert.equal(requests.length, limitedRequests);
  resendLimited.advance(510000);
  assert.equal(resendLimited.getElement('resendCode').disabled, false);
  resetRequestError = null;

  const attemptsSurviveResend = await requestRecovery();
  attemptsSurviveResend.API.verifyPasswordResetCode = async () => { throw { status: 422 }; };
  for (let i = 0; i < 2; i++) {
    enterCode(attemptsSurviveResend);
    await attemptsSurviveResend.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  }
  enterCode(attemptsSurviveResend);
  assert.equal(attemptsSurviveResend.getElement('verifyCodeMessage').textContent, '', 'Editing clears the outdated error');
  assert.equal(attemptsSurviveResend.getElement('verificationCode').attributes['aria-invalid'], 'false');
  assert.equal(attemptsSurviveResend.getElement('cell5').classList.contains('is-active'), true);
  attemptsSurviveResend.advance(75000);
  await attemptsSurviveResend.getElement('resendCode').handlers.click();
  for (let i = 0; i < 3; i++) {
    enterCode(attemptsSurviveResend);
    await attemptsSurviveResend.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  }
  assert.equal(attemptsSurviveResend.getElement('verificationCode').disabled, true, 'Resending does not reset failed attempts');

  const verifyLimited = await requestRecovery();
  enterCode(verifyLimited);
  verifyLimited.API.verifyPasswordResetCode = async () => { throw { status: 429, retryAfter: 60 }; };
  await verifyLimited.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  assert.equal(verifyLimited.getElement('verifyCodeSubmit').disabled, true);
  assert.equal(verifyLimited.getElement('verificationCode').attributes['aria-invalid'], 'false', 'Transport limits are not invalid codes');
  verifyLimited.advance(60000);
  assert.equal(verifyLimited.getElement('verifyCodeSubmit').disabled, false);
  verifyLimited.API.verifyPasswordResetCode = async () => { throw { status: 429, retryAfter: 120 }; };
  await verifyLimited.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  verifyLimited.advance(75000);
  await verifyLimited.getElement('resendCode').handlers.click();
  enterCode(verifyLimited);
  assert.equal(verifyLimited.getElement('verifyCodeSubmit').disabled, true, 'Resend cannot erase the verification endpoint retry delay');

  const passwordErrors = await requestRecovery();
  enterCode(passwordErrors);
  await passwordErrors.getElement('verifyCodeForm').handlers.submit({ preventDefault() {} });
  const newPassword = passwordErrors.getElement('newPassword');
  const confirmPassword = passwordErrors.getElement('confirmNewPassword');
  newPassword.value = confirmPassword.value = 'UpdatedPass!234';
  passwordErrors.getElement('toggleNewPassword').handlers.click();
  assert.equal(newPassword.type, 'text');
  assert.equal(passwordErrors.getElement('toggleNewPassword').attributes['aria-label'], 'Hide new password');
  passwordErrors.getElement('toggleNewPassword').handlers.click();
  assert.equal(newPassword.type, 'password');
  passwordErrors.API.resetPassword = async () => { throw { status: 422, errors: { password: ['Choose a different password.'] } }; };
  await passwordErrors.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(passwordErrors.getElement('newPasswordMessage').textContent, 'Choose a different password.');
  assert.equal(newPassword.value, 'UpdatedPass!234');
  assert.equal(confirmPassword.value, 'UpdatedPass!234');
  newPassword.handlers.input();
  assert.equal(newPassword.attributes['aria-invalid'], 'false');
  passwordErrors.API.resetPassword = async () => { throw { status: 429, retryAfter: 30 }; };
  await passwordErrors.getElement('resetPasswordForm').handlers.submit({ preventDefault() {} });
  assert.equal(passwordErrors.getElement('resetPasswordSubmit').disabled, true);
  passwordErrors.advance(30000);
  assert.equal(passwordErrors.getElement('resetPasswordSubmit').disabled, false);
  passwordErrors.advance(870000);
  assert.equal(passwordErrors.getElement('forgotPasswordForm').classList.contains('hidden'), false, 'Grant expiry restarts without polling');
  assert.equal(passwordErrors.getElement('resetEmail').value, 'customer@example.com');
  assert.equal(newPassword.value, '');
  assert.equal(storage.getItem('passwordResetRecovery').includes('UpdatedPass!234'), false);
}

function testStaticAuthContracts() {
  const signIn = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/sign-in.js"),
    "utf8",
  );
  const verify = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/verify-email.js"),
    "utf8",
  );
  const signup = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/signup.js"),
    "utf8",
  );
  const webRoutes = fs.readFileSync(path.join(projectRoot, "routes/web.php"), "utf8");
  const verifyHtml = fs.readFileSync(
    path.join(projectRoot, "pages/client/verify-email.html"),
    "utf8",
  );

  assert.doesNotMatch(signIn, /Auto-redirect already-authenticated users/);
  assert.doesNotMatch(signIn, /API\.(?:getUserRole|getAdminToken|getCustomerToken)/);
  assert.match(verify, /new URLSearchParams\(window\.location\.hash\.slice\(1\)\)/);
  assert.match(verify, /fragmentParams\.get\("token"\)/);
  assert.match(verify, /searchParams\.delete\("token"\)/);
  assert.match(verify, /cleanUrl\.hash = ""/);
  assert.ok(
    verify.indexOf('history.replaceState(') < verify.indexOf('await API.verifyEmail(verificationToken)'),
    "Authentication credentials must be scrubbed before the API request",
  );
  assert.doesNotMatch(verify, /login_token|approveLogin|pollLoginConfirmation/);
  assert.match(verify, /await API\.confirmLoginCode\(code\)/);
  assert.match(verify, /await API\.verifyEmail\(verificationToken\)/);
  assert.match(verify, /dashboard\.html/);
  assert.match(verifyHtml, /<img\b[^>]*Bethlehem_Logo-256\.png/);
  assert.match(verifyHtml, /id="loginCode"/);
  assert.match(verifyHtml, /inputmode="numeric"/);
  assert.match(verifyHtml, /autocomplete="one-time-code"/);
  assert.match(verifyHtml, /maxlength="6"/);
  assert.match(signIn, /window\.location\.replace\("\.\/verify-email\.html\?mode=login"\)/);
  assert.match(signup, /email_delivery_queued === false/);
  assert.match(signup, /pendingVerificationDeliveryFailed/);
  assert.match(webRoutes, /no-store, private, max-age=0, must-revalidate/);
  assert.match(webRoutes, /public, max-age=3600/);

  const htmlFiles = [
    path.join(projectRoot, "index.html"),
    ...fs.readdirSync(path.join(projectRoot, "pages"), { recursive: true })
      .filter((file) => file.endsWith(".html"))
      .map((file) => path.join(projectRoot, "pages", file)),
  ];
  const authAssets = [
    "scripts/api.js",
    "scripts/auth/sign-in.js",
    "scripts/auth/forgot-password.js",
    "scripts/auth/set-up-password.js",
    "scripts/auth/client/signup.js",
    "scripts/auth/client/verify-email.js",
    "scripts/components/admin-sidebar.js",
    "scripts/components/admin-dashboard.js",
    "scripts/components/admin-clinic.js",
    "scripts/components/client-dashboard.js",
    "scripts/components/client-settings.js",
    "scripts/components/my-pets.js",
    "scripts/components/pet-details.js",
  ];

  for (const htmlFile of htmlFiles) {
    const html = fs.readFileSync(htmlFile, "utf8");
    for (const tag of html.matchAll(/<script[^>]+src="([^"]+)"[^>]*>/g)) {
      const source = tag[1].replace(/\\/g, "/");
      const matchedAsset = authAssets.find((asset) => source.includes(asset));
      if (matchedAsset) {
        assert.match(
          new URL(source.replaceAll('&amp;', '&'), 'https://clinic.example/').searchParams.get('v') || '',
          /(?:19|20)\d{6}/,
          `Missing dated ${matchedAsset} cache key in ${path.relative(projectRoot, htmlFile)}`,
        );
        assert.ok(fs.existsSync(path.resolve(path.dirname(htmlFile), source.split('?')[0])), `Missing asset ${source}`);
      }
    }
  }
}

async function testInitialCustomerBrowserSessionMarker() {
  const user = { user_id: 7, role: "customer", first_name: "Gerald", last_name: "Senining" };
  const browser = createBrowser({ fetchImpl: async () => jsonResponse(200, { token: "session-token", user }) });
  await browser.API.verifyEmail("verification-token");
  assert.equal(browser.document.cookie, "bethlehem_customer_initial_session_7=1; Path=/; SameSite=Lax");
  assert.doesNotMatch(browser.document.cookie, /Expires|Max-Age/i, "The greeting marker must end with the browser session.");
  await browser.API.logout("customer");
  await browser.API.signIn("customer@example.com", "existing-password");
  assert.match(browser.document.cookie, /initial_session_7=1/, "Same-session sign-in must not turn the initial greeting into Welcome back.");
  const returning = createBrowser({ fetchImpl: async () => jsonResponse(200, { token: "later-token", user }) });
  await returning.API.signIn("customer@example.com", "existing-password");
  assert.equal(returning.document.cookie, undefined, "Normal returning sign-in must not create an initial-session marker.");
}

async function testStaffSetupUsernameAndResetApiContracts() {
  const requests = [];
  let duplicate = true;
  const browser = createBrowser({
    pathname: "/pages/client/set-up-password.html",
    fetchImpl: async (url, options) => {
      requests.push({ url, body: JSON.parse(options.body) });
      if (url.endsWith("/staff/password-setup/complete")) {
        if (duplicate) return jsonResponse(422, { errors: { username: ["That username is already in use."] } });
        return jsonResponse(200, { completed_setup: true, token: "staff-token", user: { role: "staff" } });
      }
      return jsonResponse(200, { success: true });
    },
  });
  await assert.rejects(() => browser.API.completeStaffPasswordSetup("setup-token", "Password!2345", "Password!2345", "Chosen.Staff"));
  assert.equal(browser.API.getAdminToken(), null, "Validation errors must not establish a session");
  duplicate = false;
  await browser.API.completeStaffPasswordSetup("setup-token", "Password!2345", "Password!2345", "Chosen.Staff");
  assert.equal(requests.at(-1).body.username, "Chosen.Staff");
  assert.equal(browser.API.getAdminToken(), "staff-token");
  assert.equal(browser.API.getUserRole(), "staff");
  await browser.API.completeStaffPasswordSetup("setup-token", "Password!2345", "Password!2345");
  assert.equal(requests.at(-1).body.username, null);
  await browser.API.resetPassword("reset-token", "Password!2345", "Password!2345");
  assert.deepEqual(requests.at(-1), {
    url: "http://127.0.0.1:8000/api/password/reset",
    body: { token: "reset-token", password: "Password!2345", password_confirmation: "Password!2345" },
  });
}

async function testStaffSetupUsernameForm() {
  const source = fs.readFileSync(path.join(projectRoot, "scripts/auth/set-up-password.js"), "utf8");
  async function loadPage({ verificationError = null, completionError = null } = {}) {
    const elements = new Map();
    const getElement = (id) => {
      if (!elements.has(id)) {
        const classes = new Set(["hidden"]);
        const attrs = new Map();
        elements.set(id, {
          value: "", textContent: "", className: "", disabled: false, dataset: {}, focused: false,
          handlers: {},
          classList: {
            add(name) { classes.add(name); }, remove(name) { classes.delete(name); },
            contains(name) { return classes.has(name); },
          },
          addEventListener(name, handler) { this.handlers[name] = handler; },
          setAttribute(name, value) { attrs.set(name, value); },
          getAttribute(name) { return attrs.get(name); },
          focus() { this.focused = true; },
        });
      }
      return elements.get(id);
    };
    const requests = [];
    const redirects = [];
    let onReady;
    let error = completionError;
    let scrubbed = false;
    const token = "a".repeat(64);
    vm.runInNewContext(source, {
      URL, URLSearchParams,
      window: { location: {
        hash: `#token=${token}`, href: `http://localhost/pages/client/set-up-password.html#token=${token}`,
        replace(url) { redirects.push(url); },
      } },
      history: { replaceState() { scrubbed = true; } },
      document: {
        getElementById: getElement, querySelector: () => null,
        addEventListener(name, handler) { if (name === "DOMContentLoaded") onReady = handler; },
      },
      API: {
        async verifyStaffPasswordSetupToken(value) {
          assert.equal(scrubbed, true, "Scrub the setup token before inspecting it");
          assert.equal(value, token);
          assert.equal(getElement("setupAccountDescription").classList.contains("hidden"), true);
          assert.equal(getElement("setupAccountEmail").textContent, "");
          if (verificationError) throw verificationError;
          return { email: "e***@gmail.com" };
        },
        async completeStaffPasswordSetup(...args) {
          requests.push(args);
          if (error) throw error;
          return { completed_setup: true, user: { role: "staff" } };
        },
      },
    });
    await onReady();
    return {
      getElement, requests, redirects, token,
      setCompletionError(value) { error = value; },
      async submit(username) {
        getElement("setupUsername").value = username;
        getElement("setupNewPassword").value = "CreatedPassword!234";
        getElement("setupConfirmPassword").value = "CreatedPassword!234";
        getElement("setupUsername").handlers.input();
        await getElement("setupPasswordForm").handlers.submit({ preventDefault() {} });
      },
    };
  }

  const page = await loadPage({ completionError: { errors: { username: ["That username is already in use."] } } });
  assert.equal(page.getElement("setupAccountEmail").textContent, "e***@gmail.com");
  assert.equal(page.getElement("setupAccountDescription").classList.contains("hidden"), false);
  assert.equal(page.getElement("setupUsername").focused, true);
  await page.submit("  Chosen.Staff  ");
  assert.equal(page.requests[0][3], "Chosen.Staff");
  assert.equal(page.getElement("setupUsernameMessage").textContent, "That username is already in use.");
  assert.equal(page.getElement("setupUsername").getAttribute("aria-invalid"), "true");
  assert.equal(page.getElement("setupPasswordForm").classList.contains("hidden"), false);
  assert.equal(page.getElement("setupPasswordSubmit").disabled, false);
  assert.deepEqual(page.redirects, []);
  page.setCompletionError(null);
  await page.submit("Other_Staff-2");
  assert.equal(page.getElement("setupUsernameMessage").textContent, "");
  assert.equal(page.getElement("setupUsername").getAttribute("aria-invalid"), "false");
  assert.equal(page.requests[1][0], page.token, "Retry uses the same setup token");
  assert.equal(page.requests[1][3], "Other_Staff-2");
  assert.deepEqual(page.redirects, ["../admin/dashboard.html"]);

  const blank = await loadPage();
  await blank.submit("   ");
  assert.equal(blank.requests[0][3], null);
  const invalid = await loadPage();
  await invalid.submit("1bad username");
  assert.equal(invalid.requests.length, 0);
  assert.match(invalid.getElement("setupUsernameMessage").textContent, /beginning with a letter/);
  assert.equal(invalid.getElement("setupUsername").getAttribute("aria-invalid"), "true");

  for (const expired of [true, false]) {
    const unavailable = await loadPage({ verificationError: { expired } });
    assert.equal(unavailable.getElement("setupAccountDescription").classList.contains("hidden"), true);
    assert.equal(unavailable.getElement("setupAccountEmail").textContent, "");
    assert.equal(unavailable.getElement("setupUsername").disabled, true);
    assert.equal(unavailable.getElement("setupPasswordForm").classList.contains("hidden"), true);
    assert.equal(unavailable.getElement(expired ? "setupExpiredLink" : "setupInvalidLink").classList.contains("hidden"), false);
  }
}

async function testReplacementReasonAndCookieTransport() {
  const calls = [];
  const browser = createBrowser({pathname: '/pages/client/my-pets.html',
    local: {customer_token: '81|cookie', user_role: 'customer'},
    fetchImpl: async (url, options) => {
      calls.push(options);
      return jsonResponse(401, {code: 'session_replaced', message: 'Session ended.'});
    }});
  await assert.rejects(browser.API.getMe(), error => error.status === 401);
  assert.equal(calls[0].credentials, 'include');
  assert.equal(calls[0].headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(browser.sessionStorage.getItem('bethlehem.auth.signout_reason'), 'session_replaced');
  assert.equal(browser.localStorage.getItem('customer_token'), null);
  assert.equal(browser.replacements.length, 1);
  assert.ok(!JSON.stringify([...browser.localStorage.values]).includes('secret'));
}

async function testReplacementNotifiesEveryAffectedTab() {
  const profile = createBrowserProfile(async url => url.endsWith('/sign-in')
    ? jsonResponse(200, {token: '91|cookie', user: {role: 'staff'}})
    : jsonResponse(401, {code: 'session_replaced'}));
  const login = profile.page();
  await login.API.signIn('staff', 'password');
  const tabs = [profile.page(new MemoryStorage(), '/pages/admin/clinic.html'),
    profile.page(new MemoryStorage(), '/pages/admin/dashboard.html')];
  await assert.rejects(tabs[0].API.getMe('staff'));
  for (const tab of tabs) {
    assert.equal(tab.sessionStorage.getItem('bethlehem.auth.signout_reason'), 'session_replaced');
    assert.deepEqual(tab.replacements, ['/pages/client/sign-in.html']);
  }
}

async function testNetworkFailureDoesNotCreateReplacementReason() {
  const browser = createBrowser({pathname: '/pages/admin/clinic.html',
    local: {admin_token: '92|cookie', user_role: 'staff'},
    fetchImpl: async () => { throw new Error('Offline'); }});
  await assert.rejects(browser.API.getMe('staff'), error => error.status === 0);
  assert.equal(browser.sessionStorage.getItem('bethlehem.auth.signout_reason'), null);
  assert.equal(browser.API.getAdminToken(), '92|cookie');
  assert.deepEqual(browser.replacements, []);
}

async function testDelayedReplacementDoesNotEraseANewPendingLogin() {
  const browser = createBrowser({local: {admin_token: '99|cookie', user_role: 'staff'},
    fetchImpl: async () => jsonResponse(202, {requires_login_confirmation: true, login_poll_token: 'new-challenge'})});
  await browser.API.signIn('staff', 'password');
  browser.dispatch('storage', {storageArea: browser.localStorage, key: 'bethlehem.auth.logout',
    newValue: JSON.stringify({sessionId: 'staff:99', role: 'staff', reason: 'session_replaced'})});
  assert.equal(browser.API.hasPendingLoginConfirmation(), true);
  assert.equal(browser.sessionStorage.getItem('bethlehem.auth.signout_reason'), null);
}

async function testLegacyCredentialsLeaveBrowserStorageImmediately() {
  for (const persistent of [true, false]) {
    const raw = '121|clinic_' + 'a'.repeat(48);
    let request;
    const browser = createBrowser({[persistent ? 'local' : 'session']: {customer_token: raw, user_role: 'customer'},
      fetchImpl: async (url, options) => { request=options; return jsonResponse(200, {}); }});
    assert.equal(browser.API.getCustomerToken(), '121|cookie');
    assert.ok(!JSON.stringify([...browser.localStorage.values, ...browser.sessionStorage.values]).includes(raw));
    await browser.API.getMe();
    assert.equal(request.headers.Authorization, `Bearer ${raw}`, 'Upgrade retains the credential only in memory until a cookie is issued');
    assert.equal(request.headers['X-Session-Persistent'], persistent ? '1' : '0');
  }
}

(async () => {
  await testReplacementReasonAndCookieTransport();
  await testReplacementNotifiesEveryAffectedTab();
  await testNetworkFailureDoesNotCreateReplacementReason();
  await testDelayedReplacementDoesNotEraseANewPendingLogin();
  await testLegacyCredentialsLeaveBrowserStorageImmediately();
  await testStaffSetupUsernameAndResetApiContracts();
  await testStaffSetupUsernameForm();
  if (process.argv.includes("--staff-setup")) {
    await testPasswordResetRecovery();
    console.log("Staff setup username and password-reset API regression checks passed");
    return;
  }
  await testInitialCustomerBrowserSessionMarker();
  await testLoginCodeHonorsRememberMeStorage();
  await testProfileNameLivesAndDiesWithTheSession();
  testProfileNameIsAppliedWithoutWaitingForDomContentLoaded();
  await testCustomerPasswordStepDoesNotCreateBrowserSession();
  await testTemporaryServerFailureDoesNotRedirect();
  await testReal401ClearsAndUsesNestedSafePath();
  await testAdminRequestUsesTheCurrentAdminSession();
  await testStale401CannotClearANewerLogin();
  await testOnlyExplicit403AuthCodesInvalidateSession();
  await testLogoutClearsBeforeNetworkAndSynchronizesCustomerTabs();
  testLogoutFromAnotherSessionCannotClearTheCurrentLogin();
  await testIndependentBrowserContextsInBothLoginOrders();
  await testReceptionistAccountSwitchSynchronizesSharedTabs();
  await testDelayedPasswordResponseCannotClearNewerStaffSession();
  await testVerifiedEmailChangeClearsOnlyItsSessionWithoutEarlyRedirect();
  await testRecoveryLinksNeverSendOrReplaceTheExistingBrowserSession();
  await testDelayedRevocationCannotClearNewerStaffSession();
  await testDelayedStaffLoginCannotReplaceNewerSession();
  testSharedPersistentLogoutDoesNotErasePendingConfirmation();
  await testPricingAuthFailuresStayLocalToTheRequestingSession();
  testTokenReplacementRemovalEventCannotClearFreshLogin();
  testBackForwardCacheGuard();
  testVerificationCredentialsAreScrubbedBeforeUse();
  await testLoginCodeIsEnteredOnTheOriginalTab();
  await testLoginCodeResendCooldownAndLimit();
  await testLoginDeliveryErrorsAndPrivilegedRedirects();
  await testPasswordResetRecovery();
  testStaticAuthContracts();
  console.log("frontend auth/session regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
