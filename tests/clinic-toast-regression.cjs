const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const stores = {}, timers = new Map();
let timerId = 0;
const context = {
  document: {addEventListener: (_, callback) => callback()},
  Alpine: {store: (name, store) => {stores[name] = store;}},
  setTimeout(callback, duration) {timers.set(++timerId, {callback, duration}); return timerId;},
  clearTimeout(id) {timers.delete(id);},
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(require('node:path').join(__dirname, '../scripts/components/clinic-record-presentation.js'), 'utf8'), context);
for (const file of ['clinic-feedback.js', 'admin-clinic.js', 'admin-vaccinations.js']) {
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../scripts/components', file), 'utf8'), context);
}

const feedback = stores.clinicFeedback;
assert.equal(feedback.toast.show, false);
for (const variant of ['success', 'error', 'warning', 'info']) {
  feedback.notify('Notification message.', variant);
  assert.equal(feedback.toast.variant, variant);
  assert.equal(feedback.toast.message, 'Notification message.');
  assert.equal(feedback.toast.show, true);
  assert.equal(timers.size, 1, 'A new notification cancels the previous dismissal');
  assert.equal([...timers.values()][0].duration, 3500, 'Keep the existing notification lifetime');
}
feedback.notify('Default success.');
assert.equal(feedback.toast.variant, 'success');
feedback.notify('Legacy success.', true);
assert.equal(feedback.toast.variant, 'success');
feedback.notify('Legacy failure.', false);
assert.equal(feedback.toast.variant, 'error');
[...timers.values()][0].callback();
assert.equal(feedback.toast.show, false);

const treatments = context.adminClinicVaccinations();
treatments.$store = {clinicFeedback: feedback};
treatments.showToast('Treatment added.');
assert.equal(feedback.toast.variant, 'success');
treatments.showToast('Request failed.', false);
assert.equal(feedback.toast.variant, 'error');
treatments.showToast('Information.', 'info');
assert.equal(feedback.toast.variant, 'info');
console.log('Clinic toast variants, boolean compatibility, replacement and dismissal passed');
