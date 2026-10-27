import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { clearGroomingDraft } from '../scripts/services/booking-draft-service.js';

async function harness({ allowed = true, failure = false, phoneRequired = false, unavailable = [], deferAccess = false } = {}) {
  const timers = new Map(), windowListeners = {};
  let timerId = 0;
  const nodes = new Map(), drafts = new Map([['bookingPets', 'saved grooming draft'], ['clinicVisitDraft', 'saved clinic draft']]);
  const get = id => {
    if (!nodes.has(id)) nodes.set(id, {
      id, attributes: {}, listeners: {}, textContent: '', hidden: false,
      classes: new Set(),
      classList: {
        add(name) { nodes.get(id).classes.add(name); },
        remove(name) { nodes.get(id).classes.delete(name); },
        toggle(name, enabled) { if (enabled) this.add(name); else this.remove(name); },
      },
      setAttribute(key, value) { this.attributes[key] = String(value); },
      removeAttribute(key) { delete this.attributes[key]; },
      getAttribute(key) { return this.attributes[key]; },
      addEventListener(name, handler) { this.listeners[name] = handler; },
      focus() { this.focused = true; },
      animate() { this.animations = (this.animations || 0) + 1; },
    });
    return nodes.get(id);
  };
  let requests = 0, guarded;
  const pending = [];
  const accessPending = [];
  const context = vm.createContext({ console,
    document: { getElementById: get },
    window: {
      matchMedia: () => ({ matches: true }),
      setTimeout: (callback, delay) => { timers.set(++timerId, { callback, delay }); return timerId; },
      clearTimeout: id => timers.delete(id),
      addEventListener: (name, callback) => { windowListeners[name] = callback; },
    },
    sessionStorage: { getItem: key => drafts.get(key), setItem: (k, v) => drafts.set(k, v), removeItem: k => drafts.delete(k) },
    requireCustomerSession: () => false,
    clearGroomingDraft: () => { globalThis.sessionStorage = context.sessionStorage; clearGroomingDraft(); },
    bindPreRegistrationButton: button => { guarded = button; }, setPhoneVerificationAccess() {},
    setPreRegistrationLinkAccess: (button, enabled) => button.setAttribute('aria-disabled', !enabled),
    getPreRegistrationAccess: () => {
      const data = { allowed, phone_verification_required: phoneRequired, sms_verification: { enforced: true }, message: allowed ? null : 'You already have an ongoing registration.' };
      return deferAccess ? new Promise(resolve => accessPending.push(() => resolve(data))) : Promise.resolve(data);
    },
    loadRegistrationAvailability: service => { requests++; return new Promise((resolve, reject) => {
      const finish = () => failure ? reject(Error('offline')) : resolve({
      service_day: service === 'grooming' ? '2026-10-28' : '2026-10-27', registration_available: !unavailable.includes(service),
      visit_day_label: service === 'grooming' ? 'Tomorrow, Wed, Oct 28, 2026' : 'Today, Tue, Oct 27, 2026', check_in_starts: '8:00 AM', message: 'Online Registration available',
      });
      finish.service = service;
      pending.push(finish);
    }); },
  });
  const source = fs.readFileSync(new URL('../scripts/components/pre-registration-options.js', import.meta.url), 'utf8')
    .replace(/^import\s+[\s\S]*?from\s+"[^"]+";\s*/gm, '').replace(/^export /gm, '');
  await vm.runInContext(`(async () => { ${source}; this.initPreRegistrationOptions = typeof initPreRegistrationOptions === "function" ? initPreRegistrationOptions : undefined; })()`, context);
  return { get, context, pending, drafts, timers, windowListeners, accessPending,
    setFailure(value) { failure = value; },
    revealSkeleton() { for (const [id, timer] of timers) { assert.equal(timer.delay, 180); timers.delete(id); timer.callback(); } },
    get requests() { return requests; }, get guarded() { return guarded; } };
}

