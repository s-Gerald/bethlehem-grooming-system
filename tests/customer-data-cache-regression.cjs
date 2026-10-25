const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const root = path.resolve(__dirname, "..");
const source = fs.readFileSync(path.join(root, "scripts/api.js"), "utf8");
const pets = { success: true, pets: [{ pet_id: 1, pet_name: "Mochi", species: "Dog", is_archived: false }] };
const profile = { user: { user_id: 1, first_name: "Test", last_name: "Customer", role: "customer" } };
const bookings = { bookings: [], history: [], history_total: 0 };
let healthRecordTools;
let petWeightTools;
let normalizePetSize;

class Storage {
  constructor() { this.values = new Map(); }
  getItem(key) { return this.values.get(key) ?? null; }
  setItem(key, value) { this.values.set(key, String(value)); }
  removeItem(key) { this.values.delete(key); }
}

function deferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

function response(data, status = 200) {
  return { ok: status < 400, status, json: async () => data, headers: { get: () => null } };
}

function environment() {
  const localStorage = new Storage(), sessionStorage = new Storage();
  const calls = [];
  let now = 1000000;
  let handler = (endpoint) => endpoint === "/me" ? profile : endpoint.startsWith("/pets?") ? pets : bookings;
  function documentContext(page = "dashboard.html") {
    const events = new Map();
    const context = vm.createContext({
      Date: class extends Date { static now() { return now; } },
      Math, JSON, URLSearchParams, AbortController,
      setTimeout, clearTimeout, console, localStorage, sessionStorage,
      document: { getElementById: () => null, querySelector: () => null, addEventListener() {} },
      window: {
        location: { protocol: "http:", origin: "http://127.0.0.1:8000", hostname: "127.0.0.1", port: "8000", pathname: `/pages/client/${page}`, replace() {} },
        addEventListener: (name, callback) => events.set(name, callback),
      },
      fetch: async (url, options) => {
        const endpoint = url.replace("http://127.0.0.1:8000/api", "");
        calls.push({ endpoint, method: options.method, authorization: options.headers.Authorization });
        const result = await handler(endpoint, options);
        return result?.json ? result : response(result);
      },
    });
    vm.runInContext(source, context);
    return { API: context.API, context, events };
  }
  const initial = documentContext("sign-in.html");
  initial.API.setCustomerToken("1|synthetic-customer-secret");
  return {
    ...initial, documentContext, calls, localStorage, sessionStorage,
    advance: (milliseconds) => { now += milliseconds; },
    respond: (callback) => { handler = callback; },
  };
}

async function testCrossDocumentFlowAndColdMiss() {
  const env = environment();
  await env.API.getUserPets();
  await env.API.getMe();
  await env.API.getBookingHistory({ historyLimit: 3 });
  const myPets = env.documentContext("my-pets.html").API;
  let rendered = null;
  const before = env.calls.length;
  const load = myPets.loadCustomerData("/pets?archived=0", () => myPets.getUserPets(), (data) => { rendered = data.pets; });
  assert.equal(rendered[0].pet_name, "Mochi", "Cross-document pet content must render synchronously.");
  await load;
  assert.equal(env.calls.length, before, "My Pets must reuse Dashboard's fresh active pets without a request.");
  assert.equal(env.calls.filter((call) => call.endpoint === "/pets?archived=1").length, 0);
  const history = env.documentContext("grooming-history.html").API;
  assert.equal(history.readCustomerCache("/booking/history"), null, "Limited history cannot satisfy a full-history request.");
  await history.getBookingHistory();
  const settings = env.documentContext("settings.html").API;
  await settings.getMe();
  const dashboard = env.documentContext("dashboard.html").API;
  await dashboard.getUserPets();
  await dashboard.getBookingHistory({ historyLimit: 3 });
  assert.equal(env.calls.filter((call) => call.endpoint === "/pets?archived=0").length, 1);
  assert.equal(env.calls.filter((call) => call.endpoint === "/me").length, 1);
  assert.equal(env.calls.filter((call) => call.endpoint === "/booking/history").length, 1);
  const cold = environment();
  let coldRendered = false;
  const pending = deferred();
  cold.respond(() => pending.promise);
  const firstLoad = cold.API.loadCustomerData("/pets?archived=0", () => cold.API.getUserPets(), () => { coldRendered = true; });
  assert.equal(coldRendered, false, "Cold loads must wait for the real response.");
  assert.equal(cold.calls[0].endpoint, "/pets?archived=0");
  pending.resolve(pets);
  await firstLoad;
  assert.equal(coldRendered, true);
}

