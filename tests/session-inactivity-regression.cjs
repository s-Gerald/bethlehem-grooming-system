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
  const token = `${role}-token`;
  const localStorage = options.storage || new MemoryStorage({
    [tokenKey]: token,
    user_role: role,
  });
  const sessionStorage = new MemoryStorage();

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
    return {style: {}, attributes: {}, hidden: false, value: "", disabled: false,
      setAttribute(name, value) { this.attributes[name] = String(value); },
      removeAttribute(name) { delete this.attributes[name]; },
      addEventListener(name, handler) { handlers.set(name, handler); },
      focus() { document.activeElement = this; },
      querySelector(selector) { return elements.get(selector.replace("#", "")) || null; },
      querySelectorAll() { return []; },
      dispatch(name, extra = {}) { return handlers.get(name)?.({preventDefault() {}, ...extra}); },
      contains(target) { return [...elements.values()].includes(target); },
    };
  }
  for (const id of ["session-password", "session-unlock", "session-error", "session-password-toggle", "session-other-account", "session-lock-form", "session-lock-heading", "session-lock-description"]) elements.set(id, element());
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
    querySelector: () => null,
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
    clearTimeout: clearFakeTimeout,
    console,
    document,
    fetch: async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith("/logout") && fixture.failLogout) throw new Error("Offline");
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
      const response = jsonResponse(200, {success: true, locked: server.locked, server_now: new Date(now).toISOString(),
        idle_at: new Date(server.idleAt).toISOString(), expires_at: new Date(server.expiresAt).toISOString()});
      if (url.endsWith("/session") && fixture.pauseStatus) return new Promise(resolve => { fixture.releaseStatus = () => resolve(response); });
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
    elements,
    get unlockRequests() { return unlockedRequests; },
    dispatchWindow(name, event = {}) { for (const handler of windowListeners.get(name) || []) handler(event); },
    dispatchSynthetic(name) { for (const handler of documentListeners.get(name) || []) handler({type:name, isTrusted:false}); },
    async submit(password) { elements.get("session-password").value = password; await elements.get("session-lock-form").dispatch("submit"); },
    async settle() { for (let i = 0; i < 30; i++) await Promise.resolve(); },
    localStorage,
    replacements,
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
  const browser = createIdleBrowser("admin");
  await browser.settle();
  const checks = () => browser.requests.filter(request => request.url.endsWith("/session")).length;
  const initialChecks = checks();
  browser.dispatchWindow("pageshow", { persisted: false });
  assert.equal(browser.root.attributes["data-session-gate"], undefined, "Fresh pageshow must not conceal an already verified shell");
  await browser.settle();
  assert.equal(checks(), initialChecks);
  browser.dispatchWindow("pageshow", { persisted: true });
  assert.equal(browser.root.attributes["data-session-gate"], "checking", "Restored pages must verify access again");
  await browser.settle();
  assert.equal(checks(), initialChecks + 1);
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
  fixture.releaseStatus();
  await browser.settle();
  assert.equal(browser.API.isSessionLocked(), false, "an older status response cannot undo successful password verification");
}

async function testFailedLogoutRetainsRevocationRetry() {
  const fixture = {failLogout:true};
  const browser = createIdleBrowser("admin", fixture);
  await browser.settle();
  await browser.advance(30);
  await browser.elements.get("session-other-account").dispatch("click");
  assert.equal(browser.localStorage.getItem("admin_token"), "admin-token");
  assert.deepEqual(browser.replacements, []);
  assert.equal(browser.elements.get("session-error").textContent, "Unable to sign out. Please try again.");
  fixture.failLogout = false;
  await browser.elements.get("session-other-account").dispatch("click");
  assert.equal(browser.localStorage.getItem("admin_token"), null);
}

async function testCustomerThirtyMinuteTimeout() {
  const browser = createIdleBrowser("customer");
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

(async () => {
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
  console.log("Session inactivity regression tests passed.");
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
