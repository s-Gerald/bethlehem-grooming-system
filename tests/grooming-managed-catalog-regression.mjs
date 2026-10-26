import assert from 'node:assert/strict';
import fs from 'node:fs';
import { renderPackageCard, renderAlaCarteCard, focusSpecialInstructions } from '../scripts/components/grooming-service-selection.js?v=20261026&grooming=actual-20261026';
import * as grooming from '../scripts/services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1';

const catalogue = [
  {serviceId: 31, id: 'coat_care', name: 'Coat Care Grooming', kind: 'package', petType: 'dog',
    descriptionItems: ['Gentle bath and coat conditioning', 'Nail Clipping is available separately'],
    includedServiceIds: [32], includedAlaCarteServiceIds: ['ear_care'], allowsAlaCarteServices: true,
    priceOptions: ['small', 'medium', 'large', 'extra_large'].map((sizeKey, i) => ({sizeKey,
      label: ['Small', 'Medium', 'Large', 'Extra Large'][i], minAmount: [600, 700, 900, 1100][i],
      maxAmount: i > 1 ? null : [600, 700][i], pricingType: i > 1 ? 'starting_at' : 'fixed'}))},
  {serviceId: 32, id: 'ear_care', name: 'Ear Care', kind: 'ala_carte', petTypes: ['cat', 'dog'],
    descriptionItems: [], priceOptions: [{label: 'Standard', sizeKey: '', minAmount: 150, maxAmount: 150, pricingType: 'fixed'}]},
  {serviceId: 33, id: 'nail_care', name: 'Nail Clipping', kind: 'ala_carte', petTypes: ['cat', 'dog'],
    descriptionItems: [], priceOptions: [{label: 'Standard', sizeKey: '', minAmount: 100, maxAmount: 100, pricingType: 'fixed'}]},
];
grooming.applyGroomingCatalogue(catalogue);
assert.deepEqual(grooming.getPackagesByPetType('dog').map(s => s.name), ['Coat Care Grooming']);
assert.equal(grooming.getPackageById('coat_care').descriptionItems[0], 'Gentle bath and coat conditioning');
assert.deepEqual(grooming.getPackageAlaCarteRules('coat_care').includedAlaCarteServiceIds, ['ear_care']);
assert.equal(grooming.getPackagesByPetType('cat').length, 0);
const pet = {id: 'milo', petType: 'dog', size: 'large'};
const selection = {petId: 'milo', servicePackage: 'coat_care', alaCarteServices: [], groomingPreference: ''};
// Stale package preferences must not survive a package without haircuts.
assert.equal(grooming.sanitizePetServiceSelection({...selection, groomingPreference: 'custom_hairstyle', haircutRequested: true}).groomingPreference, '');
assert.equal(grooming.sanitizePetServiceSelection({...selection, haircutRequested: true}).haircutRequested, false);
const card = renderPackageCard(pet, selection, grooming.getPackageById('coat_care'));
assert.match(card, /Coat Care Grooming/);
assert.match(card, /Gentle bath and coat conditioning/);
assert.match(card, /900\+/);
const included = renderAlaCarteCard(pet, selection, grooming.getAlaCarteServiceById('ear_care'));
assert.match(included, /\sdisabled\s/);
assert.match(included, /Included in Package/);
const separate = renderAlaCarteCard(pet, selection, grooming.getAlaCarteServiceById('nail_care'));
assert.doesNotMatch(separate, /\sdisabled\s/);
const changed = structuredClone(catalogue);
changed[0].name = 'Coat and Skin Care';
changed[0].descriptionItems = ['Gentle skin care'];
changed[0].priceOptions[0].minAmount = changed[0].priceOptions[0].maxAmount = 650;
grooming.applyGroomingCatalogue(changed.filter(s => s.id !== 'nail_care'));
assert.equal(grooming.getPackageById('coat_care').name, 'Coat and Skin Care');
assert.equal(grooming.getPackageById('coat_care').priceOptions[0].minAmount, 650);
assert.equal(grooming.getAlaCarteServiceById('nail_care'), null);
grooming.applyGroomingCatalogue([]);
assert.equal(grooming.getPackageById('coat_care'), null);
assert.equal(grooming.getAlaCarteServicesByPetType('dog').length, 0);
console.log('Managed catalog and both grooming card renderers passed.');

