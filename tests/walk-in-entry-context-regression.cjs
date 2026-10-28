const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const read = (file) => fs.readFileSync(path.join(__dirname, "..", file), "utf8");
const ownerSource = read("scripts/utils/human-name.js") + "\n" + read("scripts/utils/account-email.js") + "\n" + read("scripts/utils/philippine-mobile.js") + "\n" + read("scripts/components/walk-in-owner-step.js");
const ownerPage = read("pages/admin/walk-in-booking.html");
const clinicSource = read("scripts/components/admin-clinic.js");
const clinicPage = read("pages/admin/clinic.html");
const continuationKeys = [
  "walkInPetStep", "walkInConsentStep", "walkInReviewStep", "walkInBookingConfirmation",
  "walkInClinicComplaint", "walkInClinicConfirmation", "clinicPreselectedPetId",
];

function element() {
  const classes = new Set();
  const attributes = new Map();
  return {
    value: "", textContent: "", innerHTML: "", disabled: false,
    events: {}, addEventListener(name, callback) { this.events[name] = callback; }, focus() {},
    setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    append() {}, insertAdjacentElement() {},
    setAttribute(key, value) { attributes.set(key, value); },
    getAttribute(key) { return attributes.get(key); },
    classList: {
      add(key) { classes.add(key); }, remove(key) { classes.delete(key); },
      contains(key) { return classes.has(key); },
      toggle(key, enabled) { enabled ? classes.add(key) : classes.delete(key); },
    },
  };
}

function ownerFlow(type, { fresh = true, draft = {}, verification = Promise.resolve(true) } = {}) {
  const ui = new Map();
  const get = (id) => {
    if (!ui.has(id)) ui.set(id, element());
    return ui.get(id);
  };
  const storage = new Map(continuationKeys.map((key) => [key, "stale"]));
  storage.set("unrelated", "keep");
  storage.set("walkInOwnerStep", JSON.stringify({ appointmentType: type === "clinic" ? "grooming" : "clinic", ...draft }));
  let ready;
  const window = {
    location: { search: `?flow=${type}${fresh ? "&new=1" : ""}`, href: "" },
    history: { replaceState(a, b, url) { window.location.search = new URL(url, "http://localhost").search; } },
  };
  const context = vm.createContext({
    URLSearchParams, window,
    document: {
      getElementById: get, createElement: element,
      addEventListener(event, callback) { if (event === "DOMContentLoaded") ready = callback; },
    },
    API: { waitForSessionVerification: () => verification, validateWalkInNewOwner: async () => ({}) },
    sessionStorage: {
      getItem: (key) => storage.get(key) || null,
      setItem: (key, value) => storage.set(key, value), removeItem: (key) => storage.delete(key),
    },
  });
  vm.runInContext(ownerSource, context);
  const initialization = ready();
  return { context, storage, get, window, initialization };
}

async function checkStepNavigation(file, markupFunction, topId, bottomId, previousPage) {
  const nodes = new Map();
  const get = id => {
    if (!nodes.has(id)) nodes.set(id, element());
    return nodes.get(id);
  };
  const saved = new Map([["walkInOwnerStep", '{"appointmentType":"clinic"}'], ["walkInPetStep", '{"pets":[{"petName":"Mochi"}]}']]);
  const initialDraft = [...saved];
  const context = vm.createContext({
    document: { getElementById: get, addEventListener() {}, body: {}, querySelector: () => ({}) },
    window: { location: { href: "" } },
    sessionStorage: { getItem: key => saved.get(key), setItem: (key, value) => saved.set(key, value), removeItem: key => saved.delete(key) },
    API: { waitForSessionVerification: async () => true },
    mountClinicVisitReasonFields: () => ({}), getClinicVisitSummaryMarkup: () => "", formatClinicVisitReason: () => "",
  });
  const source = read(`scripts/components/${file}.js`).replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, "").replace(/^export /gm, "");
  vm.runInContext(source, context);
  const markup = vm.runInContext(`${markupFunction}()`, context);
  if (file === "walk-in-clinic-complaint-step") await context.renderWalkInClinicComplaintStep();
  else vm.runInContext("refreshElements(); bindEvents();", context);

  const top = markup.match(new RegExp(`<([a-z]+)\\b([^>]*\\bid="${topId}"[^>]*)>([\\s\\S]*?)<\\/\\1>`));
  assert.ok(top, `${file}: top navigation is rendered`);
  assert.equal(top[1], "a", `${file}: Home uses native anchor navigation`);
  assert.match(top[2], /href="\.\/dashboard\.html"/, file);
  assert.equal(top[3].replace(/<[^>]+>/g, "").replace(/&larr;/g, "←").replace(/\s+/g, " ").trim(), "← Back to Home", file);
  assert.equal(get(topId).events.click, undefined, `${file}: no handler overrides Home`);
  assert.deepEqual([...saved], initialDraft, `${file}: navigation setup preserves drafts`);

  get(bottomId).events.click();
  assert.equal(context.window.location.href, previousPage, `${file}: bottom Back retains its destination`);
  if (file !== "walk-in-services-step" && file !== "walk-in-clinic-complaint-step") {
    let callbacks = 0;
    context.onBack = () => { callbacks++; };
    vm.runInContext("state.onBack = onBack;", context);
    context.window.location.href = "";
    get(bottomId).events.click();
    assert.equal(callbacks, 1, `${file}: bottom Back retains the supplied callback`);
    assert.equal(context.window.location.href, "");
  }
  assert.deepEqual([...saved], initialDraft, `${file}: bottom Back preserves drafts`);
}

