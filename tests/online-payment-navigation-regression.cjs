const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const stored = new Map();
let timer, navigated, applied = [], calls = 0;
const window = {
  location: {href: 'https://bethlehem.example/pages/admin/clinic.html?payment_context=clinic&gateway_payment=8&payable=3&payment_return=success', assign: url => {navigated = url;}},
  sessionStorage: {getItem: key => stored.get(key) || null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key)},
  history: {replaceState() {}},
};
const context = {window, URL, JSON, setTimeout: fn => {timer = fn; return 1;}, clearTimeout: () => {timer = null;}};
vm.runInNewContext(fs.readFileSync('scripts/components/online-payment.js', 'utf8'), context);
(async () => {
  const helper = window.OnlinePayment;
  assert.equal(helper.get('clinic').id, 8);
  assert.equal(helper.get('clinic').outcome, 'success');
  assert.equal(helper.get('grooming'), null);
  helper.open('clinic', {id: 8, status: 'pending', checkout_url: 'https://checkout.paymongo.com/cs_test'}, 3);
  assert.equal(navigated, 'https://checkout.paymongo.com/cs_test');
  navigated = null;
  helper.open('clinic', {id: 8, status: 'paid', checkout_url: 'https://checkout.paymongo.com/cs_test'}, 3);
  assert.equal(navigated, null, 'A payment confirmed during polling must not navigate away from its receipt');
  window.location.href = 'https://bethlehem.example/pages/admin/clinic.html';
  assert.equal(helper.get('clinic').id, 8, 'A refresh recovers the pending attempt');
  assert.equal(helper.get('clinic').payable, 3);
  let status = 'pending';
  const watch = helper.watch({load: async () => {calls++; return {online_payment: {status}};}, apply: response => applied.push(response.online_payment.status), error: err => {throw err;}, active: () => true});
  await watch.check();
  assert.equal(applied.at(-1), 'pending', 'Success return must wait for verified backend status');
  assert.equal(typeof timer, 'function');
  status = 'paid'; await timer();
  assert.equal(applied.at(-1), 'paid'); assert.equal(timer, null);
  await watch.check();
  watch.stop(); assert.equal(timer, null);
  helper.clear('clinic'); assert.equal(helper.get('clinic'), null);
  assert.equal(calls, 3);
  let finish, staleApplied = false;
  const stale = helper.watch({load: () => new Promise(resolve => {finish = resolve;}), apply: () => {staleApplied = true;}, error: err => {throw err;}, active: () => true});
  const inFlight = stale.check(); stale.stop();
  finish({online_payment: {status: 'pending'}}); await inFlight;
  assert.equal(staleApplied, false, 'A late poll cannot revive a closed or cancelled payment');
  assert.equal(timer, null);
  let boundedCalls = 0, exhausted = 0;
  const bounded = helper.watch({
    load: async () => {boundedCalls++; return {online_payment: {status: 'pending'}};},
    apply() {}, error: err => {throw err;}, active: () => true,
    maxRequests: 3, exhausted: () => {exhausted++;},
  });
  await bounded.check(); await timer(); await timer();
  assert.equal(boundedCalls, 3, 'Active checkouts have a finite request budget');
  assert.equal(timer, null); assert.equal(exhausted, 1);
  await bounded.check(); assert.equal(boundedCalls, 3);
  bounded.stop();
  let overlappingCalls = 0, resolveLoad;
  const overlapping = helper.watch({
    load: () => {overlappingCalls++; return new Promise(resolve => {resolveLoad = resolve;});},
    apply() {}, error: err => {throw err;}, active: () => true,
  });
  const first = overlapping.check(), second = overlapping.check();
  assert.equal(overlappingCalls, 1, 'Repeated Check Status actions share the in-flight request');
  resolveLoad({online_payment: {status: 'paid'}}); await Promise.all([first, second]);
  overlapping.stop();
  console.log('Shared payment navigation regression passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