async function testRevalidationAndFailure() {
  const env = environment();
  await env.API.getUserPets();
  env.advance(61000);
  const api = env.documentContext("my-pets.html").API;
  const pending = deferred();
  env.respond(() => pending.promise);
  const rendered = [];
  const refresh = api.loadCustomerData("/pets?archived=0", () => api.getUserPets(), (data) => rendered.push(data.pets[0].pet_name));
  assert.deepEqual(rendered, ["Mochi"]);
  pending.resolve({ pets: [{ pet_id: 1, pet_name: "Updated" }] });
  await refresh;
  assert.deepEqual(rendered, ["Mochi", "Updated"]);
  env.advance(61000);
  env.respond(() => { throw new Error("offline"); });
  const failedRender = [];
  await api.loadCustomerData("/pets?archived=0", () => api.getUserPets(), (data) => failedRender.push(data.pets[0].pet_name));
  assert.deepEqual(failedRender, ["Updated"], "Offline refresh must retain the usable content.");
  env.respond(() => response({ message: "Server failure" }, 500));
  await api.loadCustomerData("/pets?archived=0", () => api.getUserPets(), () => {});
  env.respond(() => response({ message: "Forbidden" }, 403));
  await assert.rejects(api.loadCustomerData("/pets?archived=0", () => api.getUserPets(), () => {}), { status: 403 });
  env.advance(300001);
  assert.equal(api.readCustomerCache("/pets?archived=0"), null, "Retained data must expire.");
}

async function testDeduplicationAndRaces() {
  const env = environment();
  let pending = deferred();
  env.respond((endpoint, options) => options.method === "GET" ? pending.promise : { success: true });
  const first = env.API.getUserPets(), second = env.API.getUserPets();
  assert.equal(env.calls.length, 1, "Concurrent cacheable GETs must share one network request.");
  pending.resolve(pets);
  await Promise.all([first, second]);
  await Promise.all([env.API.addPet({ pet_name: "One" }), env.API.addPet({ pet_name: "Two" })]);
  assert.equal(env.calls.filter((call) => call.method === "POST").length, 2, "Mutations are never deduplicated.");
  pending = deferred();
  const obsolete = env.API.getUserPets();
  await env.API.archivePet(1);
  pending.resolve(pets);
  await assert.rejects(obsolete, /Customer data changed/);
  assert.equal(env.API.readCustomerCache("/pets?archived=0"), null, "A pre-mutation GET cannot resurrect an invalidated cache.");
  pending = deferred();
  const oldSession = env.API.getUserPets();
  env.API.setCustomerToken("2|other-synthetic-secret");
  pending.resolve(pets);
  await assert.rejects(oldSession, /Customer data changed/);
  assert.equal(env.API.readCustomerCache("/pets?archived=0"), null);
}

async function seed(env) {
  env.respond((endpoint) => endpoint === "/me" ? profile : endpoint.startsWith("/pets?") ? pets : { ...bookings, allowed: true });
  await Promise.all([
    env.API.getMe(), env.API.getUserPets(), env.API.getUserPets({ archived: 1 }),
    env.API.getBookingHistory(), env.API.getBookingHistory({ historyLimit: 3 }),
    env.API.getGroomingCapacity(), env.API.getCustomerNotifications(), env.API.getPreRegistrationAccess(),
  ]);
}

