const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const path = require('node:path');

async function main() {
  const server = spawnSync('php', [path.join(__dirname, 'grooming-estimate-cases.php')], { encoding: 'utf8' });
  assert.equal(server.status, 0, server.stderr || server.stdout);
  const { rules, cases } = JSON.parse(server.stdout);
  await import('../scripts/services/grooming-estimate-engine.js');
  const engine = globalThis.GroomingEstimates;
  engine.configure(rules);
  const grooming = await import('../scripts/services/grooming-service.js?v=managed-services-20261026');
  const { renderEstimateReview } = await import('../scripts/components/grooming-estimate-selection.js');
  const component = await import('../scripts/components/grooming-service-selection.js?v=20261026');
  assert.deepEqual(engine.preferences('regular_dog_grooming').map((p) => p.label), ['Summer Cut', 'Semi-Kalbo', 'Kalbo', 'Regular Trim']);
  assert.deepEqual(engine.preferences('deluxe_dog_grooming').map((p) => p.label), ['Puppy Cut', 'Custom Hairstyle']);
  for (const packageId of ['partial_grooming', 'bath_and_go', 'cat_full_grooming']) {
    assert.deepEqual(engine.preferences(packageId), []);
    const html = component.renderHaircutPreference({ id: 1, size: 'small' }, { servicePackage: packageId });
    assert.doesNotMatch(html, /data-role="grooming-preference"|<legend>Grooming preference/);
  }
  for (const [size, minutes, formatted] of [['small', 90, '1 hr 30 min'], ['medium', 105, '1 hr 45 min']]) {
    const selection = { servicePackage: 'cat_full_grooming' };
    const estimate = engine.calculate(selection, size);
    assert.deepEqual([estimate.minMinutes, estimate.maxMinutes, estimate.formatted], [minutes, minutes, formatted]);
    assert.doesNotMatch(component.renderHaircutPreference({ id: 1, size }, selection), /data-role="grooming-preference"|<legend>Grooming preference/);
    for (const factor of engine.factors()) {
      const extended = engine.calculate({ ...selection, estimateFactors: [factor.value] }, size);
      const minutesAdded = rules.factor_minutes[factor.value];
      assert.deepEqual([extended.minMinutes, extended.maxMinutes], [minutes + minutesAdded[0], minutes + minutesAdded[1]]);
    }
  }
  const regular = component.renderHaircutPreference({ id: 1, size: 'small' }, { servicePackage: 'regular_dog_grooming' });
  assert.equal((regular.match(/type="radio"/g) || []).length, 4);
  assert.doesNotMatch(regular, /Puppy Cut|Custom Hairstyle/);
  const deluxe = component.renderHaircutPreference({ id: 1, size: 'small' }, { servicePackage: 'deluxe_dog_grooming' });
  assert.equal((deluxe.match(/type="radio"/g) || []).length, 2);
  assert.doesNotMatch(deluxe, /Summer Cut|Semi-Kalbo|Kalbo|Regular Trim/);
  assert.equal(grooming.hasRequiredGroomingPreference({ servicePackage: 'regular_dog_grooming' }), false);
  assert.equal(grooming.hasRequiredGroomingPreference({ servicePackage: 'regular_dog_grooming', groomingPreference: 'regular_trim' }), true);
  for (const { selection, size, estimate } of cases) {
    assert.deepEqual(engine.calculate(selection, size), estimate, `${selection.servicePackage}/${selection.groomingPreference}/${size}`);
  }
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'grooming-catalogue-fixture.json'), 'utf8').replace(/^\uFEFF/, ''));
  grooming.applyGroomingCatalogue(fixture.data);
  const pets = [{ id: 'coco', petType: 'dog', size: 'medium' }, { id: 'bruno', petType: 'dog', size: 'large' }];
  const selections = pets.map((pet) => ({ petId: pet.id, servicePackage: 'regular_dog_grooming', groomingPreference: 'regular_trim', alaCarteServices: [] }));
  const review = grooming.buildBookingReviewPayload({ pets }, selections);
  assert.equal(review.items[0].groomingEstimate.formatted, '1 hr 30 min–1 hr 45 min');
  assert.equal(review.items[1].groomingEstimate.formatted, '2 hrs');
  assert.equal(grooming.normalizeStepThreeDraft(grooming.buildStepThreeDraftPayload(selections), { pets })[0].groomingPreference, 'regular_trim');
  assert.equal(engine.formatDuration(75), '1 hr 15 min');
  assert.equal(engine.formatDuration(72), '1 hr 12 min');
  assert.equal(engine.formatRange(120, 240), '2–4 hrs');

  const draft = await import('../scripts/services/booking-draft-service.js');
  const cardContexts = [component];
  for (const row of fixture.data) {
    if (row.kind === 'package') rules.packages[row.id].included = row.includedAlaCarteServiceIds;
  }
  engine.configure(rules);
  for (const pkg of grooming.GROOMING_PACKAGES) {
    assert.deepEqual([...pkg.includedAlaCarteServiceIds].sort(), [...rules.packages[pkg.id].included].sort(), pkg.id);
    const pet = { id: pkg.id, size: 'small', petType: pkg.petType };
    const selection = { petId: pet.id, servicePackage: pkg.id, groomingPreference: engine.preferences(pkg.id)[0]?.value || '', alaCarteServices: [] };
    const base = grooming.estimatePetGrooming(selection, pet);
    const basePrice = grooming.calculatePetSelectionPricing(selection, pet).total.minAmount;
    for (const service of grooming.ALA_CARTE_SERVICES) {
      const included = pkg.includedAlaCarteServiceIds.includes(service.id);
      for (const context of cardContexts) {
        const html = context.renderAlaCarteCard(pet, selection, service, pkg);
        assert.equal(/\sdisabled\s/.test(html), included, `${pkg.id}/${service.id}`);
        assert.equal(html.includes('Included in Package'), included);
      }
      const selected = grooming.sanitizePetServiceSelection({ ...selection, alaCarteServices: [service.id] });
      const extra = included ? [0, 0] : rules.ala_carte[service.id];
      const result = grooming.estimatePetGrooming(selected, pet);
      assert.equal(result.minMinutes, base.minMinutes + extra[0]);
      assert.equal(result.maxMinutes, base.maxMinutes + extra[1]);
      const price = grooming.calculatePetSelectionPricing(selected, pet).total.minAmount;
      assert.equal(price > basePrice, !included);
    }
  }
  const summer = { servicePackage: 'regular_dog_grooming', groomingPreference: 'summer_cut', alaCarteServices: [] };
  const small = { id: 'small', size: 'small' };
  assert.equal(grooming.estimatePetGrooming(summer, small).formatted, '1 hr 15 min');
  summer.alaCarteServices = ['facial_trimming'];
  assert.equal(grooming.estimatePetGrooming(summer, small).formatted, '1 hr 15 min');
  assert.match(renderEstimateReview(grooming.estimatePetGrooming(summer, small)), /1 hr 15 min/);
  summer.alaCarteServices = [];
  assert.equal(grooming.estimatePetGrooming(summer, small).formatted, '1 hr 15 min');

  const now = Date.parse('2026-10-09T13:15:00+08:00');
  const thickBath = engine.calculate({ servicePackage: 'bath_and_go', estimateFactors: ['thick_coat'] }, 'small');
  assert.deepEqual([thickBath.minMinutes, thickBath.maxMinutes, thickBath.formatted], [65, 75, '1 hr 5 min–1 hr 15 min']);
  const combinedBath = engine.calculate({ servicePackage: 'bath_and_go',
    alaCarteServices: ['nail_clipping', 'facial_trimming', 'facial_trimming'],
    estimateFactors: ['thick_coat', 'matted_tangled', 'extra_handling', 'detailed_styling', 'thick_coat'] }, 'small');
  assert.deepEqual([combinedBath.minMinutes, combinedBath.maxMinutes], [160, 270]);
  assert.equal(engine.calculate({ servicePackage: 'bath_and_go', estimateFactors: ['unknown'] }, 'small'), null);
  const additiveQueue = engine.queueETAs([{ key: 'first', estimate: thickBath }, { key: 'second', estimate: thickBath }], [], 1, now);
  assert.equal(additiveQueue.second.estDoneMax, now + 150 * 60000);
  const estimate = { minMinutes: 60, maxMinutes: 90 };
  const ready = engine.readyWindow(estimate, new Date(now).toISOString(), now);
  assert.equal(ready.label, '2:15–2:45 PM');
  const mediumTrim = engine.calculate({ servicePackage: 'regular_dog_grooming', groomingPreference: 'regular_trim' }, 'medium');
  assert.equal(engine.readyWindow(mediumTrim, new Date(now).toISOString(), now).label, '2:45–3:00 PM');
  assert.equal(engine.readyWindow(estimate, new Date(now).toISOString(), now + 91 * 60000).label, 'Taking longer than estimated');
  const elapsed = engine.readyWindow(estimate, new Date(now).toISOString(), now + 75 * 60000);
  assert.equal(elapsed.overdue, false);
  assert.doesNotMatch(elapsed.label, /2:15/);
  const waiting = [{ key: 'a', estimate }, { key: 'b', estimate }];
  const one = engine.queueETAs(waiting, [], 1, now), two = engine.queueETAs(waiting, [], 2, now);
  assert.equal(one.b.estDoneMax, now + 180 * 60000);
  assert.equal(two.b.estDoneMax, now + 90 * 60000);
  assert.equal(engine.queueETAs(waiting, [], 0, now).a.unavailable, true);
  const overdue = [{ key: 'active', estimate, startedAt: new Date(now - 91 * 60000).toISOString() }];
  const blocked = engine.queueETAs(waiting, overdue, 1, now);
  assert.equal(blocked.active.overdue, true);
  assert.equal(blocked.a.unavailable, true);
  assert.equal(engine.queueETAs(waiting, overdue, 2, now).a.unavailable, undefined);

  const queueNow = Date.now();
  class QueueDate extends Date { static now() { return queueNow; } }
  const context = { window: { GroomingEstimates: { ...engine, queueETAs: (waiting, active, capacity) => engine.queueETAs(waiting, active, capacity, queueNow) } }, Date: QueueDate, Intl, console };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../scripts/components/admin-dashboard.js'), 'utf8'), context);
  const ui = context.adminDashboard();
  const assessmentPet = { bookingPetId: 42, size: 'small', groomingEstimate: thickBath, estimateFactors: ['thick_coat'] };
  ui.actionConfirmModal.booking = { pets: [{ ...assessmentPet }], services: [{ bookingPetId: 42, slug: 'bath_and_go' }] };
  assert.equal(ui.assessmentPreview(assessmentPet), thickBath.formatted);
  assessmentPet.size = 'medium';
  assert.equal(ui.assessmentPreview(assessmentPet), '1 hr 20 min–1 hr 30 min');
  assessmentPet.estimateFactors = ['thick_coat', 'extra_handling'];
  assert.equal(ui.assessmentPreview(assessmentPet), '1 hr 40 min–2 hrs 15 min');
  assessmentPet.estimateFactors = [];
  assert.equal(ui.assessmentPreview(assessmentPet), '1 hr');
  ui.activeGroomers = 1;
  const first = { id: 1, bookingPetId: 1, groomingEstimate: estimate };
  const second = { id: 2, bookingPetId: 2, groomingEstimate: { minMinutes: 120, maxMinutes: 120 } };
  ui.queuedList = [{ id: 1, queueNumber: 1, pets: [first, second], services: [{ bookingPetId: 1, durationMinutes: 9999 }] }];
  ui.inProgressList = [];
  ui.groomingWorkload = { state: 'On track', pets: {
    1: { projected_start: new Date(queueNow).toISOString(), projected_completion: new Date(queueNow + 90 * 60000).toISOString(), queue_wait_minutes: 0, state: 'On track' },
    2: { projected_start: new Date(queueNow + 90 * 60000).toISOString(), projected_completion: new Date(queueNow + 210 * 60000).toISOString(), queue_wait_minutes: 90, state: 'On track' },
  } };
  assert.equal(ui.petETAs['1'].estDoneMax, queueNow + 90 * 60000);
  assert.match(ui.queueSummary.avgWaitLabel, /1 hr 30 min–1 hr 45 min/);
  assert.equal(ui.getBookingETA(ui.queuedList[0]), engine.formatTimeWindow(ui.petETAs['2'].estDoneMin, ui.petETAs['2'].estDoneMax));
  const before = ui.petETAs['2'].estDoneMax;
  first.groomingEstimate = grooming.estimatePetGrooming(selections[0], pets[0]);
  assert.equal(ui.petETAs['2'].estDoneMax, before, 'Queue projections come from the server, independent of client duration previews');
  first.groomingEstimate = grooming.estimatePetGrooming(selections[0], { ...pets[0], size: 'large' });
  assert.equal(ui.petETAs['2'].estDoneMax, before);
  const normalized = ui.normalizeDashboardPayload({ groomingWorkload: { ...ui.groomingWorkload, state: 'Needs staff action' } });
  assert.equal(normalized.groomingWorkload.state, 'Needs staff action');
  ui.groomingWorkload.pets[2].state = 'At risk';
  assert.equal(ui.getPetWorkloadState(second), 'At risk');
  assert.equal(ui.petETAs['2'].queueWait, 90);
  first.groomingEstimate = estimate;
  ui.activeGroomingPets = 1;
  assert.equal(ui.isGroomerCapacityFull, true);
  ui.inProgressList = [{ id: 3, pets: [{ ...first, groomingStartedAtIso: new Date(Date.now() - 91 * 60000).toISOString(), isGroomingStarted: true }] }];
  ui.queuedList = [];
  ui.groomingWorkload.pets[1].state = 'Needs staff action';
  assert.equal(ui.getPetWorkloadState(first), 'Needs staff action');
  assert.equal(ui.inProgressList[0].pets[0].isGroomingStarted, true);
  for (const item of review.items) {
    const html = component.renderPetServiceCard(item.pet, item.selection, 0);
    assert.doesNotMatch(html, /Estimated grooming time|Estimate factors|Service Rules/);
    assert.match(html, /Haircut Preference/);
  }
  console.log(`Grooming estimate regression tests passed (${cases.length} server/preview comparisons).`);
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
