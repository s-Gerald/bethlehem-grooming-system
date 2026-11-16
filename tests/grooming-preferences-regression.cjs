const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
async function main() {
  const grooming = await import('../scripts/services/grooming-service.js?v=managed-services-20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&short-notes=500-20261110&size-availability=20261110');
  const component = await import('../scripts/components/grooming-service-selection.js?v=20261026');
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8'));
  grooming.applyGroomingCatalogue(fixture.data, fixture.groomingPreferences);
  assert.deepEqual(grooming.getGroomingPreferences('regular_dog_grooming').map(p => p.label), ['Summer Cut', 'Semi-Kalbo', 'Kalbo', 'Regular Trim']);
  assert.deepEqual(grooming.getGroomingPreferences('deluxe_dog_grooming').map(p => p.label), ['Puppy Cut', 'Custom Hairstyle']);
  assert.equal(grooming.hasRequiredGroomingPreference({servicePackage:'regular_dog_grooming'}), false);
  const pet = {id:'coco', petType:'dog', size:'small'};
  const selection = {petId:'coco',servicePackage:'regular_dog_grooming',groomingPreference:'regular_trim',alaCarteServices:[]};
  assert.equal(grooming.specialInstructionsError(pet, { ...selection, specialInstructions: '🐾'.repeat(500) }), '');
  assert.equal(grooming.specialInstructionsError(pet, { ...selection, specialInstructions: 'x'.repeat(501) }), 'Maximum 500 characters allowed.');
  const review = grooming.buildBookingReviewPayload({pets:[pet]}, [selection]);
  assert.equal(review.items[0].pricing.hasSelection, true);
  assert.equal(review.items[0].selection.groomingPreference, 'regular_trim');
  assert.equal('groomingEstimate' in review.items[0], false);
  assert.match(component.renderGroomingPreference(selection), /Regular Trim/);
  assert.equal((component.renderHaircutPreference(pet, selection).match(/type="radio"/g)||[]).length, 4);
  assert.equal(grooming.normalizeStepThreeDraft(grooming.buildStepThreeDraftPayload([selection]),{pets:[pet]})[0].groomingPreference,'regular_trim');
  for (const pkg of grooming.GROOMING_PACKAGES) {
    const pet = {id:pkg.id,size:'small',petType:pkg.petType};
    const selected = {petId:pet.id,servicePackage:pkg.id,groomingPreference:grooming.getGroomingPreferences(pkg.id)[0]?.value||'',alaCarteServices:[]};
    const base = grooming.calculatePetSelectionPricing(selected,pet).total.minAmount;
    for (const service of grooming.ALA_CARTE_SERVICES) {
      const included = pkg.includedAlaCarteServiceIds.includes(service.id);
      const html = component.renderAlaCarteCard(pet, selected, service, pkg);
      assert.equal(/\sdisabled\s/.test(html), included);
      assert.equal(html.includes('Included in Package'), included);
      const next = grooming.sanitizePetServiceSelection({...selected,alaCarteServices:[service.id]});
      assert.equal(grooming.calculatePetSelectionPricing(next,pet).total.minAmount > base, !included);
    }
  }
  const reminder = "For tooth brushing, please bring your pet's own toothbrush.";
  for (const [petType, servicePackage, alaCarteServices, visible] of [
    ['dog', 'regular_dog_grooming', [], true],
    ['dog', 'deluxe_dog_grooming', [], true],
    ['dog', 'bath_and_go', [], true],
    ['dog', 'partial_grooming', [], false],
    ['dog', 'partial_grooming', ['tooth_brushing'], true],
    ['cat', 'cat_full_grooming', [], false],
    ['cat', 'cat_full_grooming', ['tooth_brushing'], true],
    ['dog', '', ['tooth_brushing'], true],
    ['cat', '', ['tooth_brushing'], true],
    ['dog', '', ['nail_clipping'], false],
    ['dog', '', [], false],
  ]) {
    const pet = {id: 'pet', petType, size: 'small'};
    const selected = {...grooming.createEmptyPetServiceSelection(pet), servicePackage, alaCarteServices};
    for (const walkIn of [false, true]) {
      const html = component.renderPetServiceCard(pet, selected, 0, walkIn);
      assert.equal(html.includes(reminder), visible && !walkIn, `${petType} ${servicePackage} ${alaCarteServices} walkIn=${walkIn}`);
      if (visible && !walkIn) {
        assert.ok(html.indexOf(reminder) > html.indexOf('Package Options'));
        assert.ok(html.indexOf(reminder) < html.indexOf('A la Carte Menu'));
        if (html.includes('Haircut Preference')) assert.ok(html.indexOf(reminder) < html.indexOf('Haircut Preference'));
      }
    }
  }
  // The same package ID can gain or lose included services through catalogue management.
  const managed = structuredClone(fixture.data);
  const partial = managed.find(service => service.id === 'partial_grooming');
  partial.name = 'Managed coat care';
  partial.includedAlaCarteServiceIds.push('tooth_brushing');
  grooming.applyGroomingCatalogue(managed, fixture.groomingPreferences);
  const managedSelection = {...selection, servicePackage: 'partial_grooming'};
  assert.ok(component.renderPetServiceCard(pet, managedSelection, 0).includes(reminder));
  partial.includedAlaCarteServiceIds = partial.includedAlaCarteServiceIds.filter(id => id !== 'tooth_brushing');
  grooming.applyGroomingCatalogue(managed, fixture.groomingPreferences);
  assert.ok(!component.renderPetServiceCard(pet, managedSelection, 0).includes(reminder));
  grooming.applyGroomingCatalogue(fixture.data, {...fixture.groomingPreferences, cat_full_grooming: {cat_style: 'Requested style'}});
  const cat = {id: 'cat', petType: 'cat', size: 'small'};
  const catSelection = {...grooming.createEmptyPetServiceSelection(cat), servicePackage: 'cat_full_grooming', haircutRequested: true, alaCarteServices: ['tooth_brushing']};
  const catHtml = component.renderPetServiceCard(cat, catSelection, 1);
  assert.ok(catHtml.indexOf(reminder) < catHtml.indexOf('Haircut Preference'), 'Customer cat haircut choices follow the helper too');
  assert.equal((catHtml.match(/Haircut Preference/g) || []).length, 1);
  assert.ok(!component.renderPetServiceCard(pet, managedSelection, 0).includes(reminder), 'Another pet keeps its independent selection');
  const walkInCat = component.renderPetServiceCard(cat, catSelection, 1, true);
  assert.ok(walkInCat.indexOf('Requested style') < walkInCat.indexOf('A la Carte Menu'));
  assert.ok(!walkInCat.includes(reminder));
  console.log('Independent grooming preferences, package inclusions, pricing and draft persistence passed.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
