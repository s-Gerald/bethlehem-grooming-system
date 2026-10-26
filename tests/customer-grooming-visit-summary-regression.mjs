import assert from 'node:assert/strict';
import { groomingVisitPresentation, openGroomingVisitSummary } from '../scripts/components/grooming-visit-summary.js?grooming=actual-20261026';

const booking = {
  booking_id: 71, booking_reference: 'BAC-71', status: 'waiting_to_arrive', paid: false,
  booking_date: '2026-10-17', time_window: { window_label: '9:00 AM - 9:30 AM' },
  booking_pets: [{ booking_pet_id: 8, pet: { pet_name: 'Coco <3', breed: 'Shih Tzu', weight: 0 },
    registered_size: 'small', groomer: { first_name: 'Anna' }, special_instructions: 'Sensitive paws',
    grooming_preference_label: 'Regular Trim', grooming_start_time:'2026-10-17T09:00:00+08:00', grooming_end_time:'2026-10-17T11:18:00+08:00' }],
  booking_services: [
    { booking_pet_id: 8, service: { kind: 'package', service_name: 'Regular Dog Grooming' }, price_at_booking: '550', price_min_at_booking: '550', price_max_at_booking: '550' },
    { booking_pet_id: 8, service: { kind: 'ala_carte', service_name: 'Medicated Bath' }, price_at_booking: '150', price_min_at_booking: '150', price_max_at_booking: '150' },
  ],
  consent: { grooming_consent: true, sedation_consent: false, signer_name: 'Alex Cruz', signed_at: '2026-10-17T00:30:00Z' },
};
// Any accidental read of the original confirmation must fail this feature.
globalThis.sessionStorage = { getItem() { throw new Error('Session confirmation must not be read'); } };
const view = groomingVisitPresentation(booking, 'Waiting to arrive');
assert.match(view.html, /Coco &lt;3/);
assert.match(view.html, /Grooming package[\s\S]*Regular Dog Grooming/);
assert.match(view.html, /A la Carte[\s\S]*Medicated Bath/);
assert.match(view.html, /Sensitive paws/);
assert.match(view.html, /0 kg/);
assert.match(view.html, /Not authorized/);
assert.match(view.html, /Alex Cruz/);
assert.match(view.html, /8:30 AM/);
for (const output of [view.html, view.print]) {
  assert.equal(output.split('₱700').length - 1, 1, 'Saved service prices appear as one total');
}
assert.match(view.print, /confirmation-brand-header/);
const rangeBooking = structuredClone(booking);
rangeBooking.booking_services[1].price_max_at_booking = '250';
assert.match(groomingVisitPresentation(rangeBooking, 'Waiting to arrive').html, /₱700 - ₱800/);
rangeBooking.booking_services[1].price_max_at_booking = null;
assert.match(groomingVisitPresentation(rangeBooking, 'Waiting to arrive').html, /₱700\+/);
rangeBooking.paid = true;
rangeBooking.total_amount = '725';
assert.match(groomingVisitPresentation(rangeBooking, 'Checked In').html, /₱725/);
assert.doesNotMatch(groomingVisitPresentation(rangeBooking, 'Checked In').html, /₱700/);
const legacy = groomingVisitPresentation({ status: 'checked_in', booking_pets: [{ pet: { pet_name: 'Older visit' } }] }, 'Checked In');
assert.match(legacy.html, /Not recorded/);
assert.doesNotMatch(legacy.html, /Agreed|Authorized|Alex Cruz|when you arrive/);
const multi = structuredClone(booking);
multi.booking_pets.push({ booking_pet_id: 9, pet: { pet_name: 'Luna' } });
multi.booking_services.push({ booking_pet_id: 9, service: { kind: 'ala_carte', service_name: 'Nail Clipping' }, price_at_booking: '100' });
const multiView = groomingVisitPresentation(multi, 'Waiting to arrive');
assert.match(multiView.html, /Luna<\/h3>[\s\S]*Nail Clipping/);
assert.equal(multiView.html.split('₱800').length - 1, 1);

