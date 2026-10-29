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

function createIdleBrowser(role, fixture = {}) {
  const options = fixture;
  let now = options.now ?? 1_000_000;
  const server = options.server || {idleAt: now + 30 * 60000,
    expiresAt: now + (role === "customer" ? 24 : 12) * 3600000, locked: false};
  let unlockedRequests = 0;
  let timerSequence = 0;
  const timers = new Map();
  const windowListeners = new Map();
  const documentListeners = new Map();
  const elements = new Map();
  const replacements = [];
  const requests = [];
  const tokenKey = role === "customer" ? "customer_token" : "admin_token";
  const token = options.token || `${role}-token`;
  const authSeed = {
    [tokenKey]: token,
    user_role: role,
  };
  const localStorage = options.storage || new MemoryStorage(options.remember === false ? {} : authSeed);
  const sessionStorage = new MemoryStorage(options.remember === false ? authSeed : {});

  function addListener(collection, name, handler) {
    const handlers = collection.get(name) || [];
    handlers.push(handler);
    collection.set(name, handlers);
  }

  function setFakeTimeout(callback, delay = 0) {
    const id = ++timerSequence;
    timers.set(id, { at: now + Math.max(0, Number(delay) || 0), callback });
    return id;
  }

  function clearFakeTimeout(id) {
    timers.delete(id);
  }

  async function advance(milliseconds) {
    const target = now + milliseconds;

    while (true) {
      const due = [...timers.entries()]
        .filter(([, timer]) => timer.at <= target)
        .sort((left, right) => left[1].at - right[1].at)[0];
      if (!due) break;

      const [id, timer] = due;
      timers.delete(id);
      now = timer.at;
      timer.callback();
      for (let i = 0; i < 20; i++) await Promise.resolve();
    }

    now = target;
    for (let i = 0; i < 20; i++) await Promise.resolve();
  }

  function element() {
    const handlers = new Map();
    return {style: {}, attributes: {}, changes: [], hidden: false, value: "", disabled: false,
      setAttribute(name, value) { this.attributes[name] = String(value); this.changes.push([name, String(value)]); },
      removeAttribute(name) { delete this.attributes[name]; this.changes.push([name, null]); },
      addEventListener(name, handler) { handlers.set(name, handler); },
      focus() { document.activeElement = this; },
      querySelector(selector) { return elements.get(selector.replace("#", "")) || null; },
      querySelectorAll() { return []; },
      dispatch(name, extra = {}) { return handlers.get(name)?.({preventDefault() {}, ...extra}); },
      contains(target) { return [...elements.values()].includes(target); },
    };
  }
  for (const id of ["session-password", "session-unlock", "session-error", "session-password-toggle", "session-other-account", "session-lock-form", "session-lock-heading", "session-lock-description", "session-retry"]) elements.set(id, element());
  const dashboard = element();
  const root = element();
  const body = {
    appendChild(element) {
      elements.set(element.id, element);
      return element;
    },
  };
  const document = {
    body,
    documentElement: root,
    activeElement: dashboard,
    head: {appendChild() {}},
    querySelectorAll: () => [dashboard],
    visibilityState: "visible",
    querySelector: selector => selector === '[data-admin-shell]' && options.shell !== false ? dashboard : null,
    getElementById(id) {
      return elements.get(id) || null;
    },
    createElement() { return element(); },
    addEventListener(name, handler) {
      addListener(documentListeners, name, handler);
    },
  };
  const location = {
    protocol: "https:",
    hostname: "clinic.example",
    port: "",
    origin: "https://clinic.example",
    pathname: role === "customer"
      ? "/pages/client/dashboard.html"
      : "/pages/admin/dashboard.html",
    href: "",
    replace(target) {
      replacements.push(target);
      this.href = target;
    },
  };
  const window = {
    location,
    addEventListener(name, handler) {
      addListener(windowListeners, name, handler);
    },
  };
  class FakeDate extends Date {
    static now() {
      return now;
    }
  }

  const context = {
    AbortController,
    Date: FakeDate,
    FormData: class FormData {},
    URLSearchParams,
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options?.detail; } },
    clearTimeout: clearFakeTimeout,
    console,
    document,
    fetch: async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith('/session/restore')) return jsonResponse(401, {code: 'remembered_session_invalid'});
      if (fixture.replaced) return jsonResponse(401, {code: 'session_replaced'});
      if (fixture.user) assert.equal(options.headers.Authorization, `Bearer ${token}`, 'Every request uses this context\'s token');
      if (url.endsWith("/logout") && fixture.failLogout) throw new Error("Offline");
      if (url.endsWith("/session") && fixture.statusFailure !== undefined) {
        if (fixture.statusFailure === 0) throw new Error("Offline");
        return jsonResponse(fixture.statusFailure, {message: "Status unavailable"});
      }
      if (now >= server.expiresAt || (role === "customer" && now >= server.idleAt)) return jsonResponse(401, {code: "session_expired"});
      if (now >= server.idleAt) server.locked = true;
      if (url.endsWith("/logout")) return jsonResponse(200, {success: true});
      if (url.endsWith("/session/unlock")) {
        unlockedRequests++;
        if (fixture.pauseUnlock) await new Promise(resolve => { fixture.releaseUnlock = resolve; });
        if (JSON.parse(options.body).password !== "correct") return jsonResponse(422, {message: "Incorrect password."});
        server.locked = false;
        server.idleAt = now + 30 * 60000;
      }
      if (url.endsWith("/session/activity")) {
        if (server.locked) return jsonResponse(423, {code: "session_locked", locked: true});
        server.idleAt = now + 30 * 60000;
      }
      if (fixture.protectedLocked && !url.includes("/session")) return jsonResponse(423, {code: "session_locked"});
      const response = jsonResponse(200, {success: true, ...(fixture.user ? {
        user: fixture.user, user_id: fixture.user.user_id, role: fixture.user.role, sidebar_revision: `revision-${fixture.user.user_id}`,
      } : {}), locked: server.locked, server_now: new Date(now).toISOString(),
        idle_at: new Date(server.idleAt).toISOString(), expires_at: new Date(server.expiresAt).toISOString()});
      if (url.endsWith("/session") && fixture.pauseStatus) return new Promise(resolve => { fixture.releaseStatus = () => resolve(response); });
      if (url.endsWith("/session/activity") && fixture.pauseActivity) return new Promise(resolve => { fixture.releaseActivity = () => resolve(response); });
      return response;
    },
    localStorage,
    sessionStorage,
    setTimeout: setFakeTimeout,
    window,
  };

  vm.createContext(context);
  vm.runInContext(apiSource, context, { filename: "scripts/api.js" });

  return {
    API: context.API,
    server,
    get now() { return now; },
    sleep(minutes) { now += minutes * 60000; },
    root,
    dashboard,
    document,
    elements,
    get unlockRequests() { return unlockedRequests; },
    dispatchWindow(name, event = {}) { for (const handler of windowListeners.get(name) || []) handler(event); },
    listenerCount(name) { return (windowListeners.get(name) || documentListeners.get(name) || []).length; },
    dispatchSynthetic(name) { for (const handler of documentListeners.get(name) || []) handler({type:name, isTrusted:false}); },
    async submit(password) { elements.get("session-password").value = password; await elements.get("session-lock-form").dispatch("submit"); },
    async settle() { for (let i = 0; i < 30; i++) await Promise.resolve(); },
    localStorage,
    replacements,
    sessionStorage,
    requests,
    warning() {
      return elements.get("bethlehem-session-inactivity-warning") || null;
    },
    async advance(minutes) {
      await advance(minutes * 60 * 1000);
    },
    dispatchDocument(name) {
      for (const handler of documentListeners.get(name) || []) handler({ type: name, isTrusted: true });
    },
  };
}

