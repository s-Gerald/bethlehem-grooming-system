const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { window: { showSuccessToast() {}, showToast() {} }, document: { addEventListener() {} } };
vm.createContext(context);
for (const name of ['product-form', 'admin-clinic', 'admin-inventory-items', 'admin-stock-in'])
  vm.runInContext(fs.readFileSync(`scripts/components/${name}.js`, 'utf8'), context);
const pf = context.ProductForm = context.window.ProductForm;
const fixtures = JSON.parse(fs.readFileSync('tests/Fixtures/weight-rules.json'));
const clean = value => JSON.parse(JSON.stringify(value));
const range = (min_weight_kg, max_weight_kg, price = 50) => ({ min_weight_kg, max_weight_kg, price });
const validForm = (ranges, mode = 'catalog') => pf.empty({ item_name: 'Clinic medicine', category: 'medicine', medicine_type: 'deworming', unit: 'bottle', dose_unit: 'mL', unit_cost: '73.25', reorder_level: '0',
  ...pf.clinicFields({category:'medicine', medicine_type:'deworming', dose_unit:'mL', pricing_method:'weight_based', weight_pricing_mode:mode, weight_prices:ranges}) });
const detailsExpression = fs.readFileSync('pages/admin/inventory/sections/products.html', 'utf8').match(/x-text="(ProductForm.rangeLabel[^"]+)"/)[1];
for (const example of Object.values(fixtures.catalog_pricing)) {
  const form = validForm(example.ranges.map(values => range(...values)));
  assert.equal(pf.validate(form), ''); assert.equal(pf.checkWeightSchedule(form), true);
  const payload = pf.payload(form);
  assert.equal(payload.unit_cost, 73.25); assert.equal(payload.selling_price, null);
  assert.equal(payload.weight_pricing_mode, 'catalog');
  assert.equal(pf.validateRanges(payload.weight_prices), '');
  assert.deepEqual(clean(pf.payload({...form, ...pf.clinicFields(payload)})), clean(payload));
  assert.deepEqual(Array.from(payload.weight_prices, r => [Number(r.min_weight_kg), r.max_weight_kg == null ? null : Number(r.max_weight_kg), Number(r.price)]), example.ranges);
  for (const [weight, price] of example.examples) {
    const quote = context.clinicMedicinePrice(payload, weight);
    if (price === null) assert.match(quote.error, /No price is configured/);
    else assert.equal(quote.price, price);
  }
  for (let index = 0; index < payload.weight_prices.length; index++) {
    context.details = {item:payload}; context.range = payload.weight_prices[index]; context.index = index;
    assert.equal(vm.runInContext(detailsExpression, context), example.labels[index]);
  }
}
// Stored threshold schedules retain their interpretation and load into equivalent editable catalog rows.
for (const [name, example] of Object.entries(fixtures.pricing)) {
  const ranges = example.ranges.map(values => range(...values));
  const legacy = {pricing_method:'weight_based',weight_pricing_mode:'threshold',weight_prices:ranges};
  const converted = pf.payload(validForm(ranges, 'threshold'));
  // Existing lookup and conversion stay intact; shared normal boundaries must
  // now be corrected before an edited schedule can be saved.
  if (name === 'below_only') assert.equal(pf.validateRanges(converted.weight_prices), '');
  else assert.match(pf.validateRanges(converted.weight_prices), /Must be greater than/);
  for (const [weight, price] of example.examples) {
    assert.equal(context.clinicMedicinePrice(legacy, weight).price ?? null, price);
    assert.equal(context.clinicMedicinePrice(converted, weight).price ?? null, price);
  }
}
const historical = {category:'medicine', medicine_type:'deworming', dose_unit:'mL', pricing_method:'weight_based', weight_pricing_mode:'ranges', weight_prices:[range(1,4,250),range(5,10,300)]};
assert.match(context.clinicMedicinePrice(historical, 4.1).error, /No price is configured/);
const converted = validForm([]); Object.assign(converted, pf.clinicFields(historical));
assert.equal(pf.payload(converted).weight_prices[0].max_weight_kg, '4');
assert.equal(context.clinicMedicinePrice(pf.payload(converted), 4.99).price, 250);
assert.match(context.clinicMedicinePrice(pf.payload(converted), 10.01).error, /No price is configured/);
const missingMaximum = validForm([range(1,null,250)], 'legacy');
assert.equal(missingMaximum.weight_prices[0].band,'normal');
assert.equal(pf.checkWeightSchedule(missingMaximum),false);
assert.equal(missingMaximum.weight_prices[0].maximumError,'Enter a maximum weight.');
for (const bad of [[], [range(-1,5)], [range(1,0)], [range(1,1)], [range(5,4)],
  [range(1,5,0)], [range(1,5,-1)], [range(1,5,'abc')], [range(1,5,'1.001')],
  [range(1,6),range(5,10)], [range(1,4),range(1,5)], [range(6,10),range(1,5)],
  [range('1.000',5)], [range(1,'5.000')], [range('1e1',20)],
  [range(0,6),range(5,10)], [range(0,1),range(0,2)], [range(1,null),range(5,10)],
  [range(1,null),range(5,null)], [range(1,10),range(9,null)]]) assert.ok(pf.validateRanges(bad));
