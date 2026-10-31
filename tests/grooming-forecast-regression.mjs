import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { createGroomingEstimateFlow, confirmClosingWarning } from '../scripts/components/grooming-estimates.js';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const pet = { id: 'coco', petType: 'dog', size: 'small' };
const selection = { petId: 'coco', servicePackage: 'partial_grooming', alaCarteServices: [], groomerId: 1 };
const late = { closing_risk: true, start_at: '2026-10-31T15:15:00+08:00', completion_at: '2026-10-31T18:15:00+08:00', closing_at: '2026-10-31T17:00:00+08:00', pets: [{}] };
{
  let submitted;
  const flow = createGroomingEstimateFlow({getPets:()=>[{...pet,id:'42',petType:'Dog',size:'Extra Large'}],
    getSelections:()=>[{...selection,petId:'42'}],requestForecast:async payload=>{submitted=payload;return {closing_risk:false};},onContinue:()=>{}});
  await flow.next();
  assert.equal(submitted.pets[0].size,'extra_large');
  assert.equal(submitted.pets[0].pet_id,42,'Customer saved-pet references use the same ID as submission');
}
for (const risk of [false,true]) {
  for (const choice of [false,true]) {
    let requests=0, confirmations=0, navigations=0;
    const waiting=deferred();
    const flow=createGroomingEstimateFlow({getPets:()=>[pet],getSelections:()=>[selection],
      requestForecast:async payload=>{ requests++; assert.equal(payload.durations_only,false); return waiting.promise; },
      confirmWarning:async()=>{ confirmations++; return choice; }, onContinue:()=>{navigations++;} });
    const next=flow.next();
    await flow.next();
    assert.equal(requests,1,'Repeated Next while pending issues only one request');
    waiting.resolve({...late,closing_risk:risk});
    await next;
    assert.equal(confirmations,risk?1:0);
    assert.equal(navigations,!risk||choice?1:0);
    if (!risk||choice) { await flow.next(); assert.equal(navigations,1,'Forward transition runs once'); }
    assert.equal(requests,1,'Selection changes alone do not open warning dialogs');
  }
}
{
  let navigations=0, warnings=0;
  const pending=deferred();
  let selections=[selection];
  const flow=createGroomingEstimateFlow({getPets:()=>[pet],getSelections:()=>selections,
    requestForecast:()=>pending.promise, confirmWarning:()=>{warnings++;return true;},onContinue:()=>{navigations++;}});
  const next=flow.next();
  selections=[{...selection,servicePackage:'bath_and_go'}];
  pending.resolve(late); await next;
  assert.equal(warnings,0,'Old forecast cannot override changed selections');
  assert.equal(navigations,0);
}
{
  let navigations=0, feedback=0;
  const flow=createGroomingEstimateFlow({getPets:()=>[pet],getSelections:()=>[selection],
    requestForecast:async()=>{throw Error('offline');},onUnavailable:()=>{feedback++;},onContinue:()=>{navigations++;}});
  await flow.next(); assert.equal(navigations,1); assert.equal(feedback,1,'Failure supplies non-blocking feedback');
  flow.reset(); await flow.next(); assert.equal(navigations,2,'Returning to services rechecks current forecast');
}
{
  let current=true,navigations=0,warnings=0,calls=0;
  const first=deferred(),second=deferred();
  const flow=createGroomingEstimateFlow({getPets:()=>[pet],getSelections:()=>[selection],isCurrent:()=>current,
    requestForecast:()=>++calls===1?first.promise:second.promise,
    confirmWarning:()=>{warnings++;return true;},onContinue:()=>{navigations++;}});
  const old=flow.next();current=false;first.resolve(late);await old;
  assert.equal(navigations,0,'Leaving Services cancels the pending transition');assert.equal(warnings,0);
  current=true;flow.reset();const next=flow.next();second.resolve({closing_risk:false});await next;
  assert.equal(navigations,1);
}
{
  const first=deferred(),second=deferred();let calls=0;
  const flow=createGroomingEstimateFlow({getPets:()=>[pet],getSelections:()=>[selection],
    requestForecast:()=>++calls===1?first.promise:second.promise,onContinue:()=>{}});
  const old=flow.next();flow.reset();const next=flow.next();first.resolve({closing_risk:false});await old;
  assert.equal(flow.busy,true,'A stale operation cannot unlock a newer request');
  second.resolve({closing_risk:false});await next;
}
{
  const first=deferred(),second=deferred();let calls=0,selections=[selection];
  const node={hidden:true,textContent:''};globalThis.CSS={escape:String};
  const flow=createGroomingEstimateFlow({getPets:()=>[pet],getSelections:()=>selections,container:{querySelector:()=>node},
    requestForecast:payload=>{assert.equal(payload.durations_only,true);return ++calls===1?first.promise:second.promise;},onContinue:()=>{}});
  flow.selectionChanged();await new Promise(resolve=>setTimeout(resolve,300));
  selections=[{...selection,servicePackage:'bath_and_go'}];flow.selectionChanged();await new Promise(resolve=>setTimeout(resolve,300));
  second.resolve({pets:[{duration:{min_minutes:90,max_minutes:120}}]});await new Promise(resolve=>setImmediate(resolve));
  assert.match(node.textContent,/1 hr 30 min–2 hrs/);assert.equal(node.hidden,false);
  first.resolve({pets:[{duration:{min_minutes:45,max_minutes:60}}]});await new Promise(resolve=>setImmediate(resolve));
  assert.match(node.textContent,/1 hr 30 min–2 hrs/,'Old duration responses cannot replace newer selections');
  flow.reset();
}