const h = await harness();
const ready = h.context.initPreRegistrationOptions();
assert.equal(h.get('groomingTab').attributes['aria-selected'], 'true');
assert.equal(h.get('serviceContinue').attributes['aria-disabled'], 'true');
assert.equal(h.get('serviceInformation').attributes['aria-busy'], 'true');
assert.equal(h.get('serviceContent').hidden, true, 'The entire card content is hidden while required data loads');
assert.equal(h.get('serviceSkeleton').hidden, false);
assert.equal(h.get('serviceSkeleton').classes.has('opacity-0'), true, 'Very fast requests do not flash placeholders');
h.revealSkeleton();
assert.equal(h.get('serviceSkeleton').classes.has('opacity-0'), false);
assert.equal(h.get('serviceLoadingAnnouncement').textContent, 'Loading service information.');
h.get('clinicTab').listeners.click();
assert.equal(h.get('clinicTab').attributes['aria-selected'], 'true');
h.pending.reverse().forEach(resolve => resolve());
await ready;
assert.equal(h.get('serviceContent').hidden, false);
assert.equal(h.get('serviceSkeleton').hidden, true);
assert.equal(h.get('serviceInformation').attributes['aria-busy'], 'false');
assert.equal(h.get('serviceTitle').textContent, 'Clinic Visit', 'Late Grooming responses cannot overwrite the selected service');
assert.equal(h.get('serviceVisitDay').textContent, 'Today, Tue, Oct 27, 2026');
assert.equal(h.get('serviceContinue').href, './booking-pet-details.html?flow=clinic');
assert.equal(h.get('serviceContinue').attributes['aria-disabled'], 'false');
const key = key => ({ key, preventDefault() {} });
h.get('clinicTab').listeners.keydown(key('ArrowRight'));
assert.equal(h.get('groomingTab').attributes['aria-selected'], 'true');
assert.equal(h.get('groomingTab').focused, true);
assert.match(h.get('serviceNote').textContent, /grooming cage/);
h.get('groomingTab').listeners.keydown(key('End'));
assert.equal(h.get('clinicTab').attributes['tabindex'], '0');
assert.equal(h.get('groomingTab').attributes['tabindex'], '-1');
assert.doesNotMatch(h.get('serviceNote').textContent, /cage/);
h.get('clinicTab').listeners.keydown(key('Home'));
assert.equal(h.requests, 2, 'Switching services does not fetch again');
assert.equal(h.timers.size, 0, 'Switching loaded services never schedules a skeleton');
assert.equal(h.guarded, h.get('serviceContinue'), 'Continue reuses the phone verification guard');
assert.deepEqual([...h.drafts.values()], ['saved grooming draft', 'saved clinic draft']);
h.drafts.set('bookingConfirmation', 'previously submitted visit');
h.get('serviceContinue').listeners.click();
assert.equal(h.drafts.has('bookingPets'), false, 'A previously submitted draft is cleared only when starting the next Grooming registration');
assert.equal(h.drafts.get('clinicVisitDraft'), 'saved clinic draft');

const blocked = await harness({ allowed: false });
const blockedReady = blocked.context.initPreRegistrationOptions();
blocked.pending.forEach(resolve => resolve());
await blockedReady;
blocked.get('clinicTab').listeners.click();
assert.equal(blocked.get('serviceContinue').attributes['aria-disabled'], 'true');
assert.equal(blocked.get('clinicTab').attributes['aria-selected'], 'true');
assert.match(blocked.get('serviceAccessMessage').textContent, /ongoing registration/);

const offline = await harness({ failure: true });
const offlineReady = offline.context.initPreRegistrationOptions();
offline.pending.forEach(resolve => resolve());
await offlineReady;
assert.equal(offline.get('serviceContinue').attributes['aria-disabled'], 'true');
assert.match(offline.get('serviceStatus').textContent, /Unable to check availability/);
assert.equal(offline.get('retryAvailability').hidden, false);
assert.equal(offline.get('serviceSkeleton').hidden, true, 'Failure must replace the skeleton');
assert.equal(offline.get('serviceContent').hidden, false);
offline.get('clinicTab').listeners.click();
assert.equal(offline.get('clinicTab').attributes['aria-selected'], 'true');
console.log('Registration tabs: default selection, keyboard/focus, guarded Continue, drafts, response ordering and errors passed.');

