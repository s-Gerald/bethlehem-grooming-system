const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const path = require("node:path");

const read = (file) => fs.readFileSync(path.join(__dirname, "..", file), "utf8");
const consent = vm.createContext({ getPetWeightSubmission: (pet) => ({ weight: pet.weight }), document: { addEventListener() {} } });
vm.runInContext(read("scripts/components/walk-in-consent-step.js").replace(/import[\s\S]*?from\s+"[^"]+";/g, "").replace(/export /g, ""), consent);
const reviewSource = read("scripts/components/walk-in-review-step.js")
  .replace(/import[\s\S]*?from\s+"[^"]+";/g, "").replace(/export /g, "");

function element() {
  const classes = new Set();
  return {
    disabled: false, textContent: "", innerHTML: "", attributes: {},
    setAttribute(key, value) { this.attributes[key] = value; },
    addEventListener() {},
    classList: {
      add(...values) { values.forEach((value) => classes.add(value)); },
      contains(value) { return classes.has(value); },
      toggle(value, enabled) { enabled ? classes.add(value) : classes.delete(value); },
    },
  };
}

function setup(preview) {
  let elements = new Map();
  const saved = new Map([["walkInOwnerStep", JSON.stringify({ ownerRecordType: "registered", customerUserId: 7 })]]);
  const calls = [], navigation = [], consentCalls = [];
  const item = {
    pet: { petId: 12, petName: "Coco", petType: "dog", size: "Small", weight: 8 },
    selection: { specialInstructions: "", groomingPreference: "summer_cut", estimateFactors: ["thick_coat"] },
    pricing: { hasSelection: true, total: {}, lineItems: [{ kind: "ala_carte", serviceId: "facial_trimming", serviceName: "Facial Trimming", pricing: { displayPrice: "P100" } }] },
    groomingEstimate: { formatted: "2–4 hrs" },
  };
  const payload = { items: [item], notices: [], totalPricing: {} };
  const get = (id) => {
    if (!elements.has(id)) elements.set(id, element());
    return elements.get(id);
  };
  const body = { className: "" };
  const main = { set outerHTML(_) { elements = new Map(); } };
  const context = vm.createContext({
    document: { body, addEventListener() {}, getElementById: get, querySelector: () => main },
    window: { history: { pushState(a, b, url) { navigation.push(url); } }, location: {} },
    sessionStorage: { getItem: (key) => saved.get(key), setItem: (key, value) => saved.set(key, value) },
    API: { waitForSessionVerification: async () => true, previewWalkInCapacity: (data) => { calls.push(data); return preview(data); } },
    buildWalkInPets: consent.buildWalkInPets,
    buildBookingReviewPayload: () => payload,
    hasRequiredGroomingPreference: () => true,
    specialInstructionsError: () => "",
    renderWalkInConsentStep: (options) => consentCalls.push(options),
    getGroomingPricePresentation: () => ({ label: "Estimated price", amount: "P100" }),
    petGroomerLabel: () => "Anna",
    renderEstimateReview: () => "Estimated grooming time: 2–4 hrs",
    escapeHtml: (value) => String(value).replace(/</g, "&lt;"),
    formatPetSizeLabel: (value) => value, formatPetTypeLabel: (value) => value,
    normalizePetSize: () => "small", formatAmountRange: () => "P100", getPackageById: () => null,
  });
  vm.runInContext(reviewSource, context);
  const render = (options = {}) => context.renderWalkInReviewStep({ pets: [item.pet], petSelections: [item.selection], ...options });
  return { context, get, render, calls, navigation, saved, payload, consentCalls };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));
const capacity = (fits) => ({ capacity: { fits,
  projected_last_completion: "2026-10-10T18:30:00+08:00", closing_time: "2026-10-10T17:00:00+08:00" } });