class Element {
  constructor(){this.listeners={};this.isConnected=true;this.style={};this.attributes={};}
  setAttribute(key,value){this.attributes[key]=value;}
  addEventListener(name,fn){(this.listeners[name]??=[]).push(fn);}
  emit(name,event={}){for(const fn of this.listeners[name]||[]) fn(event);}
  focus(){this.focused=true;}
  querySelector(selector){return this.children[selector];}
  remove(){this.removed=true;}
  showModal(){this.open=true;}
  close(value=''){this.returnValue=value;this.open=false;this.emit('close');}
}
for(const action of ['review','continue','escape']){
  const trigger=new Element();const dialog=new Element();
  dialog.children={'[data-review-services]':new Element(),'[data-continue-review]':new Element()};
  globalThis.document={activeElement:trigger,createElement:()=>dialog,body:{append(){}}};
  const pending=confirmClosingWarning(late);
  assert.equal(dialog.open,true);
  assert.match(dialog.innerHTML,/6:15 PM/);assert.match(dialog.innerHTML,/5:00 PM/);
  if(action==='escape')dialog.close();else dialog.children[action==='continue'?'[data-continue-review]':'[data-review-services]'].emit('click');
  assert.equal(await pending,action==='continue');assert.equal(trigger.focused,true);assert.equal(dialog.removed,true);
}
console.log('Grooming forecast navigation, stale/failure handling and dialog actions passed.');

// Exercise both real submit handlers: validation must precede the shared forecast gate.
for (const walkIn of [false,true]) {
  for (const invalid of ['instructions','groomer','services',null]) {
    let requests=0,navigations=0;
    const node={setAttribute(){},classList:{toggle(){}},querySelector(){return null;},addEventListener(){}};
    const values=new Map();
    const context=vm.createContext({console,CSS:{escape:String},
      document:{getElementById:()=>node,querySelector:()=>node,addEventListener(){}},
      window:{history:{pushState(){navigations++;}}},
      sessionStorage:{setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)},
      createGroomingEstimateFlow:options=>createGroomingEstimateFlow({...options,
        requestForecast:async()=>{requests++;return {closing_risk:false};}}),
      validateSpecialInstructions:()=>invalid!=='instructions',hasPetGroomerSelection:()=>invalid!=='groomer',
      hasCompletedRequiredServiceSelection:()=>invalid!=='services',
      createEmptyPetServiceSelection:()=>selection,selectedPricingSignature:()=>'',buildStepThreeDraftPayload:value=>value,
      goToGroomingStep:()=>{navigations++;},renderWalkInReviewStep(){},
      renderServiceSelections(){},updateSpecialInstructions(){},focusSpecialInstructions(){},
      BOOKING_STEP_THREE_KEY:'draft',
    });
    const file=walkIn?'walk-in-services-step':'booking-services-step';
    const source=fs.readFileSync(new URL(`../scripts/components/${file}.js`,import.meta.url),'utf8')
      .replace(/^import[\s\S]*?from ["'][^"']+["'];\r?\n/gm,'').replace(/^export /gm,'');
    vm.runInContext(source,context);
    await vm.runInContext(`state.catalogueReady=true;state.petSelections=[${JSON.stringify(selection)}];
      ${walkIn?`refreshElements();state.pets=[${JSON.stringify(pet)}];`:`state.bookingDraft={pets:[${JSON.stringify(pet)}]};`}
      handleSubmit({preventDefault(){}});`,context);
    assert.equal(requests,invalid?0:1,`${file}: ${invalid||'valid'} validation controls the forecast request`);
    assert.equal(navigations,invalid?0:1);
  }
}
console.log('Customer and walk-in Next validate instructions, groomers and services before forecasting.');
