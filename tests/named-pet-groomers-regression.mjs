import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as selector from '../scripts/components/pet-groomer-selection.js?services=20261026';
import * as grooming from '../scripts/services/grooming-service.js';

const people = [{ id: 11, first_name: 'Anna' }, { id: 24, first_name: 'Miguel' }];
const pets = [{ id: 'coco', petName: 'Coco', petType: 'dog', size: 'Small' },
  { id: 'luna', petName: 'Luna', petType: 'dog', size: 'Small' }];
let available = [];
globalThis.API = { getPetGroomers: async () => ({ pet_groomers: available }) };
grooming.applyGroomingCatalogue(JSON.parse(fs.readFileSync(new URL('./grooming-catalogue-fixture.json', import.meta.url))).data);

for (const walkIn of [false, true]) {
  const selections = pets.map(grooming.createEmptyPetServiceSelection);
  await selector.loadPetGroomers();
  selector.normalizePetGroomerSelections(selections);
  assert.equal(selector.hasPetGroomerSelection(selections[0], walkIn), false);
  assert.match(selector.renderPetGroomerSelection(pets[0], selections[0], walkIn), /No Pet Groomer available/);

  available = [people[0]];
  await selector.loadPetGroomers();
  selector.normalizePetGroomerSelections(selections);
  assert.deepEqual(selections.map((item) => item.groomerId), [11, 11]);
  for (let index = 0; index < pets.length; index++) {
    const markup = selector.renderPetGroomerSelection(pets[index], selections[index], walkIn);
    assert.match(markup, /Anna/);
    assert.doesNotMatch(markup, /type="radio"/);
    assert.equal(selector.hasPetGroomerSelection(selections[index], walkIn), true);
  }

  available = people;
  await selector.loadPetGroomers();
  const newSelections = pets.map(grooming.createEmptyPetServiceSelection);
  selector.normalizePetGroomerSelections(newSelections);
  const markup = selector.renderPetGroomerSelection(pets[0], newSelections[0], walkIn);
  assert.equal((markup.match(/type="radio"/g) || []).length, walkIn ? 2 : 3);
  assert.equal(markup.includes('Any available groomer'), !walkIn);
  assert.equal(selector.hasPetGroomerSelection(newSelections[0], walkIn), !walkIn);
  newSelections[0].groomerId = 11;
  newSelections[1].groomerId = 24;
  const saved = grooming.buildStepThreeDraftPayload(newSelections);
  const restored = grooming.normalizeStepThreeDraft(saved, { pets });
  assert.deepEqual(restored.map((item) => item.groomerId), [11, 24]);
  assert.deepEqual(grooming.buildBookingReviewPayload({ pets }, restored).items.map((item) => item.selection.groomerId), [11, 24]);
  assert.equal(selector.petGroomerLabel(restored[1]), 'Miguel');
  assert.equal(selector.hasPetGroomerSelection({ groomerId: 9999 }, walkIn), false);
  available = [];
}

// Both flows render the same groomer placement and preserve flow-specific options.
available = people;
const cards = await import('../scripts/components/grooming-service-selection.js?v=20261026');
await selector.loadPetGroomers();
for (const walkIn of [false, true]) {
  const html = cards.renderPetServiceCard({ id: 'pet1', petName: 'Rigby', petType: 'dog' },
    { servicePackage: '', alaCarteServices: [], specialInstructions: '', groomerId: null }, 0, walkIn);
  assert.ok(html.indexOf('Rigby') < html.indexOf('Pet Groomer'));
  assert.ok(html.indexOf('Pet Groomer') < html.indexOf('Select Services'));
  assert.equal(html.includes('Any available groomer'), !walkIn && people.length > 1);
}

// The walk-in request carries each pet's ID, independently of display names.
const source = fs.readFileSync(new URL('../scripts/components/walk-in-consent-step.js', import.meta.url), 'utf8')
  .replace(/^import[\s\S]*?from\s+"[^"]+";\s*/gm, '').replace(/^export /gm, '');
const context = vm.createContext({ document: { addEventListener() {} }, getPetWeightSubmission: () => ({}) });
vm.runInContext(source, context);
const payload = context.buildWalkInPets({ items: pets.map((pet, index) => ({ pet,
  selection: { groomerId: people[index].id }, pricing: { lineItems: [] } })) });
assert.deepEqual(Array.from(payload, (pet) => pet.groomer_id), [11, 24]);
console.log('Named Pet Groomer selection, per-pet drafts, review, and walk-in payload checks passed.');