async function main() {
  let resolve;
  const pending = setup(() => new Promise((done) => { resolve = done; }));
  await pending.render();
  assert.equal(pending.get("confirmBookingBtn").disabled, true);
  pending.context.handleConfirmClick();
  assert.equal(pending.consentCalls.length, 0);
  assert.equal(pending.calls.length, 1);
  assert.equal(pending.calls[0].customer_user_id, 7);
  assert.deepEqual(pending.calls[0].pets, consent.buildApiPayload({}, pending.payload, {}).pets,
    "Preview and final submission send identical pet selections without retired factors");
  assert.equal(pending.calls[0].pets[0].grooming_preference, "summer_cut");
  assert.equal("estimate_factors" in pending.calls[0].pets[0], false);
  assert.equal(pending.calls[0].pets[0].haircut_requested, false);
  resolve(capacity(false));
  await flush();
  assert.equal(pending.get("confirmBookingBtn").disabled, true);
  assert.match(pending.get("reviewActionNotice").innerHTML, /Grooming capacity unavailable for today/);
  assert.match(pending.get("reviewActionNotice").innerHTML, /6:30 PM.*5:00 PM/);
  assert.equal(pending.get("reviewActionNotice").attributes.role, "alert");
  assert.equal(pending.get("walkInReviewBackBtn").textContent, "Review services");
  assert.equal(pending.context.formatCapacityTime("2026-10-11T08:25:00+08:00", "2026-10-10T23:59:00+08:00"), "8:25 AM (Oct 11)",
    "A next-day completion cannot look earlier than today's closing time");
  pending.context.handleConfirmClick();
  assert.equal(pending.navigation.length, 0);
  assert.equal(pending.saved.has("walkInReviewStep"), false);
  assert.equal(pending.saved.has("walkInBookingConfirmation"), false);

  let fits = true;
  const allowed = setup(() => Promise.resolve(capacity(fits)));
  await allowed.render();
  await flush();
  assert.equal(allowed.get("confirmBookingBtn").disabled, false);
  assert.equal(allowed.get("reviewActionNotice").classList.contains("hidden"), true);
  allowed.context.handleConfirmClick();
  assert.equal(allowed.consentCalls.length, 1);
  assert.equal(allowed.saved.has("walkInReviewStep"), true);
  assert.equal(allowed.saved.has("walkInBookingConfirmation"), false);
  fits = false;
  allowed.consentCalls[0].onBack();
  await flush();
  assert.equal(allowed.calls.length, 2, "Returning from Consent checks the live queue again");
  assert.equal(allowed.get("confirmBookingBtn").disabled, true);

  let fail = true;
  const retry = setup(() => fail ? Promise.reject(new Error("Unable to check grooming capacity.")) : Promise.resolve(capacity(true)));
  await retry.render();
  await flush();
  assert.equal(retry.get("confirmBookingBtn").disabled, true);
  assert.match(retry.get("reviewActionNotice").innerHTML, /Retry/);
  fail = false;
  await retry.context.checkCapacity();
  assert.equal(retry.get("confirmBookingBtn").disabled, false);

  const stale = setup(() => new Promise((done) => { resolve = done; }));
  let back = 0;
  await stale.render({ onBack: () => { back++; } });
  stale.context.handleBackClick();
  assert.equal(back, 1);
  resolve(capacity(true));
  await flush();
  assert.equal(stale.get("confirmBookingBtn").disabled, true, "A late preview cannot enable a departed Review");

  const multiple = setup(() => Promise.resolve(capacity(false)));
  multiple.payload.items.push({ ...multiple.payload.items[0], pet: { petName: "Bruno" } });
  await multiple.render();
  await flush();
  assert.equal(multiple.calls[0].pets.length, 2);
  assert.match(multiple.get("reviewActionNotice").innerHTML, /These pets cannot be completed/);
  console.log("Walk-in capacity preview regression tests passed.");
}

main().catch((error) => { console.error(error); process.exitCode = 1; });