async function testInitialPageShowDoesNotRestartVerification() {
  const fixture = {pauseStatus: true, now: 4_000_000, storage: new MemoryStorage({
    admin_token: "admin-token", user_role: "admin",
    "bethlehem.auth.last_activity": JSON.stringify({session: "admin:admin-token", at: 1}),
  })};
  const browser = createIdleBrowser("admin", fixture);
  assert.equal(browser.root.attributes["data-session-gate"], "checking", "New documents still require server verification");
  assert.equal(browser.elements.has("bethlehem-session-lock"), false, "Pending verification uses the safe page shell without creating a modal");
  assert.equal(browser.document.activeElement, browser.dashboard, "Verification does not steal focus");
  await assert.rejects(browser.API.adminStopToday(), error => error.status === 503);
  const pendingRead = browser.API.getMe("admin");
  browser.dispatchWindow("pageshow", {persisted: false});
  browser.dispatchWindow("focus");
  browser.dispatchDocument("visibilitychange");
  assert.equal(browser.requests.filter(request => request.url.endsWith("/session")).length, 1);
  assert.equal(browser.requests.some(request => request.url.endsWith("/me")), false, "Protected data waits for authorization");
  fixture.releaseStatus();
  await browser.settle();
  await pendingRead;
  const checks = () => browser.requests.filter(request => request.url.endsWith("/session")).length;
  const initialChecks = checks();
  browser.dispatchWindow("pageshow", { persisted: false });
  assert.equal(browser.root.attributes["data-session-gate"], undefined, "Fresh pageshow must not conceal an already verified shell");
  await browser.settle();
  assert.equal(checks(), initialChecks);
  browser.dispatchWindow("pageshow", { persisted: true });
  assert.equal(browser.root.attributes["data-session-gate"], "checking", "Restored pages must verify access again");
  assert.equal(browser.elements.has("bethlehem-session-lock"), false, "BFCache checking also keeps recovery UI out of the page");
  fixture.releaseStatus();
  await browser.settle();
  assert.equal(checks(), initialChecks + 1);
  const standaloneFixture = {pauseStatus: true, shell: false};
  const standalone = createIdleBrowser("admin", standaloneFixture);
  assert.equal(standalone.root.attributes["data-session-gate"], "checking");
  assert.equal(standalone.elements.has("bethlehem-session-lock"), false, "Standalone workflows verify without creating a checking card");
  assert.equal(standalone.document.activeElement, standalone.dashboard, "Checking must not steal workflow focus");
  let initialized = false;
  const initialization = standalone.API.waitForSessionVerification().then(allowed => { initialized = allowed; });
  assert.equal(initialized, false, "Stored tokens cannot initialize sensitive workflow state");
  await assert.rejects(standalone.API.adminStopToday(), error => error.status === 503);
  standaloneFixture.releaseStatus();
  await standalone.settle();
  await initialization;
  assert.equal(initialized, true);
  assert.equal(standalone.elements.has("bethlehem-session-lock"), false);
  const revoked = createIdleBrowser("staff", {statusFailure: 401, shell: false});
  assert.equal(await revoked.API.waitForSessionVerification(), false, "Revoked sessions cannot initialize retained workflow data");
  assert.equal(revoked.API.getAdminToken(), null);
}

