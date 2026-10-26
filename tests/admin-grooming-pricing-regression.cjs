const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const { execFileSync } = require("node:child_process");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const context = { window: {}, console, Date, Intl };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components/cash-payment.js"), "utf8"), context);
vm.runInNewContext(source, context);
const ui = context.adminDashboard();

async function main() {
const catalogue = JSON.parse(fs.readFileSync(path.join(__dirname, "grooming-catalogue-fixture.json"), "utf8").replace(/^\uFEFF/, "")).data;
const catalogueModule = "../services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026";
for (const component of ["admin-dashboard", "booking-services-step", "booking-review-step", "booking-consent-step", "booking-confirmed-step", "grooming-service-selection", "grooming-pre-registration", "walk-in-services-step", "walk-in-review-step"]) {
  const componentSource = fs.readFileSync(path.join(__dirname, `../scripts/components/${component}.js`), "utf8");
  assert.ok(componentSource.includes(`"${catalogueModule}"`), `${component} must share the same live catalogue module instance`);
}
const grooming = await import(`../scripts/${catalogueModule.slice(3)}`);
context.applyPaymentCatalogue(grooming.applyGroomingCatalogue(catalogue));
const booking = {
  paid: false,
  pets: [{ bookingPetId: 1, petName: "Mochi", species: "Cat", size: "small" }],
  services: [
    { bookingServiceId: 1, bookingPetId: 1, slug: "cat_full_grooming", priceAtBooking: "500.00" },
    { bookingServiceId: 2, bookingPetId: 1, slug: "nail_clipping", priceAtBooking: "0.00", priceMinAtBooking: "50.00", priceMaxAtBooking: "100.00" },
  ],
};
assert.equal(ui.formatServiceAvailedPrice(booking.services[1], booking), "₱50–₱100");
assert.equal(ui.formatServicesAvailedTotal(booking), "₱550–₱600");
assert.equal(ui.formatPetServicesAvailedTotal(booking.pets[0], booking), "₱550–₱600");

const pet = { sizeKey: "small" };
const line = ui.normalizePaymentLine(booking.services[1], pet, "range", { lockFixedPrices: true });
assert.equal(line.isFixedPriceLocked, false);
assert.equal(line.amount, "");
const fixed = ui.normalizePaymentLine(booking.services[0], pet, "fixed", { lockFixedPrices: true });
assert.equal(fixed.isFixedPriceLocked, true);
assert.equal(fixed.amount, "500.00");
line.amount = "30";
assert.equal(ui.paymentLineError(line), "Enter an amount from ₱50 to ₱100.");
assert.equal(line.amount, "30");
line.amount = "101";
assert.equal(ui.paymentLineError(line), "Enter an amount from ₱50 to ₱100.");
assert.equal(line.amount, "101");
line.amount = "75.001";
assert.equal(ui.paymentLineError(line), "Enter an amount with no more than 2 decimal places.");
assert.equal(line.amount, "75.001");
for (const valid of ["50", "75", "100"]) {
  line.amount = valid;
  assert.equal(ui.paymentLineError(line), "");
}
ui.paymentModal.petBreakdown = [{ name: "Mochi", lines: [line] }];
ui.paymentModal.amountPaid = "100";
ui.paymentModal.paymentMethod = "cash";
line.amount = "101";
assert.equal(ui.canSubmitPayment, false);
line.amount = "75.001";
assert.equal(ui.canSubmitPayment, false);
line.amount = "80";
assert.equal(ui.canSubmitPayment, true);

const minimum = ui.normalizePaymentLine({ slug: "ear_cleaning", priceAtBooking: "150.00", priceMinAtBooking: "150.00" }, pet, "minimum");
minimum.amount = "149";
assert.equal(ui.paymentLineError(minimum), "Enter an amount of at least ₱150.");
assert.equal(minimum.amount, "149");
minimum.amount = "150";
assert.equal(ui.paymentLineError(minimum), "");

const largePet = { sizeKey: "large" };
const sizeDependent = ui.normalizePaymentLine({
  slug: "partial_grooming", priceAtBooking: "600.00", priceMinAtBooking: "600.00",
}, largePet, "package", { lockFixedPrices: true });
sizeDependent.amount = "650";
largePet.sizeKey = "extra_large";
ui.refreshPaymentLinePricing(largePet, sizeDependent);
assert.equal(sizeDependent.minAmount, 700);
assert.equal(sizeDependent.amount, "650");
assert.equal(ui.paymentLineError(sizeDependent), "Enter an amount of at least ₱700.");

booking.paid = true;
booking.services[1].priceAtBooking = "80.00";
assert.equal(ui.formatServiceAvailedPrice(booking.services[1], booking), "₱80.00");

const plusCases = [
  ["partial_grooming", "large", 600, 700, 1100],
  ["partial_grooming", "extra_large", 700, null, 1200],
  ["regular_dog_grooming", "large", 850, 1050, 1350],
  ["regular_dog_grooming", "extra_large", 1050, null, 1550],
  ["deluxe_dog_grooming", "large", 1000, 1200, 1500],
  ["deluxe_dog_grooming", "extra_large", 1200, null, 1700],
  ["bath_and_go", "large", 650, 750, 1150],
  ["bath_and_go", "extra_large", 750, null, 1250],
  ["ear_cleaning", "small", 150, null, 350],
  ["tooth_brushing", "small", 100, null, 300],
];
const ruleMoney = (amount) => `₱${amount.toLocaleString("en-PH")}`;
for (const [slug, sizeKey, min, threshold, max] of plusCases) {
  const service = ui.normalizePaymentLine({ slug, bookingServiceId: 1, priceMinAtBooking: String(min) }, { sizeKey }, slug, { lockFixedPrices: true });
  assert.equal(service.isFixedPriceLocked, false);
  assert.equal(service.priceHint, `${ruleMoney(min)}+`);
  assert.equal(service.maxAmount, null, "Safety caps must not become advertised ranges");
  assert.equal(service.safetyMaxAmount, max);
  assert.equal(service.reviewThreshold, threshold);
  const legacy = ui.normalizePaymentLine({ slug }, { sizeKey }, slug, { lockFixedPrices: true });
  assert.equal(legacy.safetyMaxAmount, max);
  assert.equal(legacy.reviewThreshold, threshold);
  ui.paymentModal.petBreakdown = [{ name: "Rigby", lines: [service] }];
  ui.paymentModal.amountPaid = String(max);
  service.amount = String(min - 0.01);
  assert.equal(ui.paymentLineError(service), `Enter an amount of at least ${ruleMoney(min)}.`);
  assert.equal(ui.canSubmitPayment, false);
  for (const valid of [min, min + 0.01, max]) {
    service.amount = String(valid);
    assert.equal(ui.paymentLineError(service), "");
    assert.equal(ui.canSubmitPayment, true);
  }
  if (threshold !== null) {
    service.amount = String(threshold - 0.01);
    assert.equal(ui.paymentLineWarning(service), "");
    for (const [amount, comparison] of [[threshold, "at"], [threshold + 0.01, "above"]]) {
      service.amount = String(amount);
      assert.equal(ui.paymentLineWarning(service), `This amount is ${comparison} the Extra Large starting price of ${ruleMoney(threshold)}. Confirm the pet's size and final charge.`);
      assert.equal(ui.canSubmitPayment, true, "A size-review warning must not block payment");
    }
  } else {
    assert.equal(ui.paymentLineWarning(service), "");
  }
  service.amount = String(max + 0.01);
  assert.equal(ui.paymentLineError(service), `Enter ${ruleMoney(max)} or less for this service.`);
  assert.equal(ui.canSubmitPayment, false);
  assert.equal(service.amount, String(max + 0.01), "Invalid input must not be clamped");
  for (const invalid of ["", "-1", "1e5", "1E3", "+850", "850.001", "NaN", "Infinity", " "]) {
    service.amount = invalid;
    assert.notEqual(ui.paymentLineError(service), "");
    assert.equal(ui.canSubmitPayment, false);
    assert.equal(service.amount, invalid);
  }
}

const resizedPet = { sizeKey: "large" };
const resizedLine = ui.normalizePaymentLine({ slug: "regular_dog_grooming", priceMinAtBooking: "850.00" }, resizedPet, "resized", { lockFixedPrices: true });
resizedLine.amount = "1400";
assert.notEqual(ui.paymentLineError(resizedLine), "");
resizedPet.sizeKey = "extra_large";
ui.refreshPaymentLinePricing(resizedPet, resizedLine);
assert.equal(resizedLine.safetyMaxAmount, 1550);
assert.equal(resizedLine.reviewThreshold, null);
assert.equal(resizedLine.priceHint, "₱1,050+");
assert.equal(resizedLine.amount, "1400");
assert.equal(ui.paymentLineError(resizedLine), "");
resizedPet.sizeKey = "medium";
ui.refreshPaymentLinePricing(resizedPet, resizedLine);
assert.equal(resizedLine.isFixedPriceLocked, true);
assert.equal(resizedLine.amount, "650.00");
assert.equal(resizedLine.safetyMaxAmount, null);
assert.equal(resizedLine.reviewThreshold, null);

for (const [slug, sizeKey, amount] of [["partial_grooming", "small", 400], ["regular_dog_grooming", "medium", 650], ["facial_trimming", "small", 150]]) {
  const locked = ui.normalizePaymentLine({ slug }, { sizeKey }, slug, { lockFixedPrices: true });
  assert.equal(locked.isFixedPriceLocked, true);
  assert.equal(locked.amount, amount.toFixed(2));
}
const fixedBounds = ui.normalizePaymentLine({ slug: "facial_trimming", priceMinAtBooking: "150", priceMaxAtBooking: "150" }, pet, "fixed-bounds", { lockFixedPrices: true });
assert.equal(fixedBounds.isFixedPriceLocked, true);

ui.paymentModal.petBreakdown = [{ lines: [{ amount: "1600" }] }];
assert.equal(ui.paymentMaximumAmount, 3000);
ui.paymentModal.amountPaid = "3000.01";
ui.enforcePaymentAmountLimit();
assert.equal(ui.paymentModal.amountPaid, "3000", "Cash Received keeps its existing bill-based clamp");
assert.equal(ui.paymentChange, 1400);
for (const invalid of ["", "100", "1600.001", "3000.01", "9".repeat(400)]) {
  ui.paymentModal.amountPaid = invalid;
  assert.equal(ui.paymentChange, 0, "Grooming and POS guard invalid change through the same helper");
  assert.equal(ui.canSubmitPayment, false);
}

// A newly loaded catalogue drives every consumer; original booking bounds still win.
const updated = JSON.parse(JSON.stringify(catalogue));
const regular = updated.find((service) => service.id === "regular_dog_grooming");
regular.priceOptions[0] = { ...regular.priceOptions[0], minAmount: 600, maxAmount: 600 };
regular.priceOptions[2] = { ...regular.priceOptions[2], minAmount: 950 };
regular.priceOptions[3] = { ...regular.priceOptions[3], minAmount: 1150 };
const facial = updated.find((service) => service.id === "facial_trimming");
facial.priceOptions[0] = { ...facial.priceOptions[0], pricingType: "starting_at", minAmount: 250, maxAmount: null };
context.applyPaymentCatalogue(grooming.applyGroomingCatalogue(updated));
assert.equal(grooming.evaluateServicePricing(grooming.getPackageById("regular_dog_grooming"), "small").displayPrice, "₱600");
const current = ui.normalizePaymentLine({ slug: regular.id }, { sizeKey: "large" }, "current");
assert.equal(current.minAmount, 950);
assert.equal(current.safetyMaxAmount, 1450);
assert.equal(current.reviewThreshold, 1150);
const historical = ui.normalizePaymentLine({ slug: regular.id, priceMinAtBooking: "850" }, { sizeKey: "large" }, "historical");
assert.equal(historical.minAmount, 850);
assert.equal(historical.safetyMaxAmount, 1350);
const changedMode = ui.normalizePaymentLine({ slug: facial.id }, pet, "new-starting");
assert.equal(changedMode.safetyMaxAmount, 450);
const historicalFixed = ui.normalizePaymentLine({ slug: facial.id, priceAtBooking: "150" }, pet, "old-fixed", { lockFixedPrices: true });
assert.equal(historicalFixed.isFixedPriceLocked, true);
assert.equal(historicalFixed.amount, "150.00");
assert.throws(() => grooming.applyGroomingCatalogue([{id: "invalid"}]), /unavailable/);

const legacyRules = ui.normalizePaymentLine({ slug: "ear_cleaning", priceAtBooking: "150",
  paymentPriceRules: { min: "150", max: null, pricing_type: "plus", safety_max: "350" } }, pet, "legacy-server-rules");
assert.equal(legacyRules.pricingType, "plus");
assert.equal(legacyRules.safetyMaxAmount, 350);
const draft = { pets: [{ id: "pet-1", size: "small", petType: "dog" }] };
const selection = [{ petId: "pet-1", servicePackage: regular.id, alaCarteServices: [], groomingPreference: "summer_cut", groomerId: 1 }];
const beforeSignature = grooming.selectedPricingSignature(draft, selection);
grooming.applyGroomingCatalogue(catalogue);
assert.notEqual(grooming.selectedPricingSignature(draft, selection), beforeSignature);
let loads = 0;
const groomingPreferences = JSON.parse(execFileSync("php", ["-r", "echo json_encode((require 'config/grooming_services.php')['preferences']);"], {
  cwd: path.resolve(__dirname, ".."), encoding: "utf8",
}));
global.API = { getGroomingCatalogue: async () => { loads++; return { data: updated, groomingPreferences }; } };
await grooming.loadGroomingCatalogue();
await grooming.loadGroomingCatalogue();
assert.equal(loads, 2, "A normal catalogue refresh must fetch current prices again");
// Review consumes the catalogue loaded in service selection, including new prices.
const reviewElements = new Map();
const reviewDocument = {
  body: {}, addEventListener() {},
  querySelector: () => ({ outerHTML: "" }),
  getElementById(id) {
    if (!reviewElements.has(id)) reviewElements.set(id, {
      classList: { add() {}, toggle() {} }, addEventListener() {}, setAttribute() {},
    });
    return reviewElements.get(id);
  },
};
global.document = reviewDocument;
global.window = {};
global.sessionStorage = { getItem: () => null, setItem() {} };
let verifications = 0, capacityReads = 0;
global.API.waitForSessionVerification = async () => { verifications++; return true; };
global.API.previewWalkInCapacity = async () => { capacityReads++; return { capacity: { fits: true } }; };
const { renderWalkInReviewStep } = await import("../scripts/components/walk-in-review-step.js?grooming=actual-20261026");
await renderWalkInReviewStep({ pets: draft.pets, petSelections: selection });
assert.equal(reviewElements.get("totalPriceText").textContent, "₱600");
assert.ok(reviewElements.get("reviewSelections").innerHTML.includes("₱600"));

// Package ranges use the same catalogue and bounds in selection, review, and payment.
regular.priceOptions[0] = { ...regular.priceOptions[0], pricingType: "range", minAmount: 600, maxAmount: 680 };
regular.priceOptions[3] = { ...regular.priceOptions[3], pricingType: "range", minAmount: 1150, maxAmount: 1800 };
context.applyPaymentCatalogue(grooming.applyGroomingCatalogue(updated));
const packageRange = grooming.evaluateServicePricing(grooming.getPackageById(regular.id), "small");
assert.equal(packageRange.displayPrice, "₱600–₱680");
assert.equal(packageRange.isEstimate, true);
await renderWalkInReviewStep({ pets: draft.pets, petSelections: selection });
assert.ok(reviewElements.get("reviewSelections").innerHTML.includes("₱600–₱680"));
assert.equal(verifications, 2, "Each protected review waits for session verification");
assert.equal(capacityReads, 0, "Walk-in reviews do not request a forecast");
assert.equal(loads, 2, "Rendering review reuses the catalogue from service selection");
const rangePet = { sizeKey: "small" };
const packageLine = ui.normalizePaymentLine({ slug: regular.id, priceMinAtBooking: "600", priceMaxAtBooking: "680" }, rangePet, "package-range", { lockFixedPrices: true });
assert.equal(packageLine.isFixedPriceLocked, false);
assert.equal(packageLine.safetyMaxAmount, null);
assert.equal(packageLine.reviewThreshold, null);
for (const amount of ["599", "681"]) {
  packageLine.amount = amount;
  assert.equal(ui.paymentLineError(packageLine), "Enter an amount from ₱600 to ₱680.");
}
for (const amount of ["600", "650", "680"]) {
  packageLine.amount = amount;
  assert.equal(ui.paymentLineError(packageLine), "");
}
regular.priceOptions[0].maxAmount = 750;
context.applyPaymentCatalogue(grooming.applyGroomingCatalogue(updated));
ui.refreshPaymentLinePricing(rangePet, packageLine);
assert.equal(packageLine.maxAmount, 680, "Historical package ranges must keep their saved maximum");
rangePet.sizeKey = "extra_large";
ui.refreshPaymentLinePricing(rangePet, packageLine);
assert.equal(packageLine.minAmount, 1150);
assert.equal(packageLine.maxAmount, 1800, "A size correction must use that size's current range");
assert.equal(packageLine.safetyMaxAmount, null);
packageLine.amount = "1800";
assert.equal(ui.paymentLineError(packageLine), "", "Starting-at safety caps do not apply to ranges");
packageLine.amount = "1800.01";
assert.equal(ui.paymentLineError(packageLine), "Enter an amount from ₱1,150 to ₱1,800.");

const inactiveCatalogue = structuredClone(updated);
inactiveCatalogue.find(service => service.id === 'regular_dog_grooming').isActive = false;
context.applyPaymentCatalogue(grooming.applyGroomingCatalogue(inactiveCatalogue));
assert.equal(grooming.getPackageById('regular_dog_grooming'), null, 'Inactive packages are unavailable for new selections');
const inactivePet = {sizeKey: 'small'};
const inactiveLine = ui.normalizePaymentLine({slug: 'regular_dog_grooming', priceMinAtBooking: '550', priceMaxAtBooking: '550'}, inactivePet, 'inactive', {lockFixedPrices: true});
inactivePet.sizeKey = 'large';
ui.refreshPaymentLinePricing(inactivePet, inactiveLine);
assert.equal(inactiveLine.minAmount, 950, 'Existing bookings can still correct sizes after the package is deactivated');

let billingReads = 0;
global.API.adminRequest = async (method, url) => {
  billingReads++;
  assert.equal(method, 'GET');
  assert.equal(url, '/grooming/services?include_inactive=1');
  return {data: inactiveCatalogue};
};
const billingCatalogue = await grooming.loadGroomingCatalogue({includeInactive: true});
assert.equal(billingReads, 1);
assert.ok(billingCatalogue.some(service => service.id === 'regular_dog_grooming'));
assert.equal(grooming.getPackageById('regular_dog_grooming'), null);

global.API.getGroomingCatalogue = async () => { throw new Error("Pricing unavailable"); };
await assert.rejects(grooming.loadGroomingCatalogue(), /unavailable/);
delete global.API;
delete global.document;
delete global.window;
delete global.sessionStorage;
console.log("Admin grooming pricing regression tests passed.");

}
main().catch((error) => { console.error(error); process.exitCode = 1; });
