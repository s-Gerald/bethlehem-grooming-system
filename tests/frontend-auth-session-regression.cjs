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
  managed = false,
  locks = null,
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
    navigator: {locks},
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
      ...(managed ? { createElement: () => ({}), documentElement: {
        setAttribute(name, value) { this[name] = value; },
        removeAttribute(name) { delete this[name]; },
      } } : {}),
    },
    fetch: (...args) => fetchImpl(...args),
    localStorage,
    sessionStorage,
    setTimeout: (...args) => { const timer = setTimeout(...args); timer.unref?.(); return timer; },
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
function createBrowserProfile(fetchImpl, locks = null) {
  const values = new Map(), tabs = [];
  return {
    page(session = new MemoryStorage(), pathname = '/pages/client/sign-in.html', managed = false) {
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
      const tab = createBrowser({ local, session, pathname, fetchImpl, locks, managed });
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

async function testEveryRoleHonorsTheServerConfirmedCheckbox() {
  for (const role of ['customer', 'admin', 'staff']) {
    for (const remember of [false, true]) {
      const browser = createBrowser({session: {pending_login_poll_token: 'pending'},
        fetchImpl: async url => jsonResponse(200, url.endsWith('/complete') ? {} : {
          token: '201|cookie', remember_me: remember, security_incident_id: 7, user: {user_id: 10, role},
        })});
      await browser.API.confirmLoginCode('012345');
      const key = role === 'customer' ? 'customer_token' : 'admin_token';
      assert.equal(browser[remember ? 'localStorage' : 'sessionStorage'].getItem(key), '201|cookie');
      assert.equal(browser[remember ? 'sessionStorage' : 'localStorage'].getItem(key), null);
      assert.equal(browser.API.getSecurityAlert(), role === 'customer' ? null : 7);
    }
  }
}

async function testTemporaryVerificationThrottlingPreservesTheBrowserChallenge() {
  for (const code of ['login_code_busy', 'login_code_locked']) {
    const browser = createBrowser({session: {pending_login_poll_token: 'pending-challenge'},
      fetchImpl: async () => jsonResponse(429, {code, retry_after: 60})});
    await assert.rejects(browser.API.confirmLoginCode('123456'), error => error.status === 429);
    assert.equal(browser.sessionStorage.getItem('pending_login_poll_token'), 'pending-challenge');
  }
  const browser = createBrowser({session: {pending_login_poll_token:'resumable'},
    fetchImpl: async () => jsonResponse(202, {requires_login_confirmation:true,login_poll_token:'resumable',
      requires_new_code:true,expires_in:40,resend_after:0})});
  const before = Date.now();
  await browser.API.signIn('customer', 'password');
  const deadline = Number(browser.sessionStorage.getItem('pendingLoginExpiresAt'));
  assert.ok(deadline >= before + 40000 && deadline <= Date.now() + 40000, 'Resume stores the server transaction remainder');
  assert.equal(browser.sessionStorage.getItem('pendingLoginCodeExhausted'),'1');
}

const turn = () => new Promise(resolve => setImmediate(resolve));
function activeSession() {
  return {locked: false, server_now: new Date().toISOString(),
    idle_at: new Date(Date.now() + 30 * 60000).toISOString(), expires_at: new Date(Date.now() + 86400000).toISOString()};
}

async function testCustomerRestorationIsDeduplicatedAndQueuesOnlySafeReads() {
  const calls = [];
  let completeRestore;
  const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html',
    fetchImpl: async (url, options) => {
      calls.push({url, options});
      if (url.endsWith('/session/restore')) return new Promise(resolve => { completeRestore = resolve; });
      if (url.endsWith('/session')) return jsonResponse(200, activeSession());
      return jsonResponse(200, {user: {role: 'customer', user_id: 7}, data: []});
    }});
  const one = browser.API.waitForCustomerSession();
  const two = browser.API.waitForCustomerSession();
  const read = browser.API.getMe();
  await turn();
  assert.equal(calls.filter(call => call.url.endsWith('/session/restore')).length, 1);
  assert.equal(calls.some(call => call.url.endsWith('/me')), false);
  await assert.rejects(browser.API.addPet({name: 'Pending'}), /session/i);
  completeRestore(jsonResponse(200, {token: '301|cookie', remember_me: true, user: {user_id: 7, role: 'customer'}}));
  assert.equal(await one, true);
  assert.equal(await two, true);
  await read;
  assert.equal(browser.API.getCustomerToken(), '301|cookie');
  assert.equal(calls.filter(call => call.options.method !== 'GET' && !call.url.endsWith('/session/restore')).length, 0);
  assert.deepEqual(browser.replacements, []);
}

async function testNetworkFailurePreservesReferencesAndDoesNotProveDeviceInvalidity() {
  let online = false, restores = 0;
  const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html',
    local: {customer_token: '302|cookie', user_role: 'customer'}, fetchImpl: async url => {
      if (!online) throw new Error('offline');
      if (url.endsWith('/session/restore')) { restores++; return jsonResponse(401, {}); }
      return jsonResponse(200, activeSession());
    }});
  await turn();
  assert.equal(restores, 0);
  assert.equal(browser.API.getCustomerToken(), '302|cookie');
  assert.equal(browser.document.documentElement['data-session-gate'], 'unavailable');
  assert.deepEqual(browser.replacements, []);
  online = true;
  await browser.API.syncSessionState();
  assert.equal(await browser.API.waitForCustomerSession(), true);
  assert.equal(restores, 0);
}

async function testDelayedRestoreCannotOverwriteANewerAccount() {
  let complete;
  const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html',
    fetchImpl: async url => url.endsWith('/session/restore') ? new Promise(resolve => { complete = resolve; })
      : jsonResponse(200, activeSession())});
  await turn();
  browser.API.setCustomerToken('401|cookie', false);
  complete(jsonResponse(200, {token: '300|cookie', remember_me: true, user: {role: 'customer', user_id: 3}}));
  await turn();
  assert.equal(browser.API.getCustomerToken(), '401|cookie');
}

