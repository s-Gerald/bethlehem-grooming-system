import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import * as grooming from "../scripts/services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1";
import * as draft from "../scripts/services/booking-draft-service.js";
import { initBookingConfirmedStep } from "../scripts/components/booking-confirmed-step.js?grooming=actual-20261026";
import { formatSavedWeight } from "../scripts/components/pet-weight-size.js?v=pet-weight-size-20261011-v3";
import * as petGroomers from "../scripts/components/pet-groomer-selection.js?services=20261026";

import * as serviceSelection from "../scripts/components/grooming-service-selection.js?v=20261026&grooming=actual-20261026";

const fixture = JSON.parse(fs.readFileSync(new URL("./grooming-catalogue-fixture.json", import.meta.url), "utf8").replace(/^\uFEFF/, "")).data;
const pet = (id) => ({ id, petName: id, petType: "dog", size: "small", sizeVerified: false, weight: 5 });
const selection = (id, extras = [], servicePackage = "regular_dog_grooming") => ({
  petId: id, servicePackage, alaCarteServices: extras, groomingPreference: "summer_cut", specialInstructions: "",
});
const fixed = (amount) => ({ pricingType: "fixed", minAmount: amount, maxAmount: amount });
const range = (min, max) => ({ pricingType: "range", minAmount: min, maxAmount: max });
const startingAt = (min) => ({ pricingType: "starting_at", minAmount: min, maxAmount: null });
const cases = [
  { name: "One fixed service", selections: [selection("A")], expected: [550, 550, false] },
  { name: "Multiple fixed services", selections: [selection("A", ["anal_sac_draining"])], expected: [700, 700, false] },
  { name: "One range service", options: { regular_dog_grooming: range(400, 600) }, selections: [selection("A")], expected: [400, 600, true] },
  { name: "Fixed + range", options: { regular_dog_grooming: fixed(500), anal_sac_draining: range(400, 600) }, selections: [selection("A", ["anal_sac_draining"])], expected: [900, 1100, true] },
  { name: "Fixed + starting-at", options: { regular_dog_grooming: fixed(500), anal_sac_draining: startingAt(400) }, selections: [selection("A", ["anal_sac_draining"])], expected: [900, null, true] },
  { name: "Multiple pets, all fixed", selections: [selection("A", ["anal_sac_draining"]), selection("B", ["anal_sac_draining"])], expected: [1400, 1400, false] },
  { name: "Multiple pets, mixed pricing", options: { anal_sac_draining: range(200, 300) }, selections: [selection("A", ["anal_sac_draining"]), selection("B", ["anal_sac_draining"])], expected: [1500, 1700, true], petTotals: [[750, 850, true], [750, 850, true]] },
  { name: "No selected services", selections: [selection("A", [], "")], expected: [0, 0, false], empty: true },
  { name: "All variable services", options: { regular_dog_grooming: range(400, 600), anal_sac_draining: range(200, 300) }, selections: [selection("A", ["anal_sac_draining"])], expected: [600, 900, true] },
  { name: "Equal-bounds range retains its pricing type", options: { regular_dog_grooming: range(550, 550) }, selections: [selection("A")], expected: [550, 550, true] },
];

function createRenderer(file) {
  const elements = new Map();
  const classList = () => {
    const values = new Set();
    return { add: (...names) => names.forEach((name) => values.add(name)), contains: (name) => values.has(name), toggle() {}, remove() {} };
  };
  const document = { body: {}, addEventListener() {}, getElementById(id) {
    if (!elements.has(id)) elements.set(id, { classList: classList(), addEventListener() {} });
    return elements.get(id);
  } };
  const context = vm.createContext({ ...grooming, ...draft, ...petGroomers, formatSavedWeight, ...serviceSelection, document, sessionStorage: { setItem() {} }, window: {} });
  const source = fs.readFileSync(new URL(`../scripts/components/${file}.js`, import.meta.url), "utf8")
    .replace(/import[\s\S]*?from\s+"[^"]+";\s*/g, "")
    .replace(/export\s+(?=(?:async\s+)?function)/g, "");
  vm.runInContext(source, context);
  context.refreshElements?.();
  return { context, elements, file };
}

const reviews = ["booking-review-step", "walk-in-review-step"].map(createRenderer);
const services = ["booking-services-step", "walk-in-services-step"].map(createRenderer);
const root = { innerHTML: "", querySelector: () => ({ focus() {}, addEventListener() {} }) };
const saved = new Map();
globalThis.document = {
  body: { dataset: {}, classList: { add() {} } },
  getElementById: (id) => id === "confirmationRoot" ? root : {},
};
globalThis.window = { addEventListener() {}, removeEventListener() {}, print() {}, setTimeout() {} };
let protectedReads = 0, verifications = 0;
globalThis.sessionStorage = { getItem: (key) => { protectedReads++; return saved.get(key) || null; } };
globalThis.API = { waitForSessionVerification: async () => { verifications++; return true; } };