async function testTabReturnKeepsUnlockedWorkflow(role) {
  const fixture = {token: "17|local-session-fixture"};
  const browser = createIdleBrowser(role, fixture);
  await browser.settle();
  await browser.advance(30);
  await browser.submit("correct");
  const card = browser.elements.get("bethlehem-session-lock");
  const revision = browser.API.getAdminDataRevision();
  browser.dashboard.value = "Unsaved appointment";
  browser.dashboard.scrollTop = 240;
  assert.equal(browser.document.activeElement, browser.dashboard, "Unlock restores workflow focus");
  browser.root.changes.length = 0;
  fixture.pauseStatus = true;
  for (let cycle = 0; cycle < 3; cycle++) {
    const before = browser.requests.filter(request => request.url.endsWith("/session")).length;
    browser.document.visibilityState = "hidden";
    browser.dispatchDocument("visibilitychange");
    browser.document.visibilityState = "visible";
    browser.dispatchDocument("visibilitychange");
    browser.dispatchWindow("focus");
    browser.dispatchWindow("focus");
    await browser.settle();
    assert.equal(browser.requests.filter(request => request.url.endsWith("/session")).length, before + 1,
      "Visibility and focus share one pending verification");
    assert.equal(browser.root.attributes["data-session-gate"], undefined, "Pending resume verification keeps the page visible");
    assert.equal(card.hidden, true, "A mounted password card stays hidden during verification");
    await browser.API.getClinicStatus();
    fixture.releaseStatus();
    await browser.settle();
  }
  assert.deepEqual(browser.root.changes, [], "Tab switching never changes the global gate");
  assert.equal(browser.elements.get("bethlehem-session-lock"), card);
  assert.equal(browser.API.getAdminDataRevision(), revision, "Resume preserves cached admin data");
  assert.equal(browser.document.activeElement, browser.dashboard);
  assert.equal(browser.dashboard.value, "Unsaved appointment");
  assert.equal(browser.dashboard.scrollTop, 240);
  assert.deepEqual(browser.replacements, []);
  for (const event of ["focus", "visibilitychange", "pagehide", "pageshow"]) assert.equal(browser.listenerCount(event), 1);
}