async function testInvalidation() {
  const env = environment();
  for (const mutate of [
    () => env.API.addPet({}), () => env.API.updatePet(1, {}),
    () => env.API.archivePet(1), () => env.API.unarchivePet(1),
    () => env.API.storeBooking({}),
  ]) {
    await seed(env);
    await mutate();
    assert.equal(env.API.readCustomerCache("/pets?archived=0"), null);
    assert.equal(env.API.readCustomerCache("/pets?archived=1"), null);
    assert.equal(env.API.readCustomerCache("/booking/history"), null);
    assert.ok(env.API.readCustomerCache("/me"), "Pet/booking changes must preserve the profile cache.");
  }
  for (const mutate of [() => env.API.cancelBooking(1), () => env.API.rescheduleBooking(1, "2026-10-06", 1), () => env.API.submitClinicPreRegistration({})]) {
    await seed(env);
    await mutate();
    for (const endpoint of ["/booking/history", "/booking/history?history_limit=3", "/booking/grooming-capacity", "/pre-registration/access", "/customer/notifications"]) {
      assert.equal(env.API.readCustomerCache(endpoint), null, `Mutation must invalidate ${endpoint}`);
    }
    assert.ok(env.API.readCustomerCache("/pets?archived=0"));
  }
  for (const mutate of [() => env.API.markCustomerNotificationRead(1), () => env.API.markAllCustomerNotificationsRead()]) {
    await seed(env);
    await mutate();
    assert.equal(env.API.readCustomerCache("/customer/notifications"), null);
    assert.ok(env.API.readCustomerCache("/pets?archived=0"));
  }
  await seed(env);
  const phoneCalls = env.calls.length;
  env.respond(() => ({ success: true, challenge_id: 'challenge', expires_in: 300, resend_after: 60 }));
  await env.API.sendPhoneVerification('09171234567');
  await env.API.resendPhoneVerification('challenge');
  assert.ok(env.API.readCustomerCache('/me'), 'Sending must not change account verification.');
  await env.API.verifyPhoneVerification('challenge', '123456');
  assert.equal(env.API.readCustomerCache('/me'), null);
  assert.equal(env.API.readCustomerCache('/pre-registration/access'), null);
  assert.ok(env.API.readCustomerCache('/pets?archived=0'), 'Verification must preserve unrelated cached data.');
  assert.equal(env.calls.length, phoneCalls + 3, 'Each explicit action makes exactly one request.');
  assert.deepEqual(env.calls.slice(-3).map(call => call.endpoint), ['/phone-verification/send', '/phone-verification/resend', '/phone-verification/verify']);
  env.respond((endpoint) => endpoint === '/me' ? profile : endpoint.startsWith('/pets?') ? pets : bookings);
  await seed(env);
  env.API.invalidateCustomerCache("profile");
  assert.equal(env.API.readCustomerCache("/me"), null);
  await seed(env);
  env.respond(() => response({ message: "Invalid input" }, 422));
  await assert.rejects(env.API.updatePet(1, {}), { status: 422 });
  assert.ok(env.API.readCustomerCache("/pets?archived=0"), "Failed mutations must not invalidate valid cached data.");
}

async function testSessionIsolationAndFreshness() {
  const env = environment();
  await seed(env);
  const persisted = env.sessionStorage.getItem("bethlehem.customer.data.v1");
  assert.ok(!persisted.includes("synthetic-customer-secret"));
  env.advance(6000);
  assert.equal(env.API.readCustomerCache("/pre-registration/access"), null, "Access decisions expire after five seconds.");
  env.respond(() => ({ bookings: [{ booking_id: 1, status: "in_progress" }], history: [] }));
  const count = env.calls.length;
  await env.API.getBookingHistory({ historyLimit: 3, force: true });
  await env.API.getBookingHistory({ historyLimit: 3, force: true });
  assert.equal(env.calls.length, count + 2, "Polling must bypass freshness even inside the TTL.");
  assert.equal(env.API.readCustomerCache("/booking/history?history_limit=3").data.bookings[0].status, "in_progress");
  await env.API.logout();
  assert.equal(env.sessionStorage.getItem("bethlehem.customer.data.v1"), null);
  assert.equal(env.API.readCustomerCache("/pets?archived=0"), null);
  env.API.setCustomerToken("2|next-customer-secret");
  await seed(env);
  env.API.setCustomerToken("3|replacement-customer-secret");
  assert.equal(env.API.readCustomerCache("/me"), null);
  await seed(env);
  env.localStorage.setItem("customer_token", "4|cross-tab-customer-secret");
  assert.equal(env.documentContext().API.readCustomerCache("/me"), null);
  await seed(env);
  env.respond(() => response({ message: "Expired" }, 401));
  await assert.rejects(env.API.getMe("customer", { force: true }), { status: 401 });
  assert.equal(env.API.hasAuthenticatedSession("customer"), false);
  assert.equal(env.sessionStorage.getItem("bethlehem.customer.data.v1"), null);
}