class Element {
  innerHTML = ''; disabled = false; open = false; isConnected = true; listeners = {}; attrs = {};
  constructor(id) { this.id = id; }
  addEventListener(type, listener) { (this.listeners[type] ??= []).push(listener); }
  emit(type, event) { for (const listener of this.listeners[type] || []) listener(event); }
  querySelectorAll() { return ['closeVisitSummary', 'dismissVisitSummary', 'printVisitSummary'].map(element).filter(node => !node.disabled); }
  setAttribute(name, value) { this.attrs[name] = value; }
  focus() { document.activeElement = this; }
  showModal() { this.open = true; }
  close() { this.open = false; this.emit('close'); }
}
const elements = new Map();
const element = id => { if (!elements.has(id)) elements.set(id, new Element(id)); return elements.get(id); };
const bodyClasses = new Set();
globalThis.document = { title: 'Customer dashboard', getElementById: element,
  body: { classList: { add: name => bodyClasses.add(name), remove: name => bodyClasses.delete(name) } } };
let printed = '';
const windowListeners = new Map();
globalThis.window = {
  addEventListener: (type, listener) => windowListeners.set(type, listener),
  removeEventListener: type => windowListeners.delete(type), setTimeout() {},
  print() {
    assert.equal(bodyClasses.has('grooming-summary-printing'), true);
    printed = element('groomingVisitPrint').innerHTML;
    windowListeners.get('afterprint')?.();
  },
};
const trigger = element('visit-summary-71');
const modal = element('groomingVisitSummary');
const content = element('groomingVisitSummaryContent');
const printButton = element('printVisitSummary');
let loadedIds = [];
globalThis.API = { async getBooking(id) { loadedIds.push(id); return { booking }; } };
await openGroomingVisitSummary(71, trigger, () => 'Waiting to arrive');
assert.deepEqual(loadedIds, [71]);
assert.equal(modal.open, true);
assert.equal(document.activeElement.id, 'closeVisitSummary');
assert.equal(content.attrs['aria-busy'], 'false');
assert.match(content.innerHTML, /BAC-71/);
assert.equal(printButton.disabled, false);
let tabPrevented = false;
modal.emit('keydown', { key: 'Tab', shiftKey: true, preventDefault() { tabPrevented = true; } });
assert.equal(document.activeElement, printButton, 'Shift+Tab at the first control wraps inside the modal');
assert.equal(tabPrevented, true);
modal.emit('keydown', { key: 'Tab', shiftKey: false, preventDefault() {} });
assert.equal(document.activeElement.id, 'closeVisitSummary');
printButton.emit('click');
assert.match(printed, /BAC-71/);
assert.equal(document.title, 'Customer dashboard');
assert.equal(bodyClasses.has('grooming-summary-printing'), false);
element('dismissVisitSummary').emit('click');
assert.equal(modal.open, false);
assert.equal(document.activeElement, trigger);
assert.equal(bodyClasses.has('grooming-summary-open'), false);

// Failed reads cannot print stale details from a previously opened visit.
API.getBooking = async () => { throw new Error('Network unavailable'); };
await openGroomingVisitSummary(72, trigger, () => 'Checked In');
assert.match(content.innerHTML, /Unable to load/);
assert.equal(printButton.disabled, true);
assert.equal(element('groomingVisitPrint').innerHTML, '');
element('closeVisitSummary').emit('click');