(async () => {
  for (const step of [
    ["walk-in-services-step", "getServicesMainMarkup", "walkInServicesBackTopBtn", "walkInServicesBackBtn", "./walk-in-pet-details.html"],
    ["walk-in-review-step", "getReviewMainMarkup", "walkInReviewBackTopBtn", "walkInReviewBackBtn", "./walk-in-grooming-services.html"],
    ["walk-in-consent-step", "getConsentMainMarkup", "walkInConsentBackTopBtn", "walkInConsentBackBtn", "./walk-in-booking-review.html"],
    ["walk-in-clinic-complaint-step", "getMarkup", "clinicComplaintBackTopBtn", "clinicComplaintBackBtn", "./walk-in-pet-details.html"],
    ["walk-in-clinic-consent-step", "getMarkup", "clinicConsentBackTopBtn", "clinicConsentBackBtn", "./walk-in-pet-details.html"],
  ]) await checkStepNavigation(...step);
  let authorize;
  const verification = new Promise(resolve => { authorize = resolve; });
  const delayed = ownerFlow("clinic", {verification, draft: {firstName: "Private draft"}});
  assert.equal(JSON.parse(delayed.storage.get("walkInOwnerStep")).firstName, "Private draft", "Pending verification cannot reset or render the retained draft");
  assert.equal(delayed.storage.get("walkInPetStep"), "stale");
  authorize(true);
  await delayed.initialization;
  assert.equal(JSON.parse(delayed.storage.get("walkInOwnerStep")).appointmentType, "clinic");
  const denied = ownerFlow("grooming", {verification: Promise.resolve(false), draft: {firstName: "Private draft"}});
  await denied.initialization;
  assert.equal(JSON.parse(denied.storage.get("walkInOwnerStep")).firstName, "Private draft", "Failed authorization cannot initialize a new flow");
  assert.doesNotMatch(ownerPage, /Walk-in Type|typeGrooming|typeClinic|name="appointmentType"/);
  assert.doesNotMatch(ownerSource, /syncExistingCustomerSearchVisibility|applyRequestedAppointmentType/);
  assert.match(read("scripts/components/admin-dashboard.js"), /walkInBookingUrl: "\.\/walk-in-booking.html\?flow=grooming&new=1"/);

  for (const type of ["grooming", "clinic"]) {
    const flow = ownerFlow(type);
    await flow.initialization;
    const phoneInput = flow.get('ownerPhoneNumber'), phoneError = flow.get('ownerPhoneNumberError');
    for (const value of ['5', '5677567576']) {
      phoneInput.value = value; phoneInput.events.input();
      assert.equal(phoneError.textContent, '', 'Incomplete mobile input stays quiet.');
    }
    phoneInput.value = '56775675767'; phoneInput.events.input();
    assert.equal(phoneError.textContent, 'Enter a valid Philippine mobile number.');
    phoneInput.value = '5677567576'; phoneInput.events.input();
    assert.equal(phoneError.textContent, '');
    phoneInput.events.blur();
    assert.equal(phoneError.textContent, 'Enter an 11-digit mobile number.');
    phoneInput.value = ''; phoneInput.events.input();
    assert.equal(phoneError.textContent, '');
    phoneInput.events.blur(); assert.equal(phoneError.textContent, 'Enter a mobile number.');
    for (const value of ['09181234567', '08971234567', '09221234567', '09561234567']) {
      phoneInput.value = value; phoneInput.events.input(); assert.equal(phoneError.textContent, '');
    }
    phoneInput.value = '0897abc12345678'; phoneInput.selectionStart = 7; phoneInput.selectionEnd = 7;
    phoneInput.events.input(); assert.equal(phoneInput.value, '0897 123 4567'); assert.equal(phoneInput.selectionStart, 4);
    assert.equal(flow.get("walkInPageTitle").textContent, type === "clinic" ? "Clinic walk-in" : "Grooming walk-in");
    assert.equal(flow.get("walkInOwnerBackLink").getAttribute("href"), type === "clinic" ? "./clinic.html" : "./appointments.html");
    assert.equal(JSON.parse(flow.storage.get("walkInOwnerStep")).appointmentType, type);
    continuationKeys.forEach((key) => assert.equal(flow.storage.has(key), false, `${type}: clears ${key}`));
    assert.equal(flow.storage.get("unrelated"), "keep");
    assert.equal(flow.window.location.search, `?flow=${type}`, "Reload does not restart the draft");

    // Even an old draft written after initialization cannot change this entry's type.
    flow.storage.set("walkInOwnerStep", JSON.stringify({ appointmentType: "other" }));
    assert.equal(vm.runInContext("getAppointmentType()", flow.context), type);
    await vm.runInContext("handleSubmit({ preventDefault() {} })", flow.context);
    assert.equal(flow.window.location.href, "", "Invalid new owner stays on the owner page");
    assert.equal(flow.get("ownerFirstNameError").textContent, "First name is required.");

    const values = { firstName: "Test", lastName: "Owner", middleInitial: "", phone: "09171234567", email: "" };
    flow.context.values = values;
    for (const [id, value] of Object.entries({ ownerFirstName: values.firstName, ownerLastName: values.lastName, ownerPhoneNumber: values.phone })) flow.get(id).value = value;
    phoneInput.value = '9171234567'; phoneInput.events.input();
    assert.equal(flow.get('walkInOwnerNextBtn').disabled, true, 'New local input needs all 11 digits before continuing.');
    phoneInput.value = values.phone; phoneInput.events.input();
    flow.context.API.validateWalkInNewOwner = async () => { throw { code: "similar_customer_name", data: { similarCustomers: [{ fullName: "Test Owner" }] } }; };
    await vm.runInContext("validateNewOwnerAndContinue(values)", flow.context);
    assert.equal(flow.window.location.href, "", "Similar names require acknowledgement");
    assert.equal(flow.get("similarOwnerWarning").classList.contains("hidden"), false);
    flow.context.API.validateWalkInNewOwner = async (payload) => { assert.equal(payload.confirm_similar_name, true); };
    await vm.runInContext("validateNewOwnerAndContinue(values, true)", flow.context);
    let owner = JSON.parse(flow.storage.get("walkInOwnerStep"));
    assert.equal(owner.appointmentType, type);
    assert.equal(owner.confirmSimilarName, true);
    assert.equal(owner.ownerRecordType, "new");
    assert.equal(flow.window.location.href, "./walk-in-pet-details.html");

    for (const recordType of ["registered", "unregistered"]) {
      flow.context.customer = { ...values, phone: '09031234567', id: 7, recordType, pets: [{ petId: 4 }] };
      vm.runInContext("selectExistingCustomer(customer)", flow.context);
      owner = JSON.parse(flow.storage.get("walkInOwnerStep"));
      assert.equal(owner.appointmentType, type);
      assert.equal(owner.ownerRecordType, recordType);
      assert.equal(owner[recordType === "registered" ? "customerUserId" : "unregisteredCustomerId"], 7);
      assert.equal(owner.existingPets[0].petId, 4);
      assert.equal(owner.phone, '09031234567', 'Selecting a historical contact does not rewrite or newly validate it.');
    }

    const resume = ownerFlow(type, { fresh: false, draft: { appointmentType: type, firstName: "Saved" } });
    await resume.initialization;
    assert.equal(JSON.parse(resume.storage.get("walkInOwnerStep")).firstName, "Saved");
    assert.equal(resume.storage.get("walkInPetStep"), "stale", "Returning from pet step preserves draft");

    const missing = ownerFlow(type);
    await missing.initialization;
    missing.context.customer = { ...values, id: 17, recordType: 'registered', phone: null, pets: [{ petId: 14 }] };
    missing.context.API.validateWalkInNewOwner = () => { throw Error('Selected customers are not new owners'); };
    vm.runInContext('selectExistingCustomer(customer)', missing.context);
    assert.equal(missing.window.location.href, '', 'Collect a missing contact in the existing form');
    assert.equal(missing.get('ownerFirstName').value, 'Test');
    assert.equal(missing.get('walkInOwnerNextBtn').disabled, true);
    missing.get('ownerPhoneNumber').value = '0917 999 8888';
    await vm.runInContext('handleSubmit({ preventDefault() {} })', missing.context);
    const completed = JSON.parse(missing.storage.get('walkInOwnerStep'));
    assert.equal(completed.customerUserId, 17);
    assert.equal(completed.phone, '09179998888');
    assert.equal(completed.existingPets[0].petId, 14);
    assert.equal(missing.window.location.href, './walk-in-pet-details.html');
  }

  const clinic = vm.createContext({ document: { addEventListener() {} }, window: { location: {} } });
  vm.runInContext(clinicSource, clinic);
  const cases = clinic.adminClinicPage();
  cases.$store = { clinicAccess: { permissions: { intake: false, customers: true } } };
  cases.startWalkIn();
  assert.equal(clinic.window.location.href, undefined);
  cases.$store.clinicAccess.permissions = { intake: true, customers: false };
  cases.startWalkIn();
  assert.equal(clinic.window.location.href, "./walk-in-booking.html?flow=clinic&new=1");
  assert.match(clinicPage, /x-show="permissions.intake" @click="startWalkIn\(\)"/);
  assert.doesNotMatch(clinicSource + clinicPage, /addCustomer|openAddCustomer|closeAddCustomer|saveCustomerRecord|createUnregisteredCustomer|adminAddCustomerPet/);
  assert.match(read("routes/api.php"), /post\('\/admin\/clinic-walk-in',[^\n]+middleware\('clinic:intake'\)/);

  // Exercise the unchanged dedicated Customers creation path with an API stub.
  const customerElements = new Map();
  const customerContext = vm.createContext({ window: {}, document: {
    getElementById(id) { if (!customerElements.has(id)) customerElements.set(id, element()); return customerElements.get(id); }, addEventListener() {},
  } });
  vm.runInContext(read("scripts/utils/human-name.js"), customerContext);
  vm.runInContext(read("scripts/utils/account-email.js"), customerContext);
  vm.runInContext(read("scripts/utils/philippine-mobile.js"), customerContext);
  const customerSource = read("scripts/components/admin-customers.js");
  vm.runInContext(customerSource.slice(customerSource.indexOf("function adminCustomers()")), customerContext);
  const customers = customerContext.adminCustomers();
  customers.refreshIcons = () => {};
  customers.setCustomerDetailScrollLock = () => {};
  let refreshed = false;
  customers.loadCustomers = async () => { refreshed = true; };
  let payload;
  customerContext.API = { createUnregisteredCustomer: async (value) => { payload = value; } };
  customers.$nextTick = callback => callback();
  customers.openAddCustomerModal();
  const phoneInput = customerContext.document.getElementById('addCustomerPhone');
  Object.assign(customers.addCustomerModal.form, { first_name: 'Test', last_name: 'Customer' });
  for (const value of ['9171234567', '8971234567']) {
    phoneInput.value = value; phoneInput.events.input();
    await customers.submitUnregisteredCustomer();
    assert.equal(payload, undefined, 'Server-side national normalization must not bypass the 11-digit local input requirement.');
    assert.equal(customers.addCustomerModal.errors.phone[0], 'Enter an 11-digit mobile number.');
  }
  for (const value of ['56775675767', '09031234567']) {
    phoneInput.value = value; phoneInput.events.input();
    assert.equal(customers.addCustomerModal.errors.phone[0], 'Enter a valid Philippine mobile number.');
    await customers.submitUnregisteredCustomer(); assert.equal(payload, undefined);
  }
  phoneInput.value = '0903123456'; phoneInput.events.input();
  assert.equal(customers.addCustomerModal.errors.phone, undefined);
  phoneInput.value = ''; phoneInput.events.input();
  assert.equal(customers.addCustomerModal.errors.phone, undefined);
  phoneInput.events.blur(); assert.equal(customers.addCustomerModal.errors.phone[0], 'Enter a mobile number.');
  for (const value of ['09181234567', '08971234567', '09221234567']) {
    phoneInput.value = value; phoneInput.events.input();
    assert.equal(customers.addCustomerModal.form.phone, value.slice(0, 4) + ' ' + value.slice(4, 7) + ' ' + value.slice(7));
    assert.equal(customers.addCustomerModal.errors.phone, undefined);
  }
  Object.assign(customers.addCustomerModal.form, { first_name: " Test ", last_name: " Customer ", phone: "0917 123 4567" });
  await customers.submitUnregisteredCustomer();
  assert.equal(payload.first_name, "Test");
  assert.equal(payload.last_name, "Customer");
  assert.equal(payload.phone, '09171234567', 'Customer creation sends the canonical contact, never display spaces.');
  assert.equal(customers.addCustomerModal.open, false);
  assert.equal(customers.statusFilter, "unregistered");
  assert.equal(refreshed, true);
  assert.match(read("scripts/api.js"), /createUnregisteredCustomer/);
  assert.match(read("pages/admin/clients.html"), /@click="openAddCustomerModal\(\)"/);
  console.log("Walk-in Home links, bottom Back routes and callbacks, entry context, drafts, owner validation, intake permissions and dedicated Customers creation passed.");
})().catch((error) => { console.error(error); process.exitCode = 1; });