async function testExcludedDataAndStorageFailure() {
  const env = environment();
  env.respond(() => ({ bookings: [{ booking_id: 1, special_notes: "private unused note" }], history: [] }));
  const history = await env.API.getBookingHistory();
  assert.equal(history.bookings[0].special_notes, "private unused note", "The live API response must remain unchanged.");
  assert.ok(!env.sessionStorage.getItem("bethlehem.customer.data.v1").includes("private unused note"));
  env.respond(() => ({ records: [] }));
  await env.API.getPetMedicalRecords(1);
  await env.API.getPetMedicalRecords(1);
  assert.equal(env.calls.filter((call) => call.endpoint === "/pets/1/medical-records").length, 2);
  assert.equal(env.API.readCustomerCache("/pets/1/medical-records"), null);
  env.sessionStorage.setItem("bethlehem.customer.data.v1", "corrupt json");
  const api = env.documentContext().API;
  assert.equal(api.readCustomerCache("/me"), null);
  env.sessionStorage.setItem = () => { throw new Error("Storage full"); };
  env.respond(() => pets);
  await api.getUserPets();
  await api.getUserPets();
  assert.equal(env.calls.filter((call) => call.endpoint === "/pets?archived=0").length, 1, "Memory caching survives unavailable storage.");
}

function mountPage(env, page) {
  const browser = env.documentContext(page);
  const elements = new Map(), idle = [], ready = [], intervals = [];
  function element(id) {
    if (elements.has(id)) return elements.get(id);
    const hidden = ["petsContent", "historyContent", "settingsContent", "petModal", "petConfirmationModal"].includes(id);
    const classes = new Set(hidden ? ["hidden"] : []);
    const attributes = new Map(hidden && !["petModal", "petConfirmationModal"].includes(id) ? [["inert", ""], ["aria-hidden", "true"]] : []);
    const events = new Map();
    let markup = "";
    const node = {
      id, value: id === "historyServiceFilter" ? "all" : "", textContent: "", style: {}, dataset: {},
      get innerHTML() { return markup; },
      set innerHTML(value) {
        markup = value;
        if (id === "petsGrid") for (const [key, child] of elements) {
          if (/^(edit|archive|unarchive)-/.test(key)) { child.isConnected = false; elements.delete(key); }
        }
      },
      isConnected: true, tabIndex: 0, scrollTop: 0,
      get inert() { return attributes.has("inert"); },
      set inert(value) { if (value) attributes.set("inert", ""); else attributes.delete("inert"); },
      get ownerDocument() { return context.document; },
      get parentElement() {
        if (["petModal", "petConfirmationModal", "petsPageShell"].includes(id)) return element("body");
        if (["petConfirmationCard", "cancelPetConfirmation", "confirmPetAction"].includes(id)) return element("petConfirmationModal");
        if ((/^pet/.test(id) && !["petSearch", "petsGrid", "petsContent", "petsLoadingScreen", "petsLoadingMessage"].includes(id)) || id === "closePetModal") return element("petModal");
        return id === "body" ? null : element("petsPageShell");
      },
      contains(target) { return target === node || Boolean(target?.parentElement && node.contains(target.parentElement)); },
      closest(selector) { return selector === "[inert]" && node.inert ? node : node.parentElement?.closest(selector); },
      matches: () => Boolean(node.disabled),
      getClientRects: () => node.classList.contains("hidden") ? [] : [{}],
      focus() { if (!node.disabled && !node.closest("[inert]")) context.document.activeElement = node; },
      classList: {
        add: (...names) => names.forEach((name) => classes.add(name)),
        remove: (...names) => names.forEach((name) => classes.delete(name)),
        contains: (name) => classes.has(name),
        toggle: (name, force) => force ? classes.add(name) : classes.delete(name),
      },
      setAttribute: (name, value) => attributes.set(name, value),
      removeAttribute: (name) => attributes.delete(name),
      hasAttribute: (name) => attributes.has(name),
      querySelectorAll(selector) {
        if (id === "petsGrid") {
          const attribute = selector.match(/^\[data-(edit|archive|unarchive)\]$/)?.[1];
          return attribute ? [...node.innerHTML.matchAll(new RegExp(`data-${attribute}="(\\d+)"`, "g"))].map((match) => {
            const button = element(`${attribute}-${match[1]}`);
            button.dataset[attribute] = match[1];
            return button;
          }) : [];
        }
        if (id === "petModal") return ["closePetModal", "petName", "petFormSubmit"].map(element);
        if (id === "petConfirmationModal") return ["cancelPetConfirmation", "confirmPetAction"].map(element);
        return [];
      },
      querySelector: () => null,
      addEventListener(name, callback) { events.set(name, [...(events.get(name) || []), callback]); },
      async dispatch(name, details = {}) {
        const event = { target: node, currentTarget: node, preventDefault() { this.defaultPrevented = true; }, ...details };
        await Promise.all((events.get(name) || []).map(callback => callback(event)));
        for (const callback of documentEvents.get(name) || []) callback(event);
        return event;
      },
    };
    elements.set(id, node);
    return node;
  }
  const context = browser.context;
  const documentEvents = new Map();
  context.document = {
    getElementById: element, querySelectorAll: () => [], body: element("body"), visibilityState: "visible",
    addEventListener(name, callback) {
      if (name === "DOMContentLoaded") ready.push(callback);
      else documentEvents.set(name, [...(documentEvents.get(name) || []), callback]);
    },
  };
  context.HTMLElement = Object;
  context.window.location.search = "";
  context.window.matchMedia = () => ({ matches: false, addEventListener() {} });
  context.window.requestIdleCallback = (callback) => idle.push(callback);
  context.window.requestAnimationFrame = (callback) => callback();
  context.window.setInterval = (callback) => intervals.push(callback);
  context.window.setTimeout = (callback) => idle.push(callback);
  context.setInterval = context.window.setInterval;
  context.window.ClientNotificationUI = { ensureDropdownControls: () => () => "all", setUnreadCount() {}, render() {} };
  const combobox = ({ input }) => ({
    setValue(value) { input.value = value; }, reset() { input.value = ""; }, update() {}, setOptions() {},
    setDisabled(value) { input.disabled = value; }, getValue: () => input.value, getValidationMessage: () => "", showValidation() {},
  });
  Object.assign(context, {
    ...healthRecordTools,
    ...petWeightTools, normalizePetSize, syncWeightSizeFields: petWeightTools?.syncPetWeightAndSize,
    createBreedCombobox: combobox, createBreedCoatCombobox: combobox, createFixedOptionCombobox: combobox,
    breedCoatCatalogueReady: Promise.resolve(), URLSearchParams,
  });
  let script;
  if (page === "my-pets.html") {
    script = fs.readFileSync(path.join(root, "scripts/components/my-pets.js"), "utf8").replace(/^import[\s\S]*?;\r?\n/gm, "");
  } else if (page === "settings.html") {
    script = fs.readFileSync(path.join(root, "scripts/components/client-settings.js"), "utf8");
  } else if (page === "grooming-history.html") {
    const html = fs.readFileSync(path.join(root, "pages/client/grooming-history.html"), "utf8");
    script = [...html.matchAll(/<script(?:\s+type="module")?>([\s\S]*?)<\/script>/g)]
      .map((match) => match[1].replace(/^\s*import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, "")).join("\n");
  } else {
    const dashboard = fs.readFileSync(path.join(root, "scripts/components/client-dashboard.js"), "utf8");
    script = dashboard.slice(dashboard.indexOf("(function () {", dashboard.indexOf("Appointments, Grooming Tracker & History")));
  }
  vm.runInContext(script, context);
  ready.forEach((callback) => callback());
  return { element, idle, intervals, API: browser.API, document: context.document };
}