async function testUnconfirmedLogoutBlocksRestorationAcrossNavigation() {
  const local = new MemoryStorage({customer_token: '501|cookie', user_role: 'customer'});
  const browser = createBrowser({local, fetchImpl: async () => { throw new Error('offline'); }});
  await assert.rejects(browser.API.logout(), /Connection/);
  assert.equal(browser.API.getCustomerToken(), null);
  assert.ok(local.getItem('bethlehem.auth.logout_pending'));
  let restores = 0;
  createBrowser({local, managed: true, pathname: '/pages/client/dashboard.html', fetchImpl: async url => {
    if (url.endsWith('/session/restore')) restores++;
    throw new Error('offline');
  }});
  await turn();
  assert.equal(restores, 0);
}

async function testMultipleTabsShareOneRestorationWhenBrowserLocksAreAvailable() {
  let tail = Promise.resolve(), restores = 0;
  const locks = {request(_name, action) {
    const result = tail.then(action, action);
    tail = result.catch(() => {});
    return result;
  }};
  const profile = createBrowserProfile(async (url, options) => {
    if (url.endsWith('/session/restore')) {
      restores++;
      return jsonResponse(200, {token: '601|cookie', remember_me: true, user: {user_id: 6, role: 'customer'}, ...activeSession()});
    }
    assert.equal(options.headers.Authorization, 'Bearer 601|cookie');
    return jsonResponse(200, activeSession());
  }, locks);
  const first = profile.page(new MemoryStorage(), '/pages/client/dashboard.html', true);
  const second = profile.page(new MemoryStorage(), '/pages/client/my-pets.html', true);
  assert.equal(await first.API.waitForCustomerSession(), true);
  assert.equal(await second.API.waitForCustomerSession(), true);
  assert.equal(restores, 1);
  assert.equal(first.API.getCustomerToken(), second.API.getCustomerToken());
}

async function testDelayedRestoreRejectionAndUncachedReadsCannotAffectNewAccount() {
  let release;
  const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html', fetchImpl: async url =>
    url.endsWith('/session/restore') ? new Promise(resolve => { release = resolve; }) : jsonResponse(200, activeSession())});
  await turn();
  browser.API.setCustomerToken('701|cookie', false);
  release(jsonResponse(401, {code: 'remembered_session_invalid'}));
  await turn();
  assert.equal(browser.API.getCustomerToken(), '701|cookie');
  assert.deepEqual(browser.replacements, []);
  const reading = createBrowser({session: {customer_token: '702|cookie', user_role: 'customer'},
    fetchImpl: () => new Promise(resolve => { release = resolve; })});
  const oldRead = reading.API.getPet(1);
  reading.API.setCustomerToken('703|cookie', false);
  release(jsonResponse(200, {pet: {pet_id: 1, user_id: 7}}));
  await assert.rejects(oldRead, /Session changed/);
}

async function testStorageRestrictionsDoNotCreateAnUntrackableRememberedSession() {
  const storage = new MemoryStorage();
  storage.setItem = () => { throw new Error('Storage restricted'); };
  let restores = 0;
  const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html', local: storage,
    fetchImpl: async () => { restores++; return jsonResponse(401, {}); }});
  await turn();
  assert.equal(restores, 0);
  assert.equal(browser.API.getCustomerToken(), null);
  assert.equal(browser.document.documentElement['data-session-gate'], 'unavailable');
}

async function testExpiredReadsCanRetryAfterRestorationButMutationsCannot() {
  for (const mutation of [false, true]) {
    let expired = false, operations = 0, restores = 0;
    const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html',
      local: {customer_token: '801|cookie', user_role: 'customer'}, fetchImpl: async url => {
        if (url.endsWith('/session')) return jsonResponse(expired ? 401 : 200, expired ? {code: 'session_expired'} : activeSession());
        if (url.endsWith('/session/restore')) {
          restores++;
          expired = false;
          return jsonResponse(200, {token: '802|cookie', remember_me: true, user: {role: 'customer', user_id: 8}, ...activeSession()});
        }
        operations++;
        if (operations === 1) { expired = true; return jsonResponse(401, {code: 'session_expired'}); }
        return jsonResponse(200, {pet: {pet_id: 1}});
      }});
    assert.equal(await browser.API.waitForCustomerSession(), true);
    if (mutation) await assert.rejects(browser.API.addPet({name: 'Pending'}), error => error.status === 401);
    else assert.equal((await browser.API.getPet(1)).pet.pet_id, 1);
    assert.equal(operations, mutation ? 1 : 2);
    assert.equal(restores, 1);
    assert.equal(browser.API.getCustomerToken(), '802|cookie');
    assert.deepEqual(browser.replacements, []);
  }
}

async function testUnconfirmableOldLogoutAllowsOnlyDeliberateFullSignInRecovery() {
  const browser = createBrowser({local: {customer_token: '901|cookie', user_role: 'customer'},
    fetchImpl: async url => {
      if (url.endsWith('/logout')) return jsonResponse(401, {code: 'session_changed'});
      if (url.endsWith('/sign-in')) return jsonResponse(202, {requires_login_confirmation: true, login_poll_token: 'recovery'});
      if (url.endsWith('/email/login/confirm')) return jsonResponse(200, {
        token: '902|cookie', remember_me: false, user: {role: 'customer', user_id: 9}});
      return jsonResponse(200, {success: true});
    }});
  await assert.rejects(browser.API.logout(), error => error.status === 401);
  assert.ok(browser.localStorage.getItem('bethlehem.auth.signed_out'));
  assert.equal((await browser.API.signIn('customer@example.test', 'password', false)).requires_login_confirmation, true);
  assert.equal(browser.API.getCustomerToken(), null);
  assert.ok(browser.localStorage.getItem('bethlehem.auth.signed_out'), 'Password verification alone cannot reenable restoration');
  await browser.API.confirmLoginCode('012345');
  assert.equal(browser.API.getCustomerToken(), '902|cookie');
  assert.equal(browser.localStorage.getItem('bethlehem.auth.signed_out'), null);
  assert.equal(browser.localStorage.getItem('bethlehem.auth.logout_pending'), null);
}