async function testVerificationFailuresAreNotPasswordLocks() {
  for (const statusFailure of [0, 500, 200]) {
    const fixture = {statusFailure};
    const browser = createIdleBrowser("admin", fixture);
    let initialized = false;
    const initialization = browser.API.waitForSessionVerification().then(allowed => { initialized = allowed; });
    await browser.settle();
    assert.equal(initialized, false, "Verification failure cannot initialize the workflow");
    assert.equal(browser.API.isSessionLocked(), false);
    assert.equal(browser.root.attributes["data-session-gate"], "unavailable");
    assert.equal(browser.elements.get("session-lock-form").hidden, true);
    assert.equal(browser.elements.get("session-retry").hidden, false);
    await assert.rejects(browser.API.adminStopToday(), error => error.status === 503 && error.code === "session_verification_unavailable");
    assert.equal(browser.requests.some(request => request.url.endsWith("/admin/clinic/stop-today")), false);
    delete fixture.statusFailure;
    fixture.pauseStatus = true;
    const retry = browser.elements.get("session-retry").dispatch("click");
    assert.equal(browser.elements.get("session-retry").disabled, true);
    assert.equal(browser.root.attributes["data-session-gate"], "unavailable", "Retry retains recovery feedback instead of another checking screen");
    await assert.rejects(browser.API.adminStopToday(), error => error.status === 503);
    fixture.releaseStatus();
    await retry;
    fixture.pauseStatus = false;
    await browser.settle();
    assert.equal(browser.root.attributes["data-session-gate"], undefined, "Explicit retry restores server-verified access");
    await initialization;
    assert.equal(initialized, true, "A successful retry initializes the waiting workflow");
    fixture.statusFailure = statusFailure;
    browser.root.changes.length = 0;
    browser.dispatchWindow("focus");
    await browser.settle();
    assert.equal(browser.API.isSessionLocked(), false);
    assert.deepEqual(browser.root.changes, [], "Background failure preserves a verified, unexpired workflow");
    browser.sleep(30);
    browser.dispatchWindow("focus");
    await browser.settle();
    assert.equal(browser.API.isSessionLocked(), true, "Connectivity cannot postpone the established lock deadline");
    assert.equal(browser.elements.get("session-lock-form").hidden, false);
  }
}

async function testRestorationRequiresFreshVerification() {
  const fixture = {};
  const browser = createIdleBrowser("admin", fixture);
  await browser.settle();
  fixture.pauseStatus = true;
  browser.dispatchWindow("focus");
  const releaseOldStatus = fixture.releaseStatus;
  browser.dispatchWindow("pagehide");
  assert.equal(browser.root.attributes["data-session-gate"], "checking");
  assert.equal(browser.elements.has("bethlehem-session-lock"), false, "Checking never creates a recovery modal");
  browser.dispatchWindow("pageshow", {persisted: true});
  const releaseRestoredStatus = fixture.releaseStatus;
  assert.notEqual(releaseOldStatus, releaseRestoredStatus, "Restoration cannot reuse a pre-suspension request");
  releaseOldStatus();
  await browser.settle();
  assert.equal(browser.root.attributes["data-session-gate"], "checking", "A pre-suspension response cannot authorize restoration");
  await assert.rejects(browser.API.adminStopToday(), error => error.status === 503);
  releaseRestoredStatus();
  await browser.settle();
  assert.equal(browser.root.attributes["data-session-gate"], undefined);
}

async function testOlderActivityCannotDismissConfirmedLock() {
  const fixture = {pauseActivity: true};
  const browser = createIdleBrowser("admin", fixture);
  await browser.settle();
  await browser.advance(1);
  browser.dispatchDocument("pointerdown");
  fixture.protectedLocked = true;
  await assert.rejects(browser.API.adminStopToday(), error => error.status === 423);
  assert.equal(browser.API.isSessionLocked(), true);
  fixture.releaseActivity();
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), true, "Activity started before a 423 cannot unlock the page");
}