async function testActualPageLoaders() {
  const flush = () => new Promise((resolve) => setImmediate(resolve));
  const cold = environment(), pending = deferred();
  cold.respond(() => pending.promise);
  const first = mountPage(cold, "my-pets.html");
  assert.equal(first.element("petsContent").hasAttribute("inert"), true);
  assert.deepEqual(cold.calls.map((call) => call.endpoint), ["/pets?archived=0"], "My Pets must request only the visible collection before idle work.");
  pending.resolve(pets);
  await flush();
  assert.match(first.element("petsGrid").innerHTML, /Mochi/);
  assert.equal(first.element("petsLoadingScreen").classList.contains("hidden"), true);

  const env = environment();
  await seed(env);
  const count = env.calls.length;
  const cachedPets = mountPage(env, "my-pets.html");
  assert.equal(cachedPets.element("petsContent").hasAttribute("inert"), false);
  assert.match(cachedPets.element("petsGrid").innerHTML, /Mochi/);
  assert.equal(env.calls.length, count, "Actual My Pets loader must reuse the shared active list.");
  const settings = mountPage(env, "settings.html");
  assert.equal(settings.element("settingsContent").hasAttribute("inert"), false);
  assert.equal(settings.element("settingsFirstName").value, "Test");
  assert.equal(env.calls.length, count);
  const clinicPending = deferred();
  env.respond((endpoint) => endpoint === "/clinic/history" ? clinicPending.promise : bookings);
  const history = mountPage(env, "grooming-history.html");
  assert.equal(history.element("historyContent").hasAttribute("inert"), false);
  assert.match(history.element("historyList").innerHTML, /No history found/);
  clinicPending.resolve({ medical_records: [{ id: 9, appointment_reference: "CL-CACHE-9", appointment_date: "2026-10-16", pet: { pet_name: "Mochi" } }] });
  await flush();
  assert.match(history.element("historyList").innerHTML, /CL-CACHE-9/);
  assert.equal(env.calls.filter(call => call.endpoint === "/clinic/history").length, 1);

  env.advance(61000);
  const update = deferred();
  env.respond(() => update.promise);
  const stalePets = mountPage(env, "my-pets.html");
  assert.equal(stalePets.element("petsContent").hasAttribute("inert"), false);
  assert.match(stalePets.element("petsGrid").innerHTML, /Mochi/);
  update.resolve({ pets: [{ pet_id: 1, pet_name: "Fresh Mochi", species: "Dog" }] });
  await flush();
  assert.match(stalePets.element("petsGrid").innerHTML, /Fresh Mochi/);
  env.advance(61000);
  env.respond(() => { throw new Error("offline"); });
  const failedPets = mountPage(env, "my-pets.html");
  const failedSettings = mountPage(env, "settings.html");
  await flush();
  assert.match(failedPets.element("petsGrid").innerHTML, /Fresh Mochi/);
  assert.equal(failedSettings.element("settingsContent").hasAttribute("inert"), false);

  const dashboardEnv = environment();
  await seed(dashboardEnv);
  const dashboard = mountPage(dashboardEnv, "dashboard.html");
  assert.match(dashboard.element("myPetsPreview").innerHTML, /Mochi/);
  await flush();
  const beforePoll = dashboardEnv.calls.length;
  dashboard.intervals[0]();
  await flush();
  assert.equal(dashboardEnv.calls.length, beforePoll + 1, "The real dashboard timer must still fetch fresh booking status.");
  const previousMarkup = dashboard.element("groomingTracker").innerHTML;
  dashboardEnv.respond(() => response({ message: "Server unavailable" }, 500));
  dashboard.intervals[0]();
  await flush();
  assert.equal(dashboard.element("groomingTracker").innerHTML, previousMarkup);
  dashboardEnv.respond(() => ({
    bookings: [{ booking_id: 9, booking_reference: "TEST-9", status: "waiting_to_arrive", booking_date: "2026-10-06", pets: [{ pet_id: 1, pet_name: "Updated appointment" }] }], history: [],
  }));
  dashboard.intervals[0]();
  await flush();
  assert.match(dashboard.element("appointmentsList").innerHTML, /Updated appointment/);
}