for (const test of cases) {
  const catalogue = structuredClone(fixture);
  for (const [id, pricing] of Object.entries(test.options || {})) {
    const service = catalogue.find((entry) => entry.id === id);
    service.priceOptions[0] = { ...service.priceOptions[0], ...pricing };
  }
  grooming.applyGroomingCatalogue(catalogue);
  const payload = grooming.buildBookingReviewPayload({ pets: test.selections.map((s) => pet(s.petId)) }, test.selections);
  const total = payload.totalPricing;
  assert.deepEqual([total.minAmount, total.maxAmount, total.isEstimate], test.expected, test.name);
  const presentation = grooming.getGroomingPricePresentation(total);
  assert.equal(presentation.label, test.expected[2] ? "Estimated Total Price" : "Total Price", test.name);
  assert.equal(Boolean(presentation.disclaimer), test.expected[2]);
  if (test.petTotals) assert.deepEqual(payload.items.map(({ pricing: { total: t } }) => [t.minAmount, t.maxAmount, t.isEstimate]), test.petTotals);

  for (const { context, elements, file } of reviews) {
    context.payload = payload;
    vm.runInContext("state.reviewPayload = payload; renderReviewNotice(); renderTotalPricing();", context);
    assert.equal(elements.get("totalPriceHeading").textContent, presentation.label);
    assert.equal(elements.get("totalPriceText").textContent, presentation.amount);
    assert.equal(elements.get("reviewNotice").textContent, presentation.disclaimer);
    assert.equal(elements.get("reviewNotice").className.includes("hidden"), !presentation.isEstimate);
    for (const [index, item] of payload.items.entries()) {
      const html = context.renderPetReviewCard(item, index);
      if (test.empty) assert.match(html, /No price available/);
      else assert.ok(html.includes(file === "booking-review-step"
        ? (item.pricing.total.isEstimate ? "Estimated pet subtotal" : "Pet subtotal")
        : grooming.getGroomingPricePresentation(item.pricing.total).label));
    }
  }

  for (const { context } of services) {
    context.selections = test.selections;
    vm.runInContext("state.petSelections = selections;", context);
    for (const [index, item] of payload.items.entries()) {
      const html = serviceSelection.renderPetServiceCard(item.pet, item.selection, index);
      if (test.empty) assert.match(html, /No service selected yet/);
      else assert.ok(html.includes(grooming.getGroomingPricePresentation(item.pricing.total).label));
      assert.doesNotMatch(html, /final service price may be confirmed/);
    }
  }

  const confirmation = {
    booking_reference: "GR-FIXTURE", status: "waiting", queue_number: 1,
    submitted_at: "2026-10-10T02:30:00Z", owner: { fullName: "Fixture Owner" },
    pets: payload.items.map((item) => item.pet),
    review: { totalPricing: total, pets: payload.items.map((item) => ({
      petId: item.pet.id, servicePackage: item.selection.servicePackage,
      pricing: item.pricing.total, groomingEstimate: { formatted: "1 hr 15 min" },
    })) },
  };
  for (const walkIn of [false, true]) {
    root.innerHTML = "";
    document.body.dataset.confirmationContext = walkIn ? "walk-in" : "customer";
    saved.set(walkIn ? "walkInBookingConfirmation" : "bookingConfirmation", JSON.stringify(confirmation));
    const beforeVerifications = verifications;
    await initBookingConfirmedStep();
    assert.equal(verifications - beforeVerifications, walkIn ? 1 : 0, "Walk-in confirmation verifies its staff session");
    assert.ok(root.innerHTML.includes(presentation.label));
    assert.ok(root.innerHTML.includes(presentation.amount));
    assert.equal(root.innerHTML.includes("Estimate Only"), presentation.isEstimate);
    assert.equal((root.innerHTML.match(/Final price is confirmed at the clinic\./g) || []).length, presentation.isEstimate ? 1 : 0);
    assert.doesNotMatch(root.innerHTML, /Estimated grooming time/);
    if (!presentation.isEstimate) assert.doesNotMatch(root.innerHTML, /Estimated Total Price|Estimated price|Estimate Only/);
  }
  console.log(`${test.name}: amounts, service/review labels, both confirmations passed`);
}

document.body.dataset.confirmationContext = "walk-in";
for (const verified of [false, true]) {
  root.innerHTML = "";
  let finishVerification;
  API.waitForSessionVerification = () => new Promise(resolve => { finishVerification = resolve; });
  const reads = protectedReads;
  const pending = initBookingConfirmedStep();
  assert.equal(root.innerHTML, "", "Pending session verification cannot render protected confirmation");
  assert.equal(protectedReads, reads, "Pending verification cannot read protected confirmation storage");
  finishVerification(verified);
  await pending;
  if (verified) {
    assert.match(root.innerHTML, /GR-FIXTURE/);
    assert.ok(protectedReads > reads);
  } else {
    assert.equal(root.innerHTML, "", "Failed session verification cannot render protected confirmation");
    assert.equal(protectedReads, reads, "Failed verification cannot read protected confirmation storage");
  }
}

assert.deepEqual(grooming.getGroomingPricePresentation(null), { label: "Total Price", amount: "To be confirmed", isEstimate: false, disclaimer: "" });
assert.equal(grooming.getGroomingPricePresentation({ minAmount: 900, maxAmount: null }).isEstimate, true);
assert.equal(grooming.getGroomingPricePresentation({ minAmount: 900, maxAmount: 1100 }).isEstimate, true);
console.log("Grooming price presentation regression checks passed.");