async function testDelayedVerificationCannotExtendDeadlines() {
  for (const minutes of [30, 12 * 60]) {
    const fixture = {};
    const browser = createIdleBrowser("admin", fixture);
    await browser.settle();
    fixture.pauseStatus = true;
    browser.dispatchWindow("focus");
    browser.sleep(minutes);
    fixture.releaseStatus();
    await browser.settle();
    if (minutes === 30) assert.equal(browser.API.isSessionLocked(), true, "Response latency cannot move the established idle deadline");
    else {
      assert.equal(browser.API.getAdminToken(), null, "Response latency cannot extend absolute authentication");
      assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
    }
  }
}

async function testCrossTabVerificationPreservesActivePresentation() {
  const fixture = {};
  const browser = createIdleBrowser("admin", fixture);
  await browser.settle();
  fixture.pauseStatus = true;
  browser.root.changes.length = 0;
  const notice = () => browser.dispatchWindow("storage", {storageArea: browser.localStorage,
    key: "bethlehem.auth.session_state", newValue: "changed"});
  notice();
  assert.equal(browser.root.attributes["data-session-gate"], undefined);
  await assert.rejects(browser.API.adminStopToday(), error => error.status === 503,
    "Writes wait for authoritative cross-tab verification");
  fixture.releaseStatus();
  await browser.settle();
  assert.deepEqual(browser.root.changes, []);
  browser.dispatchWindow("focus");
  const releaseOlderActiveStatus = fixture.releaseStatus;
  browser.server.locked = true;
  notice();
  fixture.releaseStatus();
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), true);
  releaseOlderActiveStatus();
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), true, "Cross-tab state supersedes checks started before the signal");
  notice();
  assert.equal(browser.API.isSessionLocked(), true, "A storage signal alone never unlocks");
  fixture.releaseStatus();
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), true);
}

async function testResumeExpirationAndCustomerPolicy() {
  for (const role of ["admin", "staff", "customer"]) {
    const fixture = {};
    const browser = createIdleBrowser(role, fixture);
    await browser.settle();
    browser.dispatchWindow("focus");
    await browser.settle();
    assert.equal(browser.root.attributes["data-session-gate"], undefined);
    assert.equal(browser.API.isSessionLocked(), false);
    fixture.statusFailure = 401;
    if (role === 'customer') browser.sleep(1); // Reuse very recent verified status instead of requesting on every focus event.
    browser.dispatchWindow("focus");
    await browser.settle();
    assert.equal(browser.API.getUserRole(), null);
    assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
  }
}

async function testPrivilegedLockAndUnlock(role) {
  const browser = createIdleBrowser(role);
  const policy = browser.API.getSessionInactivityPolicy(role);
  assert.equal(policy.timeoutMs, 30 * 60 * 1000);
  await browser.settle();
  await browser.advance(29);
  browser.dispatchDocument("pointerdown");
  await browser.settle();
  await browser.advance(29);
  assert.equal(browser.API.isSessionLocked(), false);
  await browser.advance(1);
  assert.equal(browser.API.isSessionLocked(), true);
  assert.equal(browser.localStorage.getItem("admin_token"), `${role}-token`);
  assert.deepEqual(browser.replacements, []);
  assert.equal(browser.root.attributes["data-session-gate"], "locked");
  browser.dispatchDocument("keydown");
  await browser.advance(1);
  assert.equal(browser.API.isSessionLocked(), true, "interaction cannot dismiss a lock");
  const wrongAttempt = browser.submit("wrong");
  assert.equal(browser.elements.get("session-unlock").disabled, true);
  assert.equal(browser.elements.get("session-unlock").textContent, "Unlocking…");
  await wrongAttempt;
  assert.equal(browser.elements.get("session-unlock").disabled, false);
  assert.equal(browser.elements.get("session-error").textContent, "Incorrect password.");
  assert.equal(browser.elements.get("session-password").attributes["aria-invalid"], "true");
  assert.equal(browser.API.isSessionLocked(), true);
  const dataRevision = browser.API.getAdminDataRevision();
  await browser.submit("correct");
  assert.ok(browser.API.getAdminDataRevision() > dataRevision, "Unlock invalidates presentation data from before the lock");
  assert.equal(browser.API.isSessionLocked(), false);
  assert.equal(browser.root.attributes["data-session-gate"], undefined);
  assert.deepEqual(browser.replacements, [], "unlock preserves the mounted workflow and URL");
  assert.equal(browser.unlockRequests, 2);
  assert.equal(browser.requests.filter(request => request.url.endsWith("/logout")).length, 0);
  browser.elements.get("session-password-toggle").dispatch("click");
  assert.equal(browser.elements.get("session-password").type, "text");
}