const phone = await harness({ phoneRequired: true });
const phoneReady = phone.context.initPreRegistrationOptions();
phone.pending.forEach(resolve => resolve());
await phoneReady;
assert.match(phone.get('serviceAccessMessage').textContent, /Phone verification/);
assert.equal(phone.get('serviceContinue').attributes['aria-disabled'], 'false', 'Continue must remain available to open phone verification');
const unavailable = await harness({ unavailable: ['grooming'] });
const unavailableReady = unavailable.context.initPreRegistrationOptions();
unavailable.pending.forEach(resolve => resolve());
await unavailableReady;
assert.equal(unavailable.get('serviceContinue').attributes['aria-disabled'], 'true');
unavailable.get('clinicTab').listeners.click();
assert.equal(unavailable.get('serviceContinue').attributes['aria-disabled'], 'false', 'An unavailable service must not block the other service');

const independent = await harness();
const independentReady = independent.context.initPreRegistrationOptions();
independent.pending.find(p => p.service === 'grooming')();
await new Promise(resolve => setImmediate(resolve));
assert.equal(independent.get('serviceContent').hidden, false, 'A loaded service does not wait for the other service');
assert.equal(independent.get('serviceContinue').attributes['aria-disabled'], 'false');
assert.equal(independent.timers.size, 0, 'A fast response cancels its display delay immediately');
independent.get('clinicTab').listeners.click();
independent.revealSkeleton();
assert.equal(independent.get('serviceSkeleton').hidden, false);
assert.equal(independent.get('serviceContent').hidden, true);
independent.get('groomingTab').listeners.click();
assert.equal(independent.get('serviceSkeleton').hidden, true, 'Already loaded information is rendered immediately');
independent.pending.find(p => p.service === 'clinic')();
await independentReady;
assert.equal(independent.get('serviceTitle').textContent, 'Grooming');
assert.equal(independent.requests, 2);
assert.equal(independent.get('serviceInformation').animations || 0, 0, 'Reduced motion disables card fades');

const access = await harness({ deferAccess: true });
const accessReady = access.context.initPreRegistrationOptions();
access.pending.forEach(resolve => resolve());
await new Promise(resolve => setImmediate(resolve));
assert.equal(access.get('serviceContinue').attributes['aria-disabled'], 'true', 'Availability alone never bypasses the access check');
assert.equal(access.get('serviceContent').hidden, true);
access.accessPending[0]();
await accessReady;
assert.equal(access.get('serviceContent').hidden, false);

offline.setFailure(false);
const retry = offline.get('retryAvailability').listeners.click();
offline.pending.slice(-2).forEach(resolve => resolve());
await retry;
assert.equal(offline.get('serviceContinue').attributes['aria-disabled'], 'false');
assert.equal(offline.get('retryAvailability').hidden, true);
assert.equal(offline.get('serviceSkeleton').hidden, true);

const inactive = await harness();
const inactiveReady = inactive.context.initPreRegistrationOptions();
inactive.windowListeners.pagehide();
assert.equal(inactive.timers.size, 0, 'Leaving the page cancels the display timer');
inactive.pending.forEach(resolve => resolve());
await inactiveReady;
inactive.windowListeners.pageshow();
assert.equal(inactive.get('serviceContent').hidden, false, 'Restoring the page uses the settled local data');
assert.equal(inactive.requests, 2);

const partial = await harness({ failure: true });
const partialReady = partial.context.initPreRegistrationOptions();
partial.pending.find(p => p.service === 'grooming')();
partial.setFailure(false);
partial.pending.find(p => p.service === 'clinic')();
await partialReady;
const partialRetry = partial.get('retryAvailability').listeners.click();
assert.equal(partial.requests, 3, 'Retry requests only the failed service');
partial.get('clinicTab').listeners.click();
assert.equal(partial.get('serviceContinue').attributes['aria-disabled'], 'false', 'The loaded service remains usable while another service retries');
partial.pending.at(-1)();
await partialRetry;
assert.equal(partial.get('serviceTitle').textContent, 'Clinic Visit');
assert.equal(partial.get('serviceSkeleton').hidden, true);
console.log('Full-card skeleton, fast completion, independent service readiness, retry, timer cleanup and reduced motion passed.');
