const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const context = { window: {}, console, Date, Intl };
vm.runInNewContext(source, context);
const ui = context.adminDashboard();

const pets = [
  { bookingPetId: 1, petName: "Rigby", breed: "American Bully", species: "Dog" },
  { bookingPetId: 2, petName: "Bruno", breed: "Shih Tzu", species: "Dog" },
  { bookingPetId: 3, petName: "Mochi", breed: "Persian", species: "Cat" },
];
const booking = {
  pets,
  services: [
    { bookingPetId: 1, serviceName: "Regular Grooming" },
    { bookingPetId: 1, serviceName: "Nail Trim" },
    { bookingPetId: 2, serviceName: "Partial Grooming" },
    { bookingPetId: 3, serviceName: "Ear Cleaning" },
  ],
};

assert.equal(ui.getIncomingPets(booking).length, 3);
assert.equal(ui.formatIncomingPetBreedType(pets[0]), "American Bully · Dog");
assert.equal(ui.formatIncomingPetServices(pets[0], booking), "Regular Grooming + 1 more");
assert.equal(ui.formatIncomingPetServices(pets[1], booking), "Partial Grooming");
assert.equal(ui.formatIncomingPetServices(pets[2], booking), "Ear Cleaning");

const legacyBooking = { petName: "Rigby", breed: "American Bully", petType: "Dog", serviceLabel: "Partial Grooming" };
assert.equal(ui.getIncomingPets(legacyBooking).length, 1);
assert.equal(ui.formatIncomingPetServices(ui.getIncomingPets(legacyBooking)[0], legacyBooking), "Partial Grooming");

console.log("Admin grooming incoming card tests passed.");

assert.equal(ui.registrationVisitDay('2026-10-27'), 'Oct 27, 2026');
assert.match(ui.registrationTimestamp('2026-10-26T20:10:00Z'), /Oct 27, 2026.*4:10 AM/);
assert.equal(ui.registrationVisitDay(null), 'Not recorded');