async function testResumeAndStorageCannotUnlock() {
  const browser = createIdleBrowser("admin");
  await browser.settle();
  await browser.advance(30);
  browser.localStorage.setItem("bethlehem.auth.last_activity", JSON.stringify({session: "admin:admin-token", at: 9999999999999}));
  browser.dispatchWindow("storage", {storageArea: browser.localStorage, key: "bethlehem.auth.last_activity", newValue: "changed"});
  browser.dispatchWindow("focus");
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), true);
  browser.dispatchWindow("storage", {storageArea: browser.localStorage, key: "bethlehem.auth.session_state", newValue: "changed"});
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), true, "server must confirm every cross-tab unlock");
  await browser.advance(12 * 60 - 30);
  assert.equal(browser.localStorage.getItem("admin_token"), null);
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
}

async function testRefreshSleepAndRealCrossTabUnlock() {
  const first = createIdleBrowser("staff");
  assert.equal(first.root.attributes["data-session-gate"], "checking");
  await first.settle();
  const second = createIdleBrowser("staff", {storage:first.localStorage, server:first.server, now:first.now});
  await second.settle();
  first.sleep(30);
  second.sleep(30);
  first.dispatchWindow("focus");
  second.dispatchWindow("focus");
  await first.settle(); await second.settle();
  assert.equal(first.API.isSessionLocked(), true, "sleep doesn't reset activity");
  assert.equal(second.API.isSessionLocked(), true);
  const refreshed = createIdleBrowser("staff", {storage:first.localStorage, server:first.server, now:first.now});
  assert.ok(["checking", "locked"].includes(refreshed.root.attributes["data-session-gate"]), "refresh starts concealed");
  await refreshed.settle();
  assert.equal(refreshed.API.isSessionLocked(), true, "refresh cannot unlock");
  await first.submit("correct");
  const notice = first.localStorage.getItem("bethlehem.auth.session_state");
  second.dispatchWindow("storage", {storageArea:second.localStorage, key:"bethlehem.auth.session_state", newValue:notice});
  await second.settle();
  assert.equal(second.API.isSessionLocked(), false);
  assert.equal(second.root.attributes["data-session-gate"], undefined);
  assert.equal(second.unlockRequests, 0, "a tab accepts a server-confirmed unlock from its sibling");
}

async function testOnlyTrustedInteractionReportsActivity() {
  const browser = createIdleBrowser("admin");
  await browser.settle();
  const reports = () => browser.requests.filter(request => request.url.endsWith("/session/activity")).length;
  browser.dispatchSynthetic("pointerdown");
  browser.dispatchDocument("scroll");
  await browser.advance(10);
  assert.equal(reports(), 0, "script events, automatic scroll and background status requests cannot extend activity");
  browser.dispatchDocument("keydown");
  await browser.settle();
  assert.equal(reports(), 1);
  browser.dispatchDocument("pointerdown");
  await browser.settle();
  assert.equal(reports(), 1, "reports are throttled");
}

async function testManualLogoutFromLock() {
  const browser = createIdleBrowser("admin");
  await browser.settle();
  await browser.advance(30);
  await browser.elements.get("session-other-account").dispatch("click");
  assert.equal(browser.localStorage.getItem("admin_token"), null);
  assert.equal(browser.requests.at(-1).url, "https://clinic.example/api/logout");
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
}