for (const minimum of [5,5.01,5.09])
  assert.equal(pf.validateRanges([range(1,5),range(minimum,6)]), 'Must be greater than 5 kg. Minimum allowed: 5.1 kg.');
assert.equal(pf.validateRanges([range(1,5),range(5.1,6)]), '');
assert.equal(pf.validateRanges([range(1,5),range(5.11,6)]), '');
for (const minimum of [5.99,6,6.08])
  assert.equal(pf.validateRanges([range(1,5.99),range(minimum,7)]), 'Must be greater than 5.99 kg. Minimum allowed: 6.09 kg.');
assert.equal(pf.validateRanges([range(1,5.99),range(6.09,7)]), '');
const decimalGapItem = {pricing_method:'weight_based',weight_pricing_mode:'catalog',
  weight_prices:[range(1,5,1750),range(5.1,6,1900)]};
for (const [weight, price] of [[5,1750],[5.01,1750],[5.09,1750],[5.1,1900],[5.11,1900]])
  assert.equal(context.clinicMedicinePrice(decimalGapItem, weight).price, price);
for (const [maximum, minimum] of [['5','5.1'],['5.99','6.09'],['1.13','1.23'],['0.09','0.19']]) {
  const draft = validForm([range('0.01',maximum)]);
  pf.addRange(draft);
  assert.equal(draft.weight_prices[1].min_weight_kg, minimum);
}
for (const maximum of ['', 'invalid', '5.999']) {
  const draft = validForm([range('1',maximum)]);
  pf.addRange(draft);
  assert.equal(draft.weight_prices[1].min_weight_kg, '');
}
const firstRow = validForm([]); pf.addRange(firstRow);
assert.equal(firstRow.weight_prices[0].min_weight_kg, '');
const belowRow = validForm([range(0,2)]); pf.addRange(belowRow);
assert.equal(belowRow.weight_prices[1].min_weight_kg, '');
const schedule = validForm([range('1','4','250'),range('5','10','300')]);
pf.addBelowRange(schedule); pf.addBelowRange(schedule);
assert.equal(schedule.weight_prices.filter(r=>r.band==='below').length,1);
schedule.weight_prices[0].price='200'; pf.addAboveRange(schedule); pf.addAboveRange(schedule);
assert.equal(schedule.weight_prices.filter(r=>r.band==='above').length,1);
assert.equal(schedule.weight_prices.at(-1).min_weight_kg,'10');
schedule.weight_prices.at(-1).price='400';
pf.addRange(schedule);
assert.deepEqual(Array.from(schedule.weight_prices,r=>r.band),['below','normal','normal','normal','above']);
const newRow=schedule.weight_prices[3];
assert.equal(newRow.min_weight_kg,'10.1');
assert.equal(newRow.weightError,undefined); assert.equal(newRow.maximumError,undefined); assert.equal(newRow.priceError,undefined);
assert.equal(pf.checkWeightSchedule(schedule),false);
assert.equal(newRow.weightError,''); assert.equal(newRow.maximumError,'Enter a maximum weight.'); assert.equal(newRow.priceError,'Enter a price.');
newRow.min_weight_kg='11.555'; newRow.max_weight_kg='15'; newRow.price='350';
assert.equal(pf.weightFieldError(schedule,3,'min'),'Use no more than 2 decimal places.');
assert.equal(newRow.min_weight_kg,'11.555');
newRow.min_weight_kg='11.'; schedule.weight_prices.at(-1).min_weight_kg='15';
assert.equal(pf.checkWeightSchedule(schedule),true);
newRow.max_weight_kg='11'; assert.equal(pf.weightFieldError(schedule,3,'max'),'Maximum must be greater than minimum.');
newRow.max_weight_kg='15';
schedule.weight_prices[0].min_weight_kg='2'; assert.ok(pf.weightFieldError(schedule,0,'min'));
schedule.weight_prices[0].min_weight_kg='1';
pf.removeRange(schedule,1); assert.equal(schedule.weight_prices[0].min_weight_kg,'1'); // Independent Below threshold is preserved.
assert.equal(pf.checkWeightSchedule(schedule),true);
assert.ok(!Object.hasOwn(pf.empty(),'final_range')); assert.ok(!Object.hasOwn(pf.empty(),'weight_limit'));
for (const file of ['pages/admin/inventory/sections/products.html','pages/admin/inventory/sections/stock-in.html']) {
  const html=fs.readFileSync(file,'utf8');
  for (const text of ['Unit Cost','Weight-based Pricing','inventory-weight-badge','From','To','Price ₱','Add weight range','Add Below Range','Add Above Range']) assert.ok(html.includes(text));
  const card = html.match(/<section x-show="ProductForm.isWeightBased[\s\S]+?<\/section>/)[0];
  assert.doesNotMatch(card,/Minimum \(kg\)|Maximum \(kg\)|Price \(₱\)/);
  assert.match(html,/:aria-describedby="'(?:form|createForm)-weight-error-' \+ index"/);
  assert.match(html,/:aria-describedby="'(?:form|createForm)-maximum-error-' \+ index"/);
  assert.match(card,/<\/label>\s*<p :id="'(?:form|createForm)-weight-error-'/);
  assert.match(html,/\$nextTick\(\(\) => ProductForm.focusLastThreshold/);
  assert.match(html,/@blur=/); assert.match(html,/type="text" inputmode="decimal"/);
  assert.doesNotMatch(html,/No maximum|final_range|weight_limit|scheduleLabel|touched|Remove tier|Lowest weight tier|Price tier [0-9]|Dog Pricing|Cat Pricing/);
}
(async()=>{
  let created,updated;
  context.InventoryAPI={createItem:async payload=>{created=clean(payload);return {data:{...payload,item_id:1}};},updateItem:async(id,payload)=>{assert.equal(id,1);updated=clean(payload);return {data:payload};}};
  const page=context.adminInventoryItems(); page.$nextTick=()=>{};page.load=async()=>{};page.showToast=()=>{};
  for (const item_id of [null, 1]) for (const minimum of [5,5.01,5.09]) {
    page.form = validForm([range(1,5,1750),range(minimum,6,1900)]); page.form.item_id = item_id;
    page.modal.open = true; created = updated = null;
    await page.submitForm();
    assert.equal(created,null); assert.equal(updated,null); assert.equal(page.modal.open,true);
    assert.equal(page.form.weight_prices[1].weightError,'Must be greater than 5 kg. Minimum allowed: 5.1 kg.');
  }
  page.openAdd();Object.assign(page.form,validForm([range(0,1,200),range(1,4,250),range(5,10,300),range(10,null,400)]));
  await page.submitForm(); assert.equal(page.modal.open,false);assert.equal(created.weight_prices[1].max_weight_kg,'4');
  assert.equal(created.weight_prices[3].band,'above');assert.equal(created.weight_prices[3].max_weight_kg,null);
  page.openEdit({...created,item_id:1,unit_cost:'73.25',reorder_level:'0.00'});
  page.form.weight_prices[1].max_weight_kg='4.50';await page.submitForm();
  assert.equal(updated.weight_prices[1].max_weight_kg,'4.50');assert.equal(updated.unit_cost,73.25);
  page.openEdit({...created,item_id:1,reorder_level:'0'});page.form.weight_prices[1].max_weight_kg='';updated=null;
  await page.submitForm();assert.equal(updated,null);assert.equal(page.modal.error,'');assert.equal(page.form.weight_prices[1].maximumError,'Enter a maximum weight.');
  page.form.weight_prices[1].max_weight_kg='4.500';await page.submitForm();assert.equal(updated,null);assert.equal(page.form.weight_prices[1].max_weight_kg,'4.500');
  assert.equal(page.form.weight_prices[1].maximumError,'Use no more than 2 decimal places.');
  const stock=context.adminStockIn();stock.$nextTick=()=>{};
  for (const minimum of [5,5.01,5.09]) {
    stock.createForm=validForm([range(1,5,1750),range(minimum,6,1900)]);created=null;
    await stock.createAndSelect();assert.equal(created,null);
    assert.equal(stock.createForm.weight_prices[1].weightError,'Must be greater than 5 kg. Minimum allowed: 5.1 kg.');
  }
  stock.createForm=validForm([range(31,40,650),range(40,null,800)]);await stock.createAndSelect();
  assert.equal(stock.selected.weight_prices[0].max_weight_kg,'40');assert.equal(stock.selected.unit_cost,73.25);assert.equal(stock.unitCost,73.25);
  stock.openCreateForm();Object.assign(stock.createForm,validForm([range(1,4,250)]));pf.addRange(stock.createForm);created=null;
  await stock.createAndSelect();assert.equal(created,null);assert.equal(stock.createError,'');assert.equal(stock.createForm.weight_prices[1].maximumError,'Enter a maximum weight.');
  console.log('Catalog schedules: decimal lookup, Below/Above, caps, ordering, Add/Edit/Stock In, Product Details and Unit Cost passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
