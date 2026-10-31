import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('scripts/components/booking-pet-step.js', 'utf8');
const restriction = source.slice(source.indexOf('function petSessionRestriction('), source.indexOf('function getBookingPets('));
const sync = source.slice(source.indexOf('async function syncSavedPets('), source.indexOf('function configurePageForFlow('));
for (const clinic of [false, true]) {
  let selected = [{id:'1'}, {id:'2'}], rendered = 0, failure = false;
  const service = clinic ? 'clinic' : 'grooming';
  const pets = [{id:'1', sessionAvailability:{[service]:{allowed:false,label:clinic ? 'Grooming Pending' : 'In Clinic'}}}, {id:'2', sessionAvailability:[]}];
  const context = vm.createContext({ IS_CLINIC_VISIT: clinic, petAvailabilityFailed:false, savedPetsLoading:true,
    loadPetsFromApi: async options => { assert.equal(options.force,true); assert.equal(options.sessionAvailability,true); if (failure) throw Error('offline'); },
    getSavedPets: () => pets, getBookingPets: () => selected, saveBookingPets: pets => {selected = pets;},
    isEligibleForGrooming: () => true, showStepError() {}, renderSelectedPets: () => {rendered++;}, renderExistingPets() {},
  });
  vm.runInContext(`${restriction}\n${sync}`, context);
  await context.syncSavedPets();
  assert.deepEqual(selected.map(pet => pet.id), ['2'], 'Returning to pet selection removes the opposite-service pet');
  assert.equal(context.petSessionRestriction(pets[0]),clinic ? 'Grooming Pending' : 'In Clinic');
  assert.equal(context.petSessionRestriction(pets[1]),'');
  assert.equal(rendered,1);
  failure = true;
  await context.syncSavedPets();
  assert.equal(selected.length,0,'Failed availability refresh clears potentially blocked saved selections');
  assert.equal(context.petAvailabilityFailed,true);
}
console.log('Pet session selection: service-specific restrictions, refreshed drafts and fail-closed errors passed.');

const staffSource = fs.readFileSync('scripts/components/walk-in-pet-step.js', 'utf8');
const guards = staffSource.slice(staffSource.indexOf('function savedPetUnavailable('), staffSource.indexOf('function renderExistingPets('));
for (const service of ['grooming', 'clinic']) {
  const pet = {petId:101, sessionAvailability:{[service]:{replaceable:false,label:'Pending'}}};
  const state = {pets:[pet], existingPets:[pet], existingPetsLoading:true};
  const context = vm.createContext({state, getOwnerAppointmentType:()=>service});
  vm.runInContext(guards,context);
  assert.equal(context.savedPetUnavailable(pet),true);
  state.existingPetsLoading=false;
  assert.equal(context.invalidSavedSelection(),true);
  pet.sessionAvailability[service].replaceable=true;
  assert.equal(context.savedPetUnavailable(pet),false,'Opposite-service pending remains selectable');
  state.petAvailabilityFailed=true;
  assert.equal(context.invalidSavedSelection(),true,'Failed refresh prevents draft continuation');
}
