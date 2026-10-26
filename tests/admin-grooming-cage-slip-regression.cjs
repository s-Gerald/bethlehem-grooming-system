const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const classes = new Set();
const listeners = new Map();
const printStyles = new Set();
let printed = 0;
let appended;
let fallback;
let clearedTimer;
const context = {
  console, Date, Intl,
  document: {
    title: "Admin Grooming",
    createElement(tag) {
      if (tag === "span") {
        return {
          textContent: "",
          get innerHTML() {
            return this.textContent.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
          },
        };
      }
      if (tag === "style") {
        return { remove() { printStyles.delete(this); } };
      }
      assert.equal(tag, "section");
      return { remove() { this.removed = true; } };
    },
    head: { appendChild(node) { printStyles.add(node); } },
    body: {
      appendChild(node) { appended = node; },
      classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name) },
    },
  },
  window: {
    addEventListener: (name, callback) => listeners.set(name, callback),
    removeEventListener: (name) => listeners.delete(name),
    setTimeout(callback, delay) { assert.equal(delay, 60000); fallback = callback; return 1; },
    clearTimeout(id) { clearedTimer = id; },
    print() {
      assert.equal(appended.className, "pet-grooming-print-clone");
      assert.ok(classes.has("pet-grooming-card-printing"));
      assert.equal(printStyles.size, 1, "Apply page sizing only during this print operation");
      const pageStyle = [...printStyles][0];
      assert.equal(pageStyle.media, "print");
      assert.match(pageStyle.textContent, /@page\s*\{\s*size:\s*105mm 148mm;\s*margin:\s*0;/);
      printed++;
    },
  },
};
vm.runInNewContext(source, context);
const ui = context.adminDashboard();
const pet = {
  bookingPetId: 1, petQueueNumber: 12, petName: "Mochi <small>",
  breed: "Persian", species: "Cat", size: "small",
  furType: "Long fur", weight: "4 kg",
  specialInstructions: "Keep tail fluffy.\nUse <gentle> shampoo & rinse well.",
  medicalConditions: "Sensitive skin; avoid scented products.",
};
const otherPet = { bookingPetId: 2, petName: "Bruno", breed: "Shih Tzu" };
const booking = {
  ownerName: "Alex & Sam", bookingType: "Online", contactNumber: "09123456789",
  dropOffTime: "10:30 AM", internalStaffNote: "Handle gently around ears.",
  pets: [pet, otherPet],
  services: [
    { bookingPetId: 1, serviceName: "Regular Grooming" },
    { bookingPetId: 1, serviceName: "Nail Trim" },
    { bookingPetId: 2, serviceName: "Ear Cleaning" },
  ],
};
const original = JSON.stringify(booking);
ui.printPetCard(booking, pet);
const html = appended.innerHTML;
assert.equal(printed, 1, "One print request per selected pet");
assert.equal(context.document.title, "#P12 Mochi <small>");
const orderedContent = [
  "#P12", "Mochi &lt;small&gt;", "Persian · Small", "Alex &amp; Sam",
  "Regular Grooming", "Nail Trim", "Customer Note", "Keep tail fluffy.",
  "Staff Note", "Handle gently around ears.", "Medical Alert", "Sensitive skin",
  "Grooming Cage Slip", "Bethlehem Animal Clinic",
];
let lastIndex = -1;
for (const text of orderedContent) {
  const index = html.indexOf(text);
  assert.ok(index > lastIndex, `${text} appears in the requested information order`);
  lastIndex = index;
}
assert.ok(html.includes("Use &lt;gentle&gt; shampoo &amp; rinse well."));
for (const unwanted of ["Bruno", "Ear Cleaning", "Cat", "Phone number", "09123456789", "Long fur", "4 kg", "10:30 AM", "Individual Pet Grooming Card"]) {
  assert.ok(!html.includes(unwanted), `${unwanted} must not appear on this pet's slip`);
}
assert.equal(JSON.stringify(booking), original, "Printing must not change the booking data");
listeners.get("afterprint")();
assert.ok(appended.removed);
assert.ok(!classes.has("pet-grooming-card-printing"));
assert.ok(!listeners.has("afterprint"));
assert.equal(clearedTimer, 1);
assert.equal(context.document.title, "Admin Grooming");
assert.equal(printStyles.size, 0, "afterprint removes the temporary page size before invoice printing");

const emptyPet = {
  pet_name: "Luna", breed: "—", pet_type: "cat", pet_size: "medium",
  special_instructions: " \n ", medical_conditions: " \n ",
};
ui.printPetCard({ ownerName: "Taylor", internalStaffNote: " \n " }, emptyPet, 2);
assert.ok(appended.innerHTML.includes("Not assigned"));
assert.ok(appended.innerHTML.includes("Cat · Medium"), "Species replaces missing breed");
for (const heading of ["Selected services", "Customer Note", "Staff Note", "Medical Alert"]) {
  assert.ok(!appended.innerHTML.includes(heading), `${heading} is hidden when empty`);
}
fallback();
assert.ok(appended.removed, "Fallback still cleans up when afterprint is unavailable");
assert.equal(printStyles.size, 0, "Fallback also removes the temporary page size");
assert.equal(context.document.title, "Admin Grooming");

const walkInPet = { ...emptyPet, special_instructions: "Trim nails only.", medical_conditions: "Arthritis" };
ui.printPetCard({ bookingType: "Walk-In" }, walkInPet);
assert.ok(appended.innerHTML.includes("Grooming & Visit Notes"), "Keep the existing Walk-In note meaning");
assert.ok(appended.innerHTML.includes("Trim nails only."));
assert.ok(appended.innerHTML.includes("Arthritis"), "Support the existing alternate medical field");
assert.ok(!appended.innerHTML.includes("Staff Note"), "Walk-In does not add an empty staff note");
listeners.get("afterprint")();
ui.printPetCard({ bookingType: "Walk-In", internalStaffNote: "Recorded staff note" }, walkInPet);
assert.ok(appended.innerHTML.includes("Recorded staff note"), "Print a staff note whenever one exists");
listeners.get("afterprint")();
assert.equal(printStyles.size, 0, "Repeated prints must not leave page-size rules behind");
const printError = new Error("Printing unavailable");
context.window.print = () => { throw printError; };
assert.throws(() => ui.printPetCard(booking, pet), (error) => error === printError);
assert.equal(printStyles.size, 0, "A failed print must not leave cage-slip sizing active for invoices");
assert.ok(appended.removed);
assert.ok(!classes.has("pet-grooming-card-printing"));
assert.equal(context.document.title, "Admin Grooming");
console.log("Admin grooming cage slip regression tests passed.");
