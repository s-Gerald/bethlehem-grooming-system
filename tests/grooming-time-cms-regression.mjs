import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import * as grooming from '../scripts/services/grooming-service.js?v=managed-services-20261026&grooming=cms-20261105&registration=fcfs-20261027-v1&short-notes=500-20261110';
import {renderHaircutPreference, renderPackageCard} from '../scripts/components/grooming-service-selection.js';
import {groomingDurationValue} from '../scripts/components/grooming-estimates.js';

const fixture = JSON.parse(fs.readFileSync(new URL('./grooming-catalogue-fixture.json', import.meta.url)));
grooming.applyGroomingCatalogue(fixture.data, {...fixture.groomingPreferences, cat_full_grooming:{cat_style:'Requested style'}});
const pet = {id:'cat',petType:'cat',size:'small'};
const selection = {...grooming.createEmptyPetServiceSelection(pet),servicePackage:'cat_full_grooming',specialInstructions:'Keep these notes'};
assert.equal(renderHaircutPreference(pet,selection),'');
assert.equal(grooming.hasRequiredGroomingPreference(selection),true);
grooming.updatePetServiceSelection(selection,{dataset:{role:'haircut-requested'},checked:true});
assert.equal(grooming.hasRequiredGroomingPreference(selection),false);
assert.match(renderPackageCard(pet,selection,grooming.getPackageById('cat_full_grooming')), /Include haircut[\s\S]*Requested style/);
grooming.updatePetServiceSelection(selection,{dataset:{role:'grooming-preference'},value:'cat_style'});
assert.equal(grooming.hasRequiredGroomingPreference(selection),true);
assert.equal(grooming.normalizeStepThreeDraft(grooming.buildStepThreeDraftPayload([selection]),{pets:[pet]})[0].groomingPreference,'cat_style');
grooming.updatePetServiceSelection(selection,{dataset:{role:'haircut-requested'},checked:false});
assert.equal(selection.groomingPreference,''); assert.equal(selection.specialInstructions,'Keep these notes');
assert.equal(groomingDurationValue(selection,null),'No estimated time');
assert.equal(groomingDurationValue(selection,null,false,true),'Estimate unavailable — retry');
grooming.applyGroomingCatalogue(fixture.data,fixture.groomingPreferences);
selection.haircutRequested = true;
assert.equal(renderHaircutPreference(pet,selection),''); assert.equal(grooming.hasRequiredGroomingPreference(selection),true);

const context = {console};
vm.runInNewContext(fs.readFileSync(new URL('../scripts/services/grooming-forecast-presentation.js',import.meta.url),'utf8'),context);
vm.runInNewContext(fs.readFileSync(new URL('../scripts/components/admin-grooming-services.js',import.meta.url),'utf8'),context);
const ui = context.adminGroomingServices();
ui.$store = {clinicAccess:{user:{role:'admin'}}};
ui.$refs = {editor:{showModal(){},close(){}},serviceName:{focus(){}}}; ui.$nextTick = fn=>fn();
ui.openEditor('package',{...fixture.data.find(s=>s.id==='regular_dog_grooming'), supportsHaircuts:true,
  timeEstimates:{small:[10,20]},haircutOptions:[{id:'kalbo',name:'Kalbo',isActive:true,estimates:{small:[30,45]}}]});
assert.equal(ui.form.timeRows[0].min.minutes,'10');
const row=ui.form.timeRows[0];
for (const invalid of ['123','-1','1.5','1e1','ab','60']) {
  const input={value:invalid};
  ui.timeInput(input,row,'min','minutes');
  assert.equal(row.min.minutes,'10',`reject ${invalid}`);
  assert.equal(input.value,'10');
}
ui.timeInput({value:'59'},row,'min','minutes'); assert.equal(row.min.minutes,'59');
ui.timeInput({value:'1'},row,'min','hours');
row.max.hours='2'; row.max.minutes='0';
assert.equal(ui.timeErrors().length,0);
row.max.hours='1'; row.max.minutes='59'; assert.ok(ui.timeErrors().length);
row.enabled=false; assert.equal(ui.timeErrors().length,0);
ui.addHaircut(); assert.equal(ui.form.haircuts.length,2); assert.ok(ui.timeErrors().length);
ui.form.haircuts[1].name='New style'; assert.equal(ui.timeErrors().length,0);
assert.equal(ui.timePayload().haircuts[0].id,'kalbo');
assert.equal(ui.timePayload().estimates.small.enabled,false);
for (const [min,max,label] of [[30,30,'30 min'],[60,60,'1 hr'],[90,90,'1 hr 30 min'],[120,120,'2 hrs'],[55,90,'55 min–1 hr 30 min'],[40,65,'40 min–1 hr 5 min']]) {
  assert.equal(context.GroomingForecastPresentation.duration({min_minutes:min,max_minutes:max}),label);
}
console.log('Managed time inputs, formatter, cat options, notes and drafts passed.');

// Exercise the actual change handlers: state changes must redraw the cat choices.
grooming.applyGroomingCatalogue(fixture.data, {...fixture.groomingPreferences,cat_full_grooming:{cat_style:'Requested style'}});
for (const walkIn of [false,true]) {
  let markup = '', requests = 0;
  const node = {setAttribute(){},classList:{toggle(){}},querySelector(){return node;},addEventListener(){},focus(){},scrollIntoView(){}};
  const flow = {selectionChanged(){requests++;}};
  const context = vm.createContext({console,CSS:{escape:String},document:{getElementById:()=>node,querySelector:()=>node,addEventListener(){}},
    window:{},sessionStorage:{setItem(){},removeItem(){}},createGroomingEstimateFlow:()=>flow,updatePetServiceSelection:grooming.updatePetServiceSelection,
    renderServiceSelections:(_container,_pets,selections)=>{markup=renderHaircutPreference(pet,selections[0]);},
    updateSpecialInstructions(){},focusSpecialInstructions(){},hasCompletedRequiredServiceSelection:()=>true,hasPetGroomerSelection:()=>true,
    createEmptyPetServiceSelection:grooming.createEmptyPetServiceSelection,buildStepThreeDraftPayload:value=>value,selectedPricingSignature:()=>'',BOOKING_STEP_THREE_KEY:'draft'});
  const source = fs.readFileSync(new URL(`../scripts/components/${walkIn?'walk-in':'booking'}-services-step.js`,import.meta.url),'utf8')
    .replace(/^import[\s\S]*?from ["'][^"']+["'];\r?\n/gm,'').replace(/^export /gm,'');
  vm.runInContext(source,context);
  vm.runInContext(`state.petSelections=[${JSON.stringify({...selection,haircutRequested:false})}];state.catalogueReady=true;
    ${walkIn ? `refreshElements();state.pets=[${JSON.stringify(pet)}];` : `state.bookingDraft={pets:[${JSON.stringify(pet)}]};`}
    handleSelectionChange({target:{dataset:{role:'haircut-requested',petId:'cat'},checked:true}});`,context);
  assert.match(markup,/Requested style/, `${walkIn?'Walk-in':'Customer'} checkbox immediately shows choices`);
  vm.runInContext(`state.petSelections[0].groomingPreference='cat_style';handleSelectionChange({target:{dataset:{role:'haircut-requested',petId:'cat'},checked:false}});`,context);
  assert.equal(markup,'');
  assert.equal(vm.runInContext('state.petSelections[0].groomingPreference',context),'');
  assert.equal(requests,2);
}
console.log('Customer and walk-in checkbox handlers immediately redraw cat haircuts.');