async function testPausedReadsResumeOnceAndWritesAreNeverReplayed() {
  const browser = createIdleBrowser("admin");
  await browser.settle();
  await browser.advance(30);
  await assert.rejects(browser.API.adminStopToday(), error => error.status === 423);
  const first = browser.API.getMe("admin");
  const second = browser.API.getMe("admin");
  await browser.settle();
  assert.equal(browser.requests.filter(request => request.url.endsWith("/me")).length, 0);
  await browser.submit("correct");
  await Promise.all([first, second]);
  assert.equal(browser.requests.filter(request => request.url.endsWith("/me")).length, 1,
    "repeated polling while locked must not create a burst on unlock");
  assert.equal(browser.requests.filter(request => request.url.endsWith("/admin/clinic/stop-today")).length, 0,
    "blocked mutations are never replayed after unlock");
}

async function testDelayedStatusCannotRelockAfterPasswordUnlock() {
  const fixture = {};
  const browser = createIdleBrowser("admin", fixture);
  await browser.settle();
  await browser.advance(30);
  fixture.pauseUnlock = true;
  const pendingUnlock = browser.submit("correct");
  await browser.settle();
  fixture.pauseStatus = true;
  browser.dispatchWindow("focus");
  await browser.settle();
  fixture.releaseUnlock();
  await pendingUnlock;
  assert.equal(browser.API.isSessionLocked(), false);
  await browser.API.adminStopToday();
  fixture.releaseStatus();
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), false, "an older status response cannot undo successful password verification");
}

async function testFailedLogoutRetainsRevocationRetry() {
  for (const statusFailure of [undefined, 500]) {
    const fixture = {failLogout:true, statusFailure};
    const browser = createIdleBrowser("admin", fixture);
    await browser.settle();
    if (statusFailure === undefined) await browser.advance(30);
    await browser.elements.get("session-other-account").dispatch("click");
    assert.equal(browser.localStorage.getItem("admin_token"), null, 'Failed revocation must still remove the authenticated presentation immediately');
    assert.ok(browser.localStorage.getItem('bethlehem.auth.logout_pending'));
    assert.deepEqual(browser.replacements, []);
    const message = browser.elements.get("session-lock-description");
    assert.equal(message.textContent, "Unable to sign out. Please try again.", "Revocation failure stays visible even when the password form is hidden");
    fixture.failLogout = false;
    await browser.elements.get("session-other-account").dispatch("click");
    assert.equal(browser.localStorage.getItem("admin_token"), null);
  }
}

async function testCustomerThirtyMinuteTimeout() {
  const browser = createIdleBrowser("customer", {remember: false});
  await browser.settle();
  const policy = browser.API.getSessionInactivityPolicy("customer");
  assert.equal(policy.warningMs, 29 * 60 * 1000);
  assert.equal(policy.timeoutMs, 30 * 60 * 1000);

  await browser.advance(29);
  assert.equal(browser.warning().style.display, "block");
  assert.deepEqual(browser.replacements, []);

  await browser.advance(1);
  assert.equal(browser.localStorage.getItem("customer_token"), null);
  assert.equal(browser.replacements.at(-1), "/pages/client/sign-in.html");
}

async function testUnattendedRememberedCustomerDoesNotRestoreUntilTheUserReturns() {
  const browser = createIdleBrowser('customer');
  await browser.settle();
  await browser.advance(30);
  assert.equal(browser.root.attributes['data-session-gate'], 'checking');
  assert.equal(browser.requests.filter(request => request.url.endsWith('/session/restore')).length, 0);
  const reads = Promise.allSettled([browser.API.getMe(), browser.API.getMe()]);
  await browser.settle();
  await browser.advance(60);
  assert.equal(browser.requests.filter(request => request.url.endsWith('/session/restore')).length, 0,
    'Background polls cannot renew remembered-device inactivity');
  browser.dispatchDocument('pointerdown');
  await browser.settle();
  await reads;
  assert.equal(browser.requests.filter(request => request.url.endsWith('/session/restore')).length, 1);
  assert.equal(browser.replacements.at(-1), '/pages/client/sign-in.html', 'The fixture has no valid device credential');
}