async function testLogoutRequiresAnExplicitServerRevocationAcknowledgement() {
  const browser = createBrowser({local: {customer_token: '903|cookie', user_role: 'customer'},
    fetchImpl: async () => jsonResponse(200, {})});
  await assert.rejects(browser.API.logout(), /confirm sign-out/i);
  assert.equal(browser.API.getCustomerToken(), null);
  assert.ok(browser.localStorage.getItem('bethlehem.auth.logout_pending'));
  assert.ok(browser.localStorage.getItem('bethlehem.auth.signed_out'));
}

async function testMixedCrossTabAccountSwitchHidesOnlyTheObsoleteTabState() {
  const browser = createBrowser({managed: true, pathname: '/pages/client/dashboard.html',
    session: {customer_token: '904|cookie', user_role: 'customer', 'bethlehem.auth.account_id': '9',
      bookingStep2: 'old customer draft', unrelated: 'keep'}, fetchImpl: async () => jsonResponse(200, activeSession())});
  assert.equal(await browser.API.waitForCustomerSession(), true);
  browser.localStorage.setItem('bethlehem.auth.account_id', '10');
  browser.localStorage.setItem('customer_token', '905|cookie');
  browser.localStorage.setItem('user_role', 'customer');
  browser.dispatch('storage', {storageArea: browser.localStorage, key: 'user_role', newValue: 'customer'});
  assert.equal(browser.document.documentElement['data-session-gate'], 'checking');
  assert.equal(browser.replacements.at(-1), '/pages/client/sign-in.html');
  assert.equal(browser.localStorage.getItem('customer_token'), '905|cookie');
  assert.equal(browser.sessionStorage.getItem('customer_token'), null);
  assert.equal(browser.sessionStorage.getItem('bookingStep2'), null);
  assert.equal(browser.sessionStorage.getItem('unrelated'), 'keep');
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

async function testDitoPhoneIdentifiersKeepExistingSignInNormalization() {
  for (const identifier of ['08951234567', '+63 895-123-4567', '638951234567', '8951234567']) {
    let body;
    const browser = createBrowser({ fetchImpl: async (_url, options) => {
      body = JSON.parse(options.body);
      return jsonResponse(202, {requires_login_confirmation: true, login_poll_token: 'pending-dito-login'});
    }});
    await browser.API.signIn(identifier, 'password');
    assert.equal(body.identifier, '08951234567');
    assert.equal(browser.API.getCustomerToken(), null, 'Phone sign-in still requires email confirmation.');
  }
}

async function testTemporaryServerFailureDoesNotRedirect() {
  const browser = createBrowser({
    pathname: "/pages/client/dashboard.html",
    local: { customer_token: "valid-looking-token", user_role: "customer" },
    fetchImpl: async () => jsonResponse(500, { message: "Trying to access array offset on null" }),
  });

  await assert.rejects(() => browser.API.getMe("customer"), /Something went wrong/);
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
        challenges.set(identifier, {user, remember: JSON.parse(options.body).remember});
        return jsonResponse(202, { requires_login_confirmation: true, login_poll_token: identifier });
      }
      if (url.endsWith('/email/login/confirm')) {
        const {user, remember} = challenges.get(JSON.parse(options.body).poll_token);
        const token = `${++sequence}|${user.user_id}-secret`;
        tokens.set(token, user);
        return jsonResponse(200, { token, user, remember_me: remember });
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
    await normal.API.signIn(roles[0], "password", true);
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
        if (browser === normal) assert.equal((await otherTab.API.getStaffIdentity()).user.staff_subrole, role);
        else await assert.rejects(otherTab.API.getStaffIdentity(), /Session ended/);
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
  const pending = browser.API.logout('staff');
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
      if (url.endsWith('/logout')) return jsonResponse(200, {success: true});
      const user = users.get(options.headers.Authorization?.replace(/^Bearer /, ''));
      const result = jsonResponse(200, {user, sidebar_revision: `revision-${user?.user_id}`});
      if (url.endsWith('/me') && pauseIdentity) return new Promise(resolve => { release = () => resolve(result); });
      return result;
    });
    const first = profile.page();
    await first.API.signIn(roles[0], 'password', true);
    const sibling = profile.page(new MemoryStorage(), '/pages/admin/clinic.html');
    const cached = await sibling.API.getStaffIdentity();
    assert.equal(cached.user.staff_subrole, roles[0]);
    // An uncached sibling starts an old-token identity read before the switch.
    const pendingTab = profile.page();
    pauseIdentity = true;
    const pending = pendingTab.API.getStaffIdentity();
    await first.API.signIn(roles[1], 'password', true);
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

