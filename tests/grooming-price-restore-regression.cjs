const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function main() {
  const source = fs.readFileSync('scripts/components/admin-grooming-services.js', 'utf8');
  let persisted = {serviceId:17,id:'regular_dog_grooming',name:'Regular Dog Grooming',kind:'package',petType:'dog',isActive:true,
    descriptionItems:['Bath and trim'],includedServiceIds:[9],supportsHaircuts:true,
    priceOptions:[{sizeKey:'small',label:'Small',pricingType:'fixed',minAmount:777,maxAmount:777}],
    defaultPriceOptions:[{sizeKey:'small',label:'Small',pricingType:'range',minAmount:550,maxAmount:600}],
    timeEstimates:{small:[45,60]},haircutOptions:[{id:'kalbo',name:'Kalbo',isActive:true,estimates:{small:[30,45]}}]};
  const original = structuredClone(persisted), writes = [], notices = [];
  const context = {console,API:{
    async loadAdminData(_path,read,present) {present(await read());},
    async adminRequest(method,_path,payload) {
      if (method === 'GET') return {data:[structuredClone(persisted)],priceLimits:{package:{minimum:1,maximum:20000}}};
      writes.push(structuredClone(payload));
      persisted.priceOptions = Object.entries(payload.sizes).map(([sizeKey,row]) => ({sizeKey,label:'Small',pricingType:row.pricing_type,minAmount:Number(row.amount),maxAmount:Number(row.maximum ?? row.amount)}));
      return {data:structuredClone(persisted)};
    },
  }};
  vm.runInNewContext(source,context);
  const ui = context.adminGroomingServices();
  ui.$store = {clinicAccess:{user:{role:'admin'}},clinicFeedback:{notify:message=>notices.push(message)}};
  ui.$refs = {editor:{showModal(){},close(){}},serviceName:{focus(){}},formError:{focus(){}},form:{checkValidity:()=>true,reportValidity:()=>true}};
  ui.$nextTick=fn=>fn();
  await ui.load();
  ui.openEditor('package',ui.rows[0]);
  const timeDraft = JSON.stringify({rows:ui.form.timeRows,haircuts:ui.form.haircuts,included:ui.form.includedServiceIds});
  ui.restorePrices();
  assert.equal(writes.length,0,'Restore alone never writes');
  assert.deepEqual(persisted,original,'Restore preserves stored prices, time configuration and inclusions');
  assert.equal(ui.rows[0].priceOptions[0].minAmount,777,'Persisted catalogue stays unchanged');
  assert.equal(ui.form.priceRows[0].amount,'550');
  assert.equal(ui.form.priceRows[0].maximum,'600');
  assert.equal(JSON.stringify({rows:ui.form.timeRows,haircuts:ui.form.haircuts,included:ui.form.includedServiceIds}),timeDraft);
  assert.equal(ui.timeDirty,false);
  assert.equal(ui.priceNotice,'Defaults selected. Save changes to apply.');
  ui.closeEditor();
  assert.equal(ui.form,null);
  assert.equal(ui.priceNotice,'');
  ui.openEditor('package',ui.rows[0]);
  assert.equal(ui.form.priceRows[0].amount,'777','Dismiss and reopen discard proposed defaults');
  ui.restorePrices();
  ui.closeEditor();
  await ui.load();
  ui.openEditor('package',ui.rows[0]);
  assert.equal(ui.form.priceRows[0].amount,'777','Fresh catalogue reads original prices without a save');
  ui.restorePrices();
  await ui.save();
  assert.equal(writes.length,1,'Save alone persists the restored draft');
  assert.equal('timeConfiguration' in writes[0],false,'Price restoration does not rewrite time settings');
  assert.equal(persisted.priceOptions[0].minAmount,550);
  assert.equal(persisted.priceOptions[0].maxAmount,600);
  assert.deepEqual(persisted.timeEstimates,original.timeEstimates);
  assert.deepEqual(persisted.haircutOptions,original.haircutOptions);
  assert.deepEqual(persisted.includedServiceIds,original.includedServiceIds);
  assert.equal(ui.rows[0].priceOptions[0].minAmount,550);
  assert.equal(notices.at(-1),'Service updated.');
  console.log('Restored prices remain a draft until Save; cancel/reopen, catalogue isolation and time preservation passed.');
}
main().catch(error=>{console.error(error);process.exitCode=1;});