async function testReceptionistContextsLockUnlockAndExpireIndependently() {
  for (const subroles of [['clinic_receptionist', 'grooming_receptionist'], ['grooming_receptionist', 'clinic_receptionist']]) {
    const [normal, privateWindow] = subroles.map((staff_subrole, index) => createIdleBrowser('staff', {
      token: `${701 + index}|${staff_subrole}-secret`,
      user: {user_id: index + 1, role: 'staff', staff_subrole,
        staff_type: staff_subrole === 'clinic_receptionist' ? 'clinic' : 'grooming',
        clinic_permissions: {access: true, clinical: false}, staff_permissions: {grooming: true}},
    }));
    await normal.settle(); await privateWindow.settle();
    await normal.API.getStaffIdentity(); await privateWindow.API.getStaffIdentity();
    assert.notEqual(normal.localStorage, privateWindow.localStorage);
    assert.notEqual(normal.sessionStorage, privateWindow.sessionStorage);
    await normal.advance(30);
    assert.equal(normal.API.isSessionLocked(), true);
    assert.equal(privateWindow.API.isSessionLocked(), false);
    await normal.submit('wrong');
    assert.equal(normal.API.isSessionLocked(), true);
    await normal.submit('correct');
    assert.equal(normal.API.isSessionLocked(), false);
    assert.equal(privateWindow.unlockRequests, 0);
    assert.equal(privateWindow.API.getCachedStaffIdentity().user.staff_subrole, subroles[1]);
    await normal.advance(12 * 60 - 30);
    assert.equal(normal.API.getAdminToken(), null);
    assert.equal(normal.replacements.at(-1), '/pages/client/sign-in.html');
    assert.equal(privateWindow.API.getCachedStaffIdentity().user.staff_subrole, subroles[1]);
    assert.equal((await privateWindow.API.getStaffIdentity()).user.staff_subrole, subroles[1]);
    assert.deepEqual(privateWindow.replacements, []);
  }
}

async function testReplacementDetectionReusesTheSessionLifecycle() {
  for (const role of ['admin', 'staff', 'customer']) {
    const fixture = {};
    const browser = createIdleBrowser(role, fixture);
    await browser.settle();
    const idleAt = browser.server.idleAt;
    await browser.advance(1);
    assert.equal(browser.server.idleAt, idleAt, 'Validation does not extend inactivity');
    const beforeHidden = browser.requests.length;
    browser.document.visibilityState = 'hidden';
    await browser.advance(2);
    assert.equal(browser.requests.length, beforeHidden, 'Hidden pages do not poll');
    fixture.replaced = true;
    browser.document.visibilityState = 'visible';
    browser.dispatchWindow('focus');
    browser.dispatchDocument('visibilitychange');
    await browser.settle();
    assert.equal(browser.requests.length, beforeHidden + 1, 'Focus and visibility share one validation');
    assert.equal(browser.sessionStorage.getItem('bethlehem.auth.signout_reason'), 'session_replaced');
    assert.deepEqual(browser.replacements, ['/pages/client/sign-in.html']);
    const after = browser.requests.length;
    await browser.advance(2);
    assert.equal(browser.requests.length, after, 'Replacement stops background validation');
  }
}

(async () => {
  await testReplacementDetectionReusesTheSessionLifecycle();
  await testTabReturnKeepsUnlockedWorkflow("admin");
  await testTabReturnKeepsUnlockedWorkflow("staff");
  await testVerificationFailuresAreNotPasswordLocks();
  await testRestorationRequiresFreshVerification();
  await testOlderActivityCannotDismissConfirmedLock();
  await testDelayedVerificationCannotExtendDeadlines();
  await testCrossTabVerificationPreservesActivePresentation();
  await testResumeExpirationAndCustomerPolicy();
  await testInitialPageShowDoesNotRestartVerification();
  await testPrivilegedLockAndUnlock("admin");
  await testPrivilegedLockAndUnlock("staff");
  await testResumeAndStorageCannotUnlock();
  await testRefreshSleepAndRealCrossTabUnlock();
  await testOnlyTrustedInteractionReportsActivity();
  await testManualLogoutFromLock();
  await testFailedLogoutRetainsRevocationRetry();
  await testDelayedStatusCannotRelockAfterPasswordUnlock();
  await testPausedReadsResumeOnceAndWritesAreNeverReplayed();
  await testCustomerThirtyMinuteTimeout();
  await testUnattendedRememberedCustomerDoesNotRestoreUntilTheUserReturns();
  await testReceptionistContextsLockUnlockAndExpireIndependently();
  console.log("Session inactivity regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
