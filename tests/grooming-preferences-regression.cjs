const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
async function main() {
  const grooming = await import('../scripts/services/grooming-service.js?v=managed-services-20261026&grooming=actual-20261026&registration=fcfs-20261027-v1');
  const component = await import('../scripts/components/grooming-service-selection.js?v=20261026');
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8'));
  grooming.applyGroomingCatalogue(fixture.data, fixture.groomingPreferences);
  assert.deepEqual(grooming.getGroomingPreferences('regular_dog_grooming').map(p => p.label), ['Summer Cut', 'Semi-Kalbo', 'Kalbo', 'Regular Trim']);
  assert.deepEqual(grooming.getGroomingPreferences('deluxe_dog_grooming').map(p => p.label), ['Puppy Cut', 'Custom Hairstyle']);
  assert.equal(grooming.hasRequiredGroomingPreference({servicePackage:'regular_dog_grooming'}), false);
  const pet = {id:'coco', petType:'dog', size:'small'};
  const selection = {petId:'coco',servicePackage:'regular_dog_grooming',groomingPreference:'regular_trim',alaCarteServices:[]};
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
  console.log('Independent grooming preferences, package inclusions, pricing and draft persistence passed.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