const fixture = JSON.parse(fs.readFileSync(new URL('./grooming-catalogue-fixture.json', import.meta.url), 'utf8'));
grooming.applyGroomingCatalogue(fixture.data, fixture.groomingPreferences);
const dog = {id: 'rigby', petName: 'Rigby', petType: 'dog', size: 'small'};
const cat = {id: 'helio', petName: 'Helio', petType: 'cat', size: 'small'};
const dogSelection = {...grooming.createEmptyPetServiceSelection(dog), servicePackage: 'deluxe_dog_grooming', specialInstructions: 'Keep these notes'};
const catSelection = {...grooming.createEmptyPetServiceSelection(cat), servicePackage: 'cat_full_grooming'};
const change = (selection, role, value, checked = true) => grooming.updatePetServiceSelection(selection, {dataset:{role}, value, checked});
assert.equal(change(dogSelection, 'grooming-preference', 'custom_hairstyle'), true);
assert.equal(grooming.requiresSpecialInstructions(dogSelection), true);
assert.equal(grooming.requiresSpecialInstructions(catSelection), false);
assert.equal(change(catSelection, 'haircut-requested', '', true), true);
assert.equal(grooming.specialInstructionsError(cat, catSelection), 'Describe the requested haircut for Helio.');
catSelection.specialInstructions = ' \t ';
assert.ok(grooming.specialInstructionsError(cat, catSelection));
catSelection.specialInstructions = 'Trim the body only';
assert.equal(grooming.specialInstructionsError(cat, catSelection), '');
const saved = grooming.buildStepThreeDraftPayload([dogSelection, catSelection]);
const restored = grooming.normalizeStepThreeDraft(saved, {pets:[dog,cat]});
assert.equal(restored[1].haircutRequested, true);
assert.equal(restored[0].groomingPreference, 'custom_hairstyle');
assert.equal(change(dogSelection, 'grooming-preference', 'puppy_cut'), false);
assert.equal(grooming.requiresSpecialInstructions(dogSelection), false);
assert.equal(dogSelection.specialInstructions, 'Keep these notes');
change(catSelection, 'haircut-requested', '', false);
assert.equal(grooming.requiresSpecialInstructions(catSelection), false);
assert.equal(catSelection.specialInstructions, 'Trim the body only');
change(catSelection, 'service-package', '');
assert.equal(catSelection.haircutRequested, false);
assert.equal(change(catSelection, 'service-package', 'cat_full_grooming'), false);
assert.equal(catSelection.haircutRequested, false);
for (const packageId of ['partial_grooming', 'regular_dog_grooming', 'deluxe_dog_grooming']) {
  const selected = {...dogSelection, servicePackage:'bath_and_go', alaCarteServices:['facial_trimming','anal_sac_draining']};
  change(selected, 'service-package', packageId);
  assert.deepEqual(selected.alaCarteServices, ['anal_sac_draining']);
  for (const size of ['small','medium','large','extra_large']) {
    assert.equal(grooming.calculatePetSelectionPricing({...selected, alaCarteServices:['facial_trimming']}, {...dog,size}).lineItems.length, 1);
  }
}
const stale = grooming.normalizeStepThreeDraft({petSelections:[{...catSelection, servicePackage:'deluxe_dog_grooming', groomingPreference:'custom_hairstyle',haircutRequested:true}]}, {pets:[cat]});
assert.equal(stale[0].servicePackage, '');
assert.equal(stale[0].haircutRequested, false);
const scrolls = [], focused = [];
const field = {focus: options=>focused.push(options), scrollIntoView: options=>scrolls.push(options)};
const cardElement = {querySelector: ()=>field};
for (const reduced of [false,true]) {
  globalThis.matchMedia=()=>({matches:reduced});
  focusSpecialInstructions(cardElement);
}
assert.deepEqual(focused, [{preventScroll:true},{preventScroll:true}]);
assert.deepEqual(scrolls, [{behavior:'smooth',block:'center'},{behavior:'instant',block:'center'}]);
console.log('Haircut instructions, independent pet state, drafts, stale selections, all-size inclusions and reduced-motion focus passed.');