function verificationPageHarness({ search, hash, pendingLogin = false, role = 'customer', deliveryStatus = null, saved = {} }) {
  const source = fs.readFileSync(
    path.join(projectRoot, "scripts/auth/client/verify-email.js"),
    "utf8",
  );
  const events = [];
  const elements = new Map();
  const elementListeners = new Map();
  const intervals = new Map();
  const windowHandlers = {};
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
      async registrationSession() { return {session_token:'11|cookie',user:{role:'customer'}}; },
      verifyEmail(token, review = false) {
        events.push({ type: "verify", token, review });
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
        return {resend_after:60};
      },
    },
    URL,
    URLSearchParams,
    Date: { now: () => now, parse: Date.parse },
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
    sessionStorage: new MemoryStorage(saved),
    setTimeout(callback) { callback(); },
    setInterval(callback) {
      const id = ++nextIntervalId;
      intervals.set(id, callback);
      return id;
    },
    clearInterval(id) { intervals.delete(id); },
    location,
    window: { location, clearTimeout() {}, setTimeout() {}, addEventListener(name, callback) { windowHandlers[name] = callback; } },
  };

  if (!context.sessionStorage.getItem('pendingLoginResendUntil')) context.sessionStorage.setItem("pendingLoginResendUntil", String(now + 60000));
  if (!context.sessionStorage.getItem('pendingLoginExpiresAt')) context.sessionStorage.setItem('pendingLoginExpiresAt', String(now + 600000));
  vm.createContext(context);
  const inline = fs.readFileSync(path.join(projectRoot, 'pages/client/verify-email.html'), 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
  vm.runInContext(inline, context);
  vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/verification-code.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/email-delivery.js'), 'utf8'), context);
  vm.runInContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/client/signup-context.js'), 'utf8'), context);
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
    windowHandlers,
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

async function testVerificationCredentialsAreScrubbedBeforeUse() {
  const expiredRegistration = verificationPageHarness({search: '', hash: '#token=expired-token'});
  expiredRegistration.API.verifyEmail = async () => { throw {status:422, code:'registration_expired', message:'This registration has expired. Start again.'}; };
  await expiredRegistration.dispatch('verifyLinkSubmit', 'click');
  assert.equal(expiredRegistration.elements.get('errorMessage').textContent, 'This registration has expired. Start again.');
  assert.equal(expiredRegistration.elements.get('resendSection').classList.contains('hidden'), true);
  assert.equal(expiredRegistration.elements.get('verificationBackAction').href, './signup.html');
  assert.equal(expiredRegistration.elements.get('verificationBackAction').textContent, 'Back to sign up');

  const expiredLink = verificationPageHarness({search:'', hash:'#token=old-token'});
  expiredLink.API.verifyEmail = async () => { throw {status:422, code:'registration_link_expired', message:'This link has expired. Request a new one.'}; };
  await expiredLink.dispatch('verifyLinkSubmit', 'click');
  assert.equal(expiredLink.elements.get('errorMessage').textContent, 'This link has expired. Request a new one.');
  assert.equal(expiredLink.elements.get('resendSection').classList.contains('hidden'), false);
  let recoveryRequests = 0;
  let finishRecovery;
  expiredLink.elements.get('resendEmail').value = 'customer@gmail.com';
  expiredLink.API.resendVerification = () => { recoveryRequests++; return new Promise(resolve => {finishRecovery = resolve;}); };
  const recovering = expiredLink.dispatch('resendForm', 'submit');
  await expiredLink.dispatch('resendForm', 'submit');
  assert.equal(recoveryRequests, 1, 'Repeated recovery submissions must share the pending request.');
  assert.equal(expiredLink.elements.get('resendSubmit').disabled, true);
  finishRecovery({resend_after:60});
  await recovering;
  assert.equal(expiredLink.elements.get('resendSubmit').disabled, false);

  const fragmentEvents = verificationPageHarness({
    search: "?token=legacy-token&campaign=welcome",
    hash: "#token=fragment-token",
  });
  assert.deepEqual(fragmentEvents.events, [
    { type: "scrub", target: "/pages/client/verify-email.html?campaign=welcome" },
  ], 'Loading a link must scrub credentials without verifying');
  fragmentEvents.dispatch('verifyLinkSubmit','click');
  assert.deepEqual(fragmentEvents.events.at(-1), {type:'verify',token:'fragment-token', review:false});
  fragmentEvents.dispatch('verifyLinkSubmit','click');
  assert.equal(fragmentEvents.events.filter(event => event.type==='verify').length, 1);

  const legacyEvents = verificationPageHarness({search:'?token=legacy-token',hash:''});
  assert.deepEqual(legacyEvents.events, [{type:'scrub',target:'/pages/client/verify-email.html'}]);
  legacyEvents.dispatch('verifyLinkSubmit','click');
  assert.deepEqual(legacyEvents.events.at(-1), {type:'verify',token:'legacy-token', review:false});

  const deliberateReview = verificationPageHarness({search:'', hash:'#token=review-token'});
  deliberateReview.dispatch('verifyLinkEdit', 'click');
  deliberateReview.dispatch('verifyLinkSubmit', 'click');
  assert.deepEqual(deliberateReview.events.filter(event => event.type === 'verify'), [{type:'verify', token:'review-token', review:true}]);

  const success = verificationPageHarness({search:'',hash:'#token=valid-token'});
  success.API.verifyEmail = async () => ({token:'11|cookie',user:{role:'customer'}});
  await success.dispatch('verifyLinkSubmit','click');
  assert.equal(success.elements.get('successTitle').textContent,'Account created successfully!');
  assert.equal(success.elements.get('successDashboard').classList.contains('hidden'),false);
  assert.doesNotMatch(success.elements.get('successMessage').textContent,/close this tab/);

  const foreign = verificationPageHarness({search:'',hash:'#token=foreign-token'});
  foreign.API.verifyEmail = async () => ({requires_registration_review:true, registration_recovery_token:'private-grant', registration_recovery_expires_at:'1970-01-01T00:11:40.000Z',
    details:{first_name:'Saved',last_name:'Owner',email:'owner@gmail.com'}});
  await foreign.dispatch('verifyLinkSubmit','click');
  assert.equal(foreign.storage.getItem('pendingRegistrationRecoveryToken'),'private-grant');
  assert.equal(foreign.storage.getItem('pendingRegistrationRecoveryUntil'),'700000');
  assert.deepEqual(foreign.events.at(-1),{type:'redirect',target:'./signup.html?review=1'});

  const unavailable = verificationPageHarness({search:'',hash:'#token=valid-token'});
  unavailable.API.verifyEmail = async () => ({token:'11|cookie',user:{role:'customer'}});
  unavailable.API.registrationSession = async () => {throw {status:401};};
  await unavailable.dispatch('verifyLinkSubmit','click');
  assert.equal(unavailable.elements.get('stateError').classList.contains('hidden'),false);
  assert.equal(unavailable.elements.get('stateSuccess').classList.contains('hidden'),true);

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
  let page = verificationPageHarness({
    search: "?mode=login",
    hash: "",
    pendingLogin: true,
  });
  let resend = page.elements.get("loginCodeResendBtn");

  assert.equal(resend.disabled, true);
  page.windowHandlers.pagehide({persisted:true});
  page.advanceSeconds(10);
  page.windowHandlers.pageshow({persisted:true});
  assert.equal(page.elements.get('loginCodeResendCountdown').textContent, 'Resend code in 50s');
  page.advanceSeconds(49);
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
    assert.equal(input.value, digits, 'Rejected resends must retain the entered code');
    assert.equal(page.elements.get('loginCodeSubmit').disabled, true);
    await page.dispatch('loginCodeResendBtn', 'click');
    assert.equal(requests, 1);
    reject({ status: 503 });
    await sending;
    assert.equal(input.value, digits);
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
  assert.equal(page.elements.get("loginCodeResendCountdown").textContent, "Resend code in 2m 08s");
  page.advanceSeconds(127);
  assert.equal(resend.disabled, true);
  page.advanceSeconds(1);
  assert.equal(resend.disabled, false, "Server throttling must not permanently lock resends");
  page = verificationPageHarness({search:'?mode=login',hash:'',pendingLogin:true});
  resend = page.elements.get('loginCodeResendBtn');
  page.API.confirmLoginCode = async () => { throw {status:429, code:'login_code_locked', retryAfter:65}; };
  page.elements.get('loginCode').value = '123456';
  await page.dispatch('loginCodeForm', 'submit');
  assert.equal(page.elements.get('loginCodeMessage').textContent, 'Too many incorrect codes. Request a new code in 1m 05s.');
  assert.equal(page.elements.get('loginCodeResendLock').classList.contains('hidden'), false);
  assert.equal(resend.classList.contains('hidden'), false);
  assert.equal(page.elements.get('loginCodeResendCountdown').classList.contains('hidden'), true);
  assert.equal(page.elements.get('loginCodeMessage')['aria-live'], 'off');
  assert.equal(page.elements.get('loginCodeSubmit').disabled, true);
  page.advanceSeconds(64);
  assert.equal(page.elements.get('loginCodeMessage').textContent, 'Too many incorrect codes. Request a new code in 0m 01s.');
  page.advanceSeconds(1);
  assert.equal(page.elements.get('loginCodeSubmit').disabled, true, 'Exhausted code stays disabled after cooldown');
  assert.equal(page.elements.get('loginCode').disabled, true);
  assert.equal(resend.disabled, false);
  assert.equal(page.elements.get('loginCodeResendLock').classList.contains('hidden'), true);
  assert.equal(page.elements.get('loginCodeMessage').textContent, '');
  assert.equal(page.events.filter(event => event.type === 'resend-login-code').length, 0, 'Timer completion never sends mail');
  page.API.resendLoginCode = async () => { throw {status:429, code:'email_rate_limited', retryAfter:120, data:{resend_limited:true}}; };
  await page.dispatch('loginCodeResendBtn', 'click');
  assert.equal(page.elements.get('loginCode').disabled, true, 'Rejected resend cannot restore an exhausted code');
  assert.match(page.elements.get('loginCodeMessage').textContent, /Too many requests/);
  page.advanceSeconds(120);
  page.API.resendLoginCode = async () => ({resend_after:60});
  await page.dispatch('loginCodeResendBtn', 'click');
  assert.equal(page.elements.get('loginCode').value, '');
  assert.equal(page.elements.get('loginCode').disabled, false);
  assert.equal(page.elements.get('loginCodeSubmit').disabled, true);
  assert.equal(page.elements.get('loginCodeMessage').textContent, '');
  page.advanceSeconds(60);
  page.API.resendLoginCode = async () => { throw {status:429, code:'login_code_locked', retryAfter:65}; };
  await page.dispatch('loginCodeResendBtn', 'click');
  assert.equal(page.elements.get('loginCode').disabled, true, 'An OTP attempt lockout reported by resend also blocks verification');
  assert.match(page.elements.get('loginCodeMessage').textContent, /1m 05s/);
  page.advanceSeconds(65);
  assert.equal(page.elements.get('loginCode').disabled, true);
  assert.equal(resend.disabled, false);

  const reload = verificationPageHarness({search:'?mode=login',hash:'',pendingLogin:true,
    saved:{pendingLoginCodeExhausted:'1',pendingLoginVerifyUntil:'140000'}});
  assert.equal(reload.elements.get('loginCode').disabled, true);
  assert.match(reload.elements.get('loginCodeMessage').textContent, /40s/);
  reload.advanceSeconds(40);
  assert.equal(reload.elements.get('loginCode').disabled, true);
  reload.advanceSeconds(20);
  assert.equal(reload.elements.get('loginCodeResendBtn').disabled, false);
  reload.advanceSeconds(540);
  assert.equal(reload.elements.get('stateError').classList.contains('hidden'), false);
  assert.equal(reload.elements.get('errorMessage').textContent, 'Sign-in expired. Please sign in again.');
  assert.equal(reload.elements.get('loginCodeResendBtn').disabled, true);

  const busy = verificationPageHarness({search:'?mode=login',hash:'',pendingLogin:true});
  busy.API.confirmLoginCode = async () => {throw {status:429,code:'login_code_busy',retryAfter:1};};
  busy.elements.get('loginCode').value='123456';
  await busy.dispatch('loginCodeForm','submit');
  busy.advanceSeconds(1);
  assert.equal(busy.elements.get('loginCode').disabled,false,'Busy response does not exhaust the code');
  assert.equal(busy.elements.get('loginCodeResendLock').classList.contains('hidden'),true);
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

function signInPageHarness({ storage = new MemoryStorage(), signIn = async () => { throw { status: 401, message: 'Invalid credentials.' }; } } = {}) {
  let elapsed = 0, wall = 100000, nextTimer = 0;
  const intervals = new Map(), lifecycle = {}, events = {}, elements = new Map();
  function element(id) {
    if (!elements.has(id)) {
      const classes = new Set();
      elements.set(id, { value: '', textContent: '', disabled: false, hidden: true, type: 'password', attributes: {}, handlers: {},
        set innerHTML(value) { this.textContent = value; }, get innerHTML() { return this.textContent; },
        classList: { add(c) { classes.add(c); }, toggle(c, on) { if (on) classes.add(c); else classes.delete(c); } },
        setAttribute(k, v) { this.attributes[k] = v; }, focus() {},
        addEventListener(k, f) { const previous = this.handlers[k]; this.handlers[k] = e => { previous?.(e); return f.call(this, e); }; },
      });
    }
    return elements.get(id);
  }
  const API = { signIn };
  const context = { API, URL, URLSearchParams, sessionStorage: storage,
    Date: class extends Date { static now() { return wall; } }, performance: { now: () => elapsed },
    setInterval(f) { const id = ++nextTimer; intervals.set(id, f); return id; }, clearInterval(id) { intervals.delete(id); },
    window: { location: { search: '', replace() {} }, addEventListener(k, f) { lifecycle[k] = f; } },
    document: { getElementById: element, querySelector: () => null,
      addEventListener(k, f) { if (k === 'DOMContentLoaded') f(); else events[k] = f; } },
  };
  vm.runInNewContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/verification-code.js'), 'utf8'), context);
  vm.runInNewContext(fs.readFileSync(path.join(projectRoot, 'scripts/auth/sign-in.js'), 'utf8'), context);
  element('identifier').value = 'customer@example.com'; element('password').value = 'password';
  return { element, API, intervals, lifecycle, storage,
    submit: () => element('signin').handlers.submit({ preventDefault() {} }),
    edit(value) { element('identifier').value = value; element('identifier').handlers.input?.(); },
    advance(ms, { tick = true, wallDelta = ms } = {}) { elapsed += ms; wall += wallDelta; if (tick) for (const f of [...intervals.values()]) f(); },
    visible() { events.visibilitychange?.(); },
  };
}

async function testSignInCountdownOnlyUsesApplicableServerPasswordOrIpResponses() {
  const key = 'signInLockoutUntil';
  for (const stored of ['36100000', 'Infinity', 'NaN', '-1', 'garbage', '999999999999999999999']) {
    const page = signInPageHarness({ storage: new MemoryStorage({ [key]: stored }) });
    assert.equal(page.element('sharedSignInSubmit').disabled, false, 'Stored timestamps cannot restrict sign-in');
    assert.equal(page.intervals.size, 0);
    assert.equal(page.storage.getItem(key), null, 'Remove the obsolete stored timer');
    await page.submit();
    assert.equal(page.element('sharedSignInMessage').textContent, 'Invalid credentials.');
  }
  for (const code of ['login_challenges_limited', 'sign_in_busy', null]) {
    const page = signInPageHarness({ signIn: async () => { throw { status: 429, code, retryAfter: 43200, message: 'Please try again later.' }; } });
    await page.submit();
    assert.equal(page.intervals.size, 0, `${code} is not a password cooldown`);
    assert.equal(page.element('sharedSignInSubmit').disabled, false);
    assert.equal(page.element('sharedSignInMessage').textContent, 'Please try again later.');
  }
  const otp = signInPageHarness({signIn:async () => {throw {status:429,code:'login_code_locked',retryAfter:40};}});
  await otp.submit();
  assert.equal(otp.element('sharedSignInMessage').textContent,'Too many verification attempts. Try again in 40s.');
  assert.equal(otp.element('sharedSignInSubmit').disabled,true);
  otp.advance(20000);
  assert.match(otp.element('sharedSignInMessage').textContent,/20s/);
  otp.advance(20000);
  assert.equal(otp.element('sharedSignInSubmit').disabled,false);
  assert.equal(otp.element('sharedSignInMessage').textContent,'');
  for (const duration of [null, undefined, '', 'bad', Infinity, NaN, -1, 0, 43200]) {
    const page = signInPageHarness({ signIn: async () => { throw { status: 429, code: 'sign_in_password_cooldown', retryAfter: duration }; } });
    await page.submit();
    assert.equal(page.intervals.size, 0, `Invalid duration ${duration} must not create a timer`);
    assert.equal(page.element('sharedSignInSubmit').disabled, false);
  }
  let requests = 0;
  const storage = new MemoryStorage();
  const page = signInPageHarness({ storage, signIn: async () => { requests++; throw { status: 429, code: 'sign_in_password_cooldown', retryAfter: 60 }; } });
  await page.submit();
  assert.equal(page.element('sharedSignInMessage').textContent, 'Too many attempts. Try again in 1m 00s.');
  assert.equal(page.element('sharedSignInSubmit').disabled, true);
  assert.equal(page.element('identifier').disabled, false);
  assert.equal(page.element('password').disabled, false);
  assert.equal(storage.getItem(key), null);
  assert.equal(page.intervals.size, 1);
  await page.submit(); assert.equal(requests, 1);
  const announcement = page.element('sharedSignInStatus').textContent;
  page.advance(1000, { wallDelta: -36000000 });
  assert.match(page.element('sharedSignInMessage').textContent, /59s/);
  assert.equal(page.element('sharedSignInStatus').textContent, announcement, 'Do not announce every second');
  page.lifecycle.pagehide({ persisted: true });
  page.advance(58000, { tick: false });
  page.lifecycle.pageshow({ persisted: true });
  assert.match(page.element('sharedSignInMessage').textContent, /1s/);
  assert.equal(page.intervals.size, 1);
  page.advance(1000, { tick: false }); page.visible();
  assert.equal(page.element('sharedSignInSubmit').disabled, false);
  assert.equal(page.element('sharedSignInMessage').textContent, '');
  assert.equal(page.intervals.size, 0);
  await page.submit(); assert.equal(requests, 2, 'Server response can establish a renewed restriction');

  const reloaded = signInPageHarness({ storage, signIn: async () => { throw { status: 429, code: 'sign_in_password_cooldown', retryAfter: 23 }; } });
  assert.equal(reloaded.element('sharedSignInSubmit').disabled, false);
  await reloaded.submit();
  assert.match(reloaded.element('sharedSignInMessage').textContent, /23s/);
  reloaded.edit('someone-else@example.com');
  assert.equal(reloaded.element('sharedSignInSubmit').disabled, false);
  assert.equal(reloaded.intervals.size, 0);
  reloaded.API.signIn = async () => { throw { status: 429, code: 'sign_in_ip_cooldown', retryAfter: 30 }; };
  await reloaded.submit(); reloaded.edit('another@example.com');
  assert.equal(reloaded.element('sharedSignInSubmit').disabled, true, 'IP restrictions apply across identifiers');
  reloaded.advance(30000);
  assert.equal(reloaded.element('sharedSignInSubmit').disabled, false);

  let reject;
  const delayed = signInPageHarness({ signIn: () => new Promise((_resolve, fail) => { reject = fail; }) });
  const pending = delayed.submit(); delayed.edit('other@example.com');
  reject({ status: 429, code: 'sign_in_password_cooldown', retryAfter: 300 }); await pending;
  assert.equal(delayed.element('sharedSignInSubmit').disabled, false, 'Old identifier response cannot block an edited identifier');
  assert.equal(delayed.intervals.size, 0);

  const expired = signInPageHarness({ signIn: async () => { throw { status: 429, code: 'sign_in_password_cooldown', retryAfter: 60 }; } });
  await expired.submit(); expired.advance(60000, { tick: false });
  expired.API.signIn = async () => { throw { status: 401, message: 'Invalid credentials.' }; };
  await expired.submit(); expired.advance(1000);
  assert.equal(expired.element('sharedSignInMessage').textContent, 'Invalid credentials.', 'An expired suspended timer cannot erase the next response');
  assert.equal(expired.intervals.size, 0);
}

async function testSignInEmailLimitFeedback() {
  const cases = [
    [{next_eligible_at:'2026-10-29T20:31:58Z'}, undefined, 'Oct 30, 2026 at 4:32 AM PHT'],
    [{next_eligible_at:'2026-10-31T23:59:01+08:00'}, undefined, 'Nov 1, 2026 at 12:00 AM PHT'],
    [{next_eligible_at:'2026-12-31T16:00:00Z'}, undefined, 'Jan 1, 2027 at 12:00 AM PHT'],
    [{next_eligible_at:'2026-10-30T04:32:00+08:00'}, undefined, 'Oct 30, 2026 at 4:32 AM PHT'],
  ];
  for (const [data, retryAfter, expected] of cases) {
    const page = signInPageHarness({signIn:async () => {throw {status:429,code:'email_rate_limited',data,retryAfter};}});
    await page.submit();
    assert.equal(page.element('sharedSignInMessage').textContent, `Sign-in email limit reached. Try again ${expected}.`);
    assert.match(page.element('sharedSignInMessage').className, /border-red-200/);
    assert.equal(page.element('sharedSignInMessage').attributes.role, 'alert');
    assert.equal(page.element('sharedSignInSubmit').disabled, false);
    assert.equal(page.intervals.size, 0);
    page.edit('another@example.com');
    assert.equal(page.element('sharedSignInMessage').textContent, '');
  }
  for (const timestamp of [undefined, '', 'bad', 123, '2026-02-30T10:00:00Z', '2026-10-30T04:32:00']) {
    const page = signInPageHarness({signIn:async () => {throw {status:429,code:'email_rate_limited',data:{next_eligible_at:timestamp,retry_after:7200}};}});
    await page.submit();
    assert.equal(page.element('sharedSignInMessage').textContent, 'Sign-in email limit reached. Try again in about 2 hours.');
  }
  for (const seconds of [undefined, null, '', 'bad', Infinity, -1, 0, true]) {
    const page = signInPageHarness({signIn:async () => {throw {status:429,code:'email_rate_limited',retryAfter:seconds};}});
    await page.submit();
    assert.equal(page.element('sharedSignInMessage').textContent, 'Sign-in email limit reached. Please try again later.');
    assert.equal(page.element('sharedSignInSubmit').disabled, false);
  }
  for (const [seconds, expected] of [[60,'1 minute'],[30,'30 seconds'],[61,'2 minutes']]) {
    const page = signInPageHarness({signIn:async () => {throw {status:429,code:'email_cooldown',retryAfter:seconds};}});
    await page.submit();
    assert.equal(page.element('sharedSignInMessage').textContent, `Please wait ${expected} before requesting another sign-in email.`);
    assert.equal(page.element('sharedSignInSubmit').disabled, false);
    assert.equal(page.intervals.size, 0);
  }
  const shortMissing = signInPageHarness({signIn:async () => {throw {status:429,code:'email_cooldown',retryAfter:NaN};}});
  await shortMissing.submit();
  assert.equal(shortMissing.element('sharedSignInMessage').textContent, 'Please wait before requesting another sign-in email.');
  const headerDuration = signInPageHarness({signIn:async () => {throw {status:429,code:'email_rate_limited',retryAfter:120,data:{retry_after:7200}};}});
  await headerDuration.submit();
  assert.equal(headerDuration.element('sharedSignInMessage').textContent, 'Sign-in email limit reached. Try again in about 2 minutes.');
  headerDuration.advance(60000);
  assert.equal(headerDuration.element('sharedSignInMessage').textContent, 'Sign-in email limit reached. Try again in about 2 minutes.', 'Email feedback does not run a second countdown');
  let reject;
  const delayed = signInPageHarness({signIn:() => new Promise((_resolve, fail) => {reject=fail;})});
  const pending=delayed.submit(); delayed.edit('other@example.com');
  reject({status:429,code:'email_rate_limited',retryAfter:7200}); await pending;
  assert.equal(delayed.element('sharedSignInMessage').textContent, '');
}

async function testApiRetryAfterParsingPreservesSecurityLimitClassification() {
  for (const [header, date, body, expected] of [
    ['23', null, 60, 23], [null, null, 60, 60], ['bad', null, 60, 60],
    ['Infinity', null, -1, null], ['-5', null, 'bad', null], ['0', null, 60, 0],
    ['Wed, 01 Jan 2025 00:01:00 GMT', 'Wed, 01 Jan 2025 00:00:00 GMT', 99, 60],
    ['Tue, 31 Dec 2024 23:59:00 GMT', 'Wed, 01 Jan 2025 00:00:00 GMT', 99, 0],
  ]) {
    const browser = createBrowser({ fetchImpl: async () => ({ ...jsonResponse(429, {code: 'sign_in_password_cooldown', retry_after: body}),
      headers: { get: name => name === 'Retry-After' ? header : name === 'Date' ? date : null } }) });
    await assert.rejects(browser.API.signIn('customer@example.com', 'wrong'), error => {
      assert.equal(error.retryAfter, expected); assert.equal(error.code, 'sign_in_password_cooldown'); return true;
    });
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
      performance: { now: () => now },
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
  assert.equal(forgot.getElement('resendCode').textContent, 'Resend in 1m 15s');
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
  assert.equal(resendLimited.getElement('resendCode').textContent, 'Resend in 8m 30s');
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
  assert.doesNotMatch(verify, /login_token|approveLogin|pollLoginConfirmation/);
  assert.match(verify, /await API\.confirmLoginCode\(code\)/);
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

async function testRegistrationHandoffAcceptsTheSessionBeforeAcknowledgement() {
  const calls = [];
  const browser = createBrowser({fetchImpl: async (url, options) => {
    const body = JSON.parse(options.body);
    calls.push(body);
    if (body.acknowledge) assert.equal(browser.API.getCustomerToken(), '11|cookie');
    return jsonResponse(200, {session_token:'11|cookie', user:{user_id:7,role:'customer',email:'owner@gmail.com',first_name:'Owner',last_name:'Test'}});
  }});
  await Promise.all([browser.API.registrationSession('owner@gmail.com', true), browser.API.registrationSession('owner@gmail.com', true)]);
  assert.deepEqual(calls, [{email:'owner@gmail.com',acknowledge:false},{email:'owner@gmail.com',acknowledge:true}]);
  const cacheGeneration = browser.localStorage.getItem('bethlehem.customer.cache_session');
  assert.ok(cacheGeneration);
  await browser.API.registrationSession('owner@gmail.com');
  assert.equal(browser.localStorage.getItem('bethlehem.customer.cache_session'), cacheGeneration, 'Checking handoff must not reset customer caches');
  const mismatch = createBrowser({fetchImpl: async () => jsonResponse(200, {session_token:'11|cookie',user:{role:'customer',email:'other@gmail.com'}})});
  assert.equal(await mismatch.API.registrationSession('owner@gmail.com', true), null);
  assert.equal(mismatch.API.getCustomerToken(), null);
}

async function testInitialCustomerBrowserSessionMarker() {
  const user = { user_id: 7, role: "customer", first_name: "Gerald", last_name: "Senining" };
  const browser = createBrowser({ fetchImpl: async url => jsonResponse(200,
    url.endsWith('/logout') ? {success: true} : { token: "session-token", user }) });
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
  await login.API.signIn('staff', 'password', true);
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
  await testEveryRoleHonorsTheServerConfirmedCheckbox();
  await testCustomerRestorationIsDeduplicatedAndQueuesOnlySafeReads();
  await testNetworkFailurePreservesReferencesAndDoesNotProveDeviceInvalidity();
  await testDelayedRestoreCannotOverwriteANewerAccount();
  await testUnconfirmedLogoutBlocksRestorationAcrossNavigation();
  await testMultipleTabsShareOneRestorationWhenBrowserLocksAreAvailable();
  await testDelayedRestoreRejectionAndUncachedReadsCannotAffectNewAccount();
  await testStorageRestrictionsDoNotCreateAnUntrackableRememberedSession();
  await testExpiredReadsCanRetryAfterRestorationButMutationsCannot();
  await testUnconfirmableOldLogoutAllowsOnlyDeliberateFullSignInRecovery();
  await testLogoutRequiresAnExplicitServerRevocationAcknowledgement();
  await testMixedCrossTabAccountSwitchHidesOnlyTheObsoleteTabState();
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
  await testRegistrationHandoffAcceptsTheSessionBeforeAcknowledgement();
  await testLoginCodeHonorsRememberMeStorage();
  await testTemporaryVerificationThrottlingPreservesTheBrowserChallenge();
  await testProfileNameLivesAndDiesWithTheSession();
  testProfileNameIsAppliedWithoutWaitingForDomContentLoaded();
  await testCustomerPasswordStepDoesNotCreateBrowserSession();
  await testDitoPhoneIdentifiersKeepExistingSignInNormalization();
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
  await testVerificationCredentialsAreScrubbedBeforeUse();
  await testLoginCodeIsEnteredOnTheOriginalTab();
  await testLoginCodeResendCooldownAndLimit();
  await testLoginDeliveryErrorsAndPrivilegedRedirects();
  await testPasswordResetRecovery();
  await testSignInCountdownOnlyUsesApplicableServerPasswordOrIpResponses();
  await testSignInEmailLimitFeedback();
  await testApiRetryAfterParsingPreservesSecurityLimitClassification();
  testStaticAuthContracts();
  console.log("frontend auth/session regression checks passed");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