// Closing during a slow request must not populate the next visit with stale data.
let resolveOld;
API.getBooking = () => new Promise(resolve => { resolveOld = resolve; });
const oldRequest = openGroomingVisitSummary(73, trigger, () => 'Checked In');
modal.emit('keydown', { key: 'Tab', shiftKey: true, preventDefault() {} });
assert.equal(document.activeElement.id, 'dismissVisitSummary', 'Loading focus trap skips the disabled print control');
modal.close();
API.getBooking = async () => ({ booking: { ...booking, booking_reference: 'BAC-74' } });
await openGroomingVisitSummary(74, trigger, () => 'Checked In');
resolveOld({ booking });
await oldRequest;
assert.match(content.innerHTML, /BAC-74/);
assert.doesNotMatch(content.innerHTML, /BAC-71/);
trigger.isConnected = false;
const replacementTrigger = new Element(trigger.id);
elements.set(trigger.id, replacementTrigger);
modal.close();
assert.equal(document.activeElement, replacementTrigger, 'Focus returns to a trigger replaced by dashboard polling');
console.log('Customer grooming visit summary regression tests passed.');

assert.match(view.html, /Actual Grooming Duration[\s\S]*2 hr 18 min/);
const missing = structuredClone(booking); delete missing.booking_pets[0].grooming_end_time;
assert.doesNotMatch(groomingVisitPresentation(missing, 'Grooming in Progress').html, /Actual Grooming Duration/);

const overlapping = structuredClone(booking);
overlapping.booking_pets.push({booking_pet_id:9,pet:{pet_name:'Luna'},grooming_start_time:'2026-10-17T10:00:00+08:00',grooming_end_time:'2026-10-17T11:00:00+08:00'});
const actual = groomingVisitPresentation(overlapping, 'Completed').html;
assert.equal((actual.match(/Actual Grooming Duration/g)||[]).length, 2);
assert.match(actual, /2 hr 18 min/);
assert.match(actual, /1 hr/);
assert.doesNotMatch(actual, /3 hr 18 min/);

// Shared metadata stays out of pet sections; source and independent pet progress survive print.
const compact = structuredClone(multi);
compact.status = 'checked_in'; compact.booking_type = 'online'; compact.queue_number = 12;
compact.created_at = '2026-10-17T00:30:00Z';
compact.user = { first_name: 'Alex', last_name: 'Cruz', phone: '09171234567', email: 'alex@example.test' };
compact.booking_pets[0].pet_queue_number = 12;
compact.booking_pets[1].pet_queue_number = 13;
compact.booking_pets[1].grooming_state = 'not_started';
const compactView = groomingVisitPresentation(compact, 'Grooming in Progress');
assert.equal(compactView.title, 'Grooming Online Registration Summary');
for (const output of [compactView.html, compactView.print]) {
  assert.match(output, /Entry source<\/dt><dd>Online Registration/);
  assert.match(output, /Submitted online/);
  assert.match(output, /Queue #12/); assert.match(output, /Queue #13/);
  assert.match(output, /Grooming Finished/); assert.match(output, /Checked In/);
  assert.doesNotMatch(output, /<h2>Grooming details|<h2>Pet details|None provided|Not specified/);
  assert.equal((output.match(/Registration reference/g) || []).length, 1);
  assert.equal((output.match(/Visit day/g) || []).length, 1);
  assert.equal((output.match(/Sedation consent/g) || []).length, 1);
}
assert.match(compactView.print, /Alex Cruz/); assert.match(compactView.print, /alex@example.test/);
assert.equal(groomingVisitPresentation({ ...compact, booking_type: 'walk_in' }, 'Checked In').title, 'Grooming Walk-in Summary');
assert.doesNotMatch(groomingVisitPresentation({ ...compact, booking_type: 'walk_in' }, 'Checked In').print, /Online Registration|Submitted online/);

const petSections = [...compactView.html.matchAll(/<section class="grooming-summary-pet confirmation-pet">([\s\S]*?)<\/section>/g)].map(match => match[1]);
assert.match(petSections[0], /Regular Dog Grooming/); assert.match(petSections[0], /₱700/);
assert.doesNotMatch(petSections[0], /Nail Clipping/);
assert.match(petSections[1], /Nail Clipping/); assert.match(petSections[1], /₱100/);
assert.doesNotMatch(petSections[1], /Medicated Bath/);
