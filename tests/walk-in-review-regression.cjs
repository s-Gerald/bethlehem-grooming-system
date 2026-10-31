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
    pet: { petId: 12, petName: "Coco", color: "Brown/White", medicalNotes: "Sensitive skin", petType: "dog", size: "Small", weight: 8 },
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
    sessionStorage: { getItem: (key) => saved.get(key), setItem: (key, value) => saved.set(key, value), removeItem: key => saved.delete(key) },
    API: { waitForSessionVerification: async () => true, previewWalkInCapacity: (data) => { calls.push(data); return preview(data); } },
    buildWalkInPets: consent.buildWalkInPets,
    buildBookingReviewPayload: () => payload,
    hasRequiredGroomingPreference: () => true,
    specialInstructionsError: () => "",
    renderWalkInConsentStep: (options) => consentCalls.push(options),
    getGroomingPricePresentation: () => ({ label: "Estimated price", amount: "P100" }),
    petGroomerLabel: () => "Anna",
    renderGroomingPreference: () => "Grooming preference: Summer Cut",
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
  const review = setup(() => { throw new Error("Review must not request a forecast"); });
  await review.render();
  assert.equal(review.get("confirmBookingBtn").disabled, false);
  assert.equal(review.calls.length, 0);
  review.context.handleConfirmClick();
  assert.equal(review.consentCalls.length, 1);
  assert.equal(review.navigation[0], "./walk-in-consent.html");
  assert.equal(JSON.parse(review.saved.get("walkInReviewStep")).items.length, 1);
  const pets = consent.buildWalkInPets(review.payload);
  assert.equal(pets[0].grooming_preference, "summer_cut");
  assert.equal(pets[0].color, "Brown/White");
  assert.equal(pets[0].medical_conditions, "Sensitive skin");
  assert.equal("estimate_factors" in pets[0], false);
  // Run the actual submission handler: rapid events, lost responses, and recovery.
  const draft = new Map([
    ['walkInOwnerStep', JSON.stringify({ firstName: 'John', lastName: 'Lennon', phone: '09171234567' })],
    ['walkInReviewStep', JSON.stringify(review.payload)],
    ['walkInConsentStep', JSON.stringify({ signature_strokes: [[[1, 1], [30, 40]]] })],
  ]);
  const originalDraft = draft.get('walkInConsentStep');
  const sent = [];
  let rejectRequest;
  const confirmations = [];
  const button = element(), message = element();
  const signature = { valid: true, payload: () => ({ signature_strokes: [[[1, 1], [30, 40]]] }),
    confirmSubmission: () => new Promise(resolve => { confirmations.push(resolve); }),
    setBusy(value) { this.valid = !value; } };
  const submission = vm.createContext({
    document: { addEventListener() {} }, window: { location: {} }, crypto: require('node:crypto').webcrypto,
    sessionStorage: { getItem: key => draft.get(key), setItem: (key, value) => draft.set(key, value), removeItem: key => draft.delete(key) },
    getPetWeightSubmission: pet => ({ weight: pet.weight }), getPetWeightSubmissionValidationMessage: () => '',
    API: { submitWalkIn(payload) { sent.push(payload); return new Promise((_, reject) => { rejectRequest = reject; }); } },
  });
  vm.runInContext(read('scripts/components/walk-in-consent-step.js').replace(/import[\s\S]*?from\s+"[^"]+";/g, '').replace(/export /g, ''), submission);
  submission.signature = signature; submission.button = button; submission.message = message;
  vm.runInContext('consent = signature; elements = { submitBookingButton: button, consentStatusMessage: message };', submission);
  const event = { preventDefault() {} };
  const first = submission.handleSubmit(event);
  const second = submission.handleSubmit(event);
  assert.equal(confirmations.length, 1, 'Rapid submits must open only one confirmation');
  confirmations.shift()(true);
  await flush();
  assert.equal(sent.length, 1, 'Rapid submissions must share one pending request');
  assert.equal(button.disabled, true);
  rejectRequest(new Error('Connection lost. Please try again.'));
  await Promise.all([first, second]);
  assert.equal(draft.get('walkInConsentStep'), originalDraft, 'Failure preserves the captured signature');
  assert.equal(signature.valid, true);
  assert.match(message.textContent, /Connection lost/);
  assert.match(sent[0].submission_key, /^[0-9a-f-]{36}$/);
  const retry = submission.handleSubmit(event);
  confirmations.shift()(true);
  await flush();
  assert.equal(sent[1].submission_key, sent[0].submission_key, 'A lost response retry keeps its identity');
  rejectRequest(new Error('Still offline'));
  await retry;
  console.log("Walk-in review proceeds without a forecast request.");
}
main().catch(error => { console.error(error); process.exitCode = 1; });