async function testMyPetsStaticModalsAndWorkflows() {
  const flush = () => new Promise(resolve => setImmediate(resolve));
  const env = environment();
  const activePet = { ...pets.pets[0], breed: "Beagle", fur_type: "Smooth Short Coat", weight: 5, size: "small", clinic_verified_fields: ["weight", "size"] };
  const archivedPet = { ...activePet, pet_id: 2, pet_name: "Archived pet", is_archived: true };
  const petResponse = endpoint => endpoint === "/me" ? profile : endpoint.startsWith("/pets?")
    ? { pets: endpoint.includes("archived=1") ? [archivedPet] : [activePet] } : { success: true };
  env.respond(petResponse);
  const ui = mountPage(env, "my-pets.html");
  await flush();
  const el = ui.element;
  const form = el("petModal"), confirmation = el("petConfirmationModal");
  const open = modal => !modal.classList.contains("hidden");
  const writes = () => env.calls.filter(call => call.method !== "GET");

  async function accidentalDismissal(modal) {
    const callsBefore = env.calls.length;
    const pointer = await modal.dispatch("pointerdown");
    await modal.dispatch("click");
    await modal.dispatch("keydown", { key: "Escape" });
    assert.equal(open(modal), true, `${modal.id} must survive outside clicks and Escape`);
    assert.equal(env.calls.length, callsBefore, "Accidental interactions must not fetch or mutate data");
    assert.equal(pointer.defaultPrevented, true, "Backdrop pointerdown must preserve focus and scroll");
  }

  el("addPetBtn").focus();
  await el("addPetBtn").dispatch("click");
  el("petName").value = "New pet";
  el("petWeight").value = "-1";
  await el("petForm").dispatch("submit");
  const validation = el("petFormError").textContent;
  assert.match(validation, /greater than 0/);
  form.scrollTop = 120;
  await accidentalDismissal(form);
  assert.equal(el("petName").value, "New pet");
  assert.equal(el("petSpecies").value, "Dog");
  assert.equal(el("petFormError").textContent, validation);
  assert.equal(form.scrollTop, 120);
  assert.equal(el("petsPageShell").inert, true);
  el("petSearch").focus();
  assert.equal(ui.document.activeElement, el("closePetModal"), "Background controls cannot take focus");
  await form.dispatch("keydown", { key: "Tab", shiftKey: true });
  assert.equal(ui.document.activeElement, el("petFormSubmit"));
  await form.dispatch("keydown", { key: "Tab" });
  assert.equal(ui.document.activeElement, el("closePetModal"));
  await el("closePetModal").dispatch("click");
  assert.equal(open(form), false);
  assert.equal(el("petsPageShell").inert, false);
  assert.equal(ui.document.activeElement, el("addPetBtn"));

  await el("addPetBtn").dispatch("click");
  el("petName").value = "New pet";
  el("petWeight").value = "5";
  el("petFormSubmit").focus();
  const pendingSave = deferred();
  env.respond((endpoint, options) => endpoint === "/pets" && options.method === "POST" ? pendingSave.promise : petResponse(endpoint));
  const creating = el("petForm").dispatch("submit");
  await flush();
  assert.equal(form.contains(ui.document.activeElement) && !ui.document.activeElement.disabled, true,
    "Saving must keep focus on an enabled control inside the form");
  pendingSave.resolve({ success: true });
  await creating;
  env.respond(petResponse);
  assert.equal(open(form), false, "Successful Add Pet still closes the form");
  assert.equal(writes().at(-1).endpoint, "/pets");

  el("edit-1").focus();
  await el("edit-1").dispatch("click");
  el("petName").value = "Edited pet";
  el("petWeight").value = "6";
  await accidentalDismissal(form);
  el("petFormSubmit").focus();
  let saving = el("petForm").dispatch("submit");
  await flush();
  assert.equal(open(confirmation), true, "Verified edits require the existing confirmation");
  const beforeCancel = writes().length;
  await accidentalDismissal(confirmation);
  assert.equal(form.inert, true, "Only the topmost modal is interactive");
  el("petName").focus();
  assert.equal(confirmation.contains(ui.document.activeElement), true);
  el("confirmPetAction").focus();
  await confirmation.dispatch("keydown", { key: "Tab" });
  assert.equal(ui.document.activeElement, el("cancelPetConfirmation"));
  await confirmation.dispatch("keydown", { key: "Tab", shiftKey: true });
  assert.equal(ui.document.activeElement, el("confirmPetAction"));
  await el("cancelPetConfirmation").dispatch("click");
  await saving;
  assert.equal(open(confirmation), false);
  assert.equal(open(form), true);
  assert.equal(form.inert, false);
  assert.equal(el("petsPageShell").inert, true);
  assert.equal(ui.document.activeElement, el("petFormSubmit"));
  assert.equal(el("petName").value, "Edited pet");
  assert.equal(el("petWeight").value, "6");
  assert.equal(writes().length, beforeCancel, "Cancel must resolve without saving");
  saving = el("petForm").dispatch("submit");
  await flush();
  await el("confirmPetAction").dispatch("click");
  await saving;
  assert.equal(open(form), false, "Save Changes still completes the update");
  assert.equal(writes().at(-1).endpoint, "/pets/1");

  for (const [button, endpoint] of [["archive-1", "/pets/1/archive"], ["unarchive-2", "/pets/2/unarchive"]]) {
    if (button === "unarchive-2") { await el("filterArchivedBtn").dispatch("click"); await flush(); }
    el(button).focus();
    let action = el(button).dispatch("click");
    await flush();
    await accidentalDismissal(confirmation);
    const before = writes().length;
    await el("cancelPetConfirmation").dispatch("click");
    await action;
    assert.equal(writes().length, before);
    assert.equal(ui.document.activeElement, el(button));
    action = el(button).dispatch("click");
    await flush();
    await el("confirmPetAction").dispatch("click");
    await action;
    assert.equal(writes().at(-1).endpoint, endpoint);
    assert.equal(open(confirmation), false);
    assert.equal(el("petsPageShell").inert, false);
  }
  el("notifDropdown").style.display = "flex";
  await el("petSearch").dispatch("click");
  assert.equal(el("notifDropdown").style.display, "none", "Notification outside-click dismissal remains active");
}

async function main() {
  healthRecordTools = await import('../scripts/components/customer-health-records.js');
  petWeightTools = await import('../scripts/components/pet-weight-size.js');
  ({ normalizePetSize } = await import('../scripts/services/booking-draft-service.js'));
  for (const test of [testCrossDocumentFlowAndColdMiss, testRevalidationAndFailure, testDeduplicationAndRaces, testInvalidation, testSessionIsolationAndFreshness, testExcludedDataAndStorageFailure, testActualPageLoaders, testMyPetsStaticModalsAndWorkflows]) {
    let timeout;
    try {
      await Promise.race([test(), new Promise((_, reject) => {
        timeout = setTimeout(() => reject(new Error(`${test.name} did not settle`)), 5000);
      })]);
    } finally {
      clearTimeout(timeout);
    }
    console.log(`${test.name} passed`);
  }
}

// Optional local acceptance fixture: real HTML documents and real HTTP requests,
// with synthetic customer responses. Never connects to the Laravel database.
function serveBrowserFixture() {
  const http = require("node:http");
  const port = Number(process.argv.find((arg) => arg.startsWith("--port="))?.split("=")[1] || 8765);
  const baselinePath = process.argv.find((arg) => arg.startsWith("--baseline="))?.slice("--baseline=".length);
  const baseline = baselinePath ? JSON.parse(fs.readFileSync(baselinePath, "utf8")) : {};
  const requests = [];
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, `http://127.0.0.1:${port}`);
    if (url.pathname === "/__requests") {
      res.setHeader("Content-Type", "application/json");
      return res.end(JSON.stringify(requests));
    }
    if (url.pathname.startsWith("/api/")) {
      requests.push({ endpoint: url.pathname + url.search, at: Date.now() });
      let data;
      switch (url.pathname) {
        case "/api/me": data = profile; break;
        case "/api/pets": data = url.searchParams.get("archived") === "1" ? { pets: [] } : pets; break;
        case "/api/booking/history": data = bookings; break;
        case "/api/clinic/history": data = { medical_records: [] }; break;
        case "/api/customer/notifications": data = { notifications: [], unread_count: 0 }; break;
        case "/api/pre-registration/access": data = { allowed: true }; break;
        case "/api/booking/grooming-capacity": data = { capacity: { max: 20, used: 0, remaining: 20 }, queue: { active: 0 } }; break;
        default: res.statusCode = 404; data = { message: "Unknown fixture endpoint" };
      }
      res.setHeader("Content-Type", "application/json");
      // Simulated latency belongs only to this test server, never production.
      return setTimeout(() => res.end(JSON.stringify(data)), 300);
    }
    const relative = decodeURIComponent(url.pathname).replace(/^\//, "");
    const file = path.resolve(root, relative);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      res.statusCode = 404;
      return res.end("Missing fixture file");
    }
    const types = { ".html": "text/html", ".js": "application/javascript", ".css": "text/css", ".json": "application/json", ".svg": "image/svg+xml", ".png": "image/png" };
    const extension = path.extname(file);
    res.setHeader("Content-Type", types[extension] || "application/octet-stream");
    res.setHeader("Cache-Control", extension === ".html" ? "no-store" : "public, max-age=3600");
    let content = baseline[relative] ?? fs.readFileSync(file);
    if (extension === ".html") {
      content = String(content).replace(/<head>/i, `<head><script>
        window.BETHLEHEM_API_BASE_URL = location.origin + '/api';
        if (!localStorage.getItem('customer_token')) {
          localStorage.setItem('customer_token', '99001|synthetic-browser-fixture');
          localStorage.setItem('user_role', 'customer');
        }
        document.addEventListener('DOMContentLoaded', () => {
          const primary = document.querySelector('#petsContent, #historyContent, #settingsContent');
          if (!primary) return;
          requestAnimationFrame(() => console.info('Fixture primary at first frame: ' + (!primary.classList.contains('hidden') && !primary.hasAttribute('inert') ? 'visible' : 'loading')));
        });
      </script>`);
    }
    res.end(content);
  });
  server.listen(port, "127.0.0.1", () => console.log(`Customer acceptance fixture: http://127.0.0.1:${port}/pages/client/dashboard.html`));
}

if (require.main === module) {
  if (process.argv.includes("--serve")) serveBrowserFixture();
  else main().catch((error) => { console.error(error); process.exitCode = 1; });
}
module.exports = { environment, deferred, response, pets, profile, bookings };
