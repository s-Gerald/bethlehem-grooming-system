import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Small DOM boundary, matching the repository's Node regression conventions.
class Element {
  constructor(tag = 'div', id = '') {
    this.tagName = tag; this.id = id; this.children = []; this.attributes = {};
    this.listeners = {}; this.hidden = false; this.disabled = false; this.textContent = '';
    const classes = new Set();
    this.classList = {
      contains: name => classes.has(name),
      toggle(name, force = !classes.has(name)) { force ? classes.add(name) : classes.delete(name); },
      add: name => classes.add(name), remove: name => classes.delete(name),
    };
    this.style = {};
  }
  append(...nodes) { nodes.forEach(node => { node.parent = this; this.children.push(node); }); }
  replaceChildren(...nodes) { this.children = []; this.append(...nodes); }
  insertBefore(node, reference) {
    if (node.parent) node.parent.children.splice(node.parent.children.indexOf(node), 1);
    this.children.splice(this.children.indexOf(reference), 0, node);
    node.parent = this;
    if (node.contains(this.document?.activeElement)) this.document.activeElement = null;
  }
  setAttribute(key, value) { this.attributes[key] = String(value); }
  getAttribute(key) { return this.attributes[key] ?? null; }
  hasAttribute(key) { return key in this.attributes; }
  addEventListener(name, fn) { this.listeners[name] = fn; }
  contains(node) { return node === this || this.children.some(child => child.contains?.(node)); }
  closest(selector) { return (selector.startsWith('[') ? this.hasAttribute(selector.slice(1, -1)) : this.tagName === selector) ? this : this.parent?.closest(selector) ?? null; }
  querySelectorAll(selector) { return walk(this).filter(node => node !== this && (selector.startsWith('.') ? node.classList.contains(selector.slice(1)) : node.hasAttribute(selector.slice(1, -1)))); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
  focus() { this.focused = true; if (this.document) this.document.activeElement = this; }
}
const walk = node => [node, ...node.children.flatMap(walk)];
const content = node => [node.textContent, ...node.children.map(content)].join(' ');
const read = path => fs.existsSync(new URL(path, import.meta.url)) ? fs.readFileSync(new URL(path, import.meta.url), 'utf8') : '';
const source = read('../scripts/landing.js')
  .replace(/^import .*;\s*$/gm, '');
const formatters = read('../scripts/services/grooming-price-format.js')
  .replace(/^export /gm, '');
const option = (sizeKey, minAmount, pricingType = 'fixed', maxAmount = minAmount) =>
  ({sizeKey, label: sizeKey, minAmount, pricingType, maxAmount});
const packages = [
  {id: 'partial_grooming', name: 'Partial Grooming', petType: 'dog', descriptionItems: ['<script>unsafe</script>', 'Ear cleaning'],
    priceOptions: [option('small', 450), option('medium', 550, 'range', 650)]},
  {id: 'bath_and_go', name: 'Bath and Go!', petType: 'dog', descriptionItems: ['Bath'],
    priceOptions: [option('medium', 499.50), option('large', 700, 'starting_at', null)]},
  {id: 'cat_full_grooming', name: 'Cat Full Grooming', petType: 'cat', descriptionItems: ['Coat care'],
    priceOptions: [option('small', 600), option('medium', 800)]},
];

function harness(fetcher = async () => ({ok: true, json: async () => ({data: packages})})) {
  const ids = new Map(['landing-header', 'menu-toggle', 'section-links', 'pricing-status', 'pricing-retry',
    'pricing-content', 'package-grid', 'dog-pricing', 'cat-pricing'].map(id => [id, new Element('div', id)]));
  const doc = new Element('document');
  const clinic = new Element();
  clinic.classList.add('clinic-grid');
  clinic.setAttribute('data-accordion-group', '');
  clinic.document = doc;
  for (const id of ['surgery', 'treatment', 'vaccination', 'confinement', 'xray', 'consultation', 'laboratory', 'ultrasound']) {
    const card = new Element('article', id);
    card.setAttribute('data-accordion-item', '');
    const button = new Element('button', `${id}-trigger`);
    button.document = doc;
    button.setAttribute('data-accordion-trigger', '');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-controls', `clinic-${id}`);
    const detail = new Element('div', `clinic-${id}`);
    detail.hidden = true;
    card.append(button, detail);
    clinic.append(card);
  }
  doc.append(clinic);
  doc.baseURI = 'https://clinic.example/subdirectory/';
  doc.getElementById = id => ids.get(id) || walk(doc).find(node => node.id === id);
  doc.createElement = tag => new Element(tag);
  doc.createElementNS = (ns, tag) => new Element(tag);
  ids.get('menu-toggle').setAttribute('aria-expanded', 'false');
  ids.get('package-grid').setAttribute('data-accordion-group', '');
  ids.get('landing-header').append(ids.get('menu-toggle'), ids.get('section-links'));
  doc.append(ids.get('landing-header'), ...[...ids.values()].filter(node => !doc.contains(node)));
  const media = { matches: false, addEventListener(name, fn) { this.listener = fn; } };
  const tablet = { ...media, matches: true };
  const desktop = { ...media, matches: true };
  let requests = 0;
  vm.runInNewContext(`${formatters}\n${source}`, { document: doc, window: {matchMedia: query => query.includes('40rem') ? tablet : query.includes('64rem') ? desktop : media},
    fetch: (...args) => { requests++; return fetcher(...args); }, URL, console });
  return {doc, ids, media, tablet, desktop, clinic, requests: () => requests};
}
const settle = () => new Promise(resolve => setImmediate(resolve));

const page = harness(async (url, options) => {
  assert.equal(String(url), 'https://clinic.example/subdirectory/api/public/grooming/packages');
  assert.equal(options.cache, 'no-store');
  assert.equal(options.credentials, 'omit');
  return {ok: true, json: async () => ({data: packages})};
});
await settle();
assert.equal(page.requests(), 1);
const cards = page.ids.get('package-grid');
assert.match(content(cards), /Starts at ₱450/);
assert.match(content(cards), /Starts at ₱499.5/);
assert.equal(walk(cards).find(node => node.className === 'package-name' && node.textContent.includes('Cat')).textContent, 'Full Grooming — Cat');
assert.equal(page.ids.get('pricing-content').hidden, false);
const dog = page.ids.get('dog-pricing');
assert.match(content(dog), /₱550–₱650/);
assert.match(content(dog), /₱700\+/);
assert.ok(!content(dog).includes('Extra Large'));
assert.ok(!content(page.ids.get('cat-pricing')).includes('Large'));
assert.ok(content(dog).includes('Unavailable'));
assert.equal(walk(cards).filter(node => node.tagName === 'script').length, 0);
assert.ok(content(cards).includes('<script>unsafe</script>'), 'Catalogue content is literal text');

const triggers = cards.querySelectorAll('[data-accordion-trigger]');
const click = target => page.doc.listeners.click({target});
click(triggers[0]);
assert.equal(triggers[0].getAttribute('aria-expanded'), 'true');
assert.equal(page.doc.getElementById(triggers[0].getAttribute('aria-controls')).hidden, false);
click(triggers[1]);
assert.equal(triggers[0].getAttribute('aria-expanded'), 'false');
assert.equal(triggers[1].getAttribute('aria-expanded'), 'true');
click(triggers[1]);
assert.equal(triggers[1].getAttribute('aria-expanded'), 'false');

const menu = page.ids.get('menu-toggle');
menu.listeners.click();
assert.equal(menu.getAttribute('aria-expanded'), 'true');
page.doc.listeners.keydown({key: 'Escape'});
assert.equal(menu.getAttribute('aria-expanded'), 'false');
assert.equal(menu.focused, true);
menu.listeners.click();
page.doc.listeners.click({target: new Element('outside')});
assert.equal(menu.getAttribute('aria-expanded'), 'false');
menu.listeners.click();
page.media.listener({matches: true});
assert.equal(menu.getAttribute('aria-expanded'), 'false');
menu.listeners.click();
const sectionLink = new Element('a');
page.ids.get('section-links').append(sectionLink);
page.ids.get('section-links').listeners.click({target: sectionLink});
assert.equal(menu.getAttribute('aria-expanded'), 'false');
menu.listeners.click();
page.ids.get('landing-header').listeners.focusout({relatedTarget: new Element('outside')});
assert.equal(menu.getAttribute('aria-expanded'), 'false');

// The same accordion handler keeps grooming, clinic and FAQ groups independent.
const faqGroup = new Element();
faqGroup.setAttribute('data-accordion-group', '');
for (const id of ['faq-one', 'faq-two']) {
  const article = new Element('article');
  article.setAttribute('data-accordion-item', '');
  const button = new Element('button');
  button.setAttribute('data-accordion-trigger', '');
  button.setAttribute('aria-expanded', 'false');
  button.setAttribute('aria-controls', id);
  const answer = new Element('div', id);
  answer.hidden = true;
  article.append(button, answer);
  faqGroup.append(article);
}
page.doc.append(faqGroup);
const faqButtons = faqGroup.querySelectorAll('[data-accordion-trigger]');
click(triggers[0]); click(faqButtons[0]); click(faqButtons[1]);
assert.equal(triggers[0].getAttribute('aria-expanded'), 'true');
assert.equal(faqButtons[0].getAttribute('aria-expanded'), 'false');
assert.equal(page.doc.getElementById('faq-one').hidden, true);
assert.equal(page.doc.getElementById('faq-two').hidden, false);

// A last-column card moves before its neighbor in reading order, then restores on collapse.
const clinicButtons = page.clinic.querySelectorAll('[data-accordion-trigger]');
const originalOrder = ['surgery', 'treatment', 'vaccination', 'confinement', 'xray', 'consultation', 'laboratory', 'ultrasound'];
const clinicOrder = () => page.clinic.children.map(card => card.id);
clinicButtons[3].focus();
click(clinicButtons[3]);
assert.deepEqual(clinicOrder(), ['surgery', 'treatment', 'confinement', 'vaccination', 'xray', 'consultation', 'laboratory', 'ultrasound']);
assert.equal(page.doc.activeElement, clinicButtons[3], 'Reordering preserves selected-button focus');
assert.equal(page.doc.getElementById('clinic-confinement').hidden, false);
clinicButtons[7].focus();
click(clinicButtons[7]);
assert.deepEqual(clinicOrder(), ['surgery', 'treatment', 'vaccination', 'confinement', 'xray', 'consultation', 'ultrasound', 'laboratory']);
assert.equal(clinicButtons[3].getAttribute('aria-expanded'), 'false');
assert.equal(page.doc.activeElement, clinicButtons[7]);
click(clinicButtons[7]);
assert.deepEqual(clinicOrder(), originalOrder);
for (const index of [0, 1, 2, 4, 5, 6]) {
  click(clinicButtons[index]);
  assert.deepEqual(clinicOrder(), originalOrder);
  click(clinicButtons[index]);
}
click(clinicButtons[1]);
page.desktop.matches = false;
page.desktop.listener();
assert.deepEqual(clinicOrder(), ['treatment', 'surgery', 'vaccination', 'confinement', 'xray', 'consultation', 'laboratory', 'ultrasound']);
page.tablet.matches = false;
page.tablet.listener();
assert.deepEqual(clinicOrder(), originalOrder, 'Mobile restores natural reading order while keeping the detail open');
assert.equal(page.doc.getElementById('clinic-treatment').hidden, false);
click(clinicButtons[1]);
assert.equal(page.doc.getElementById('clinic-treatment').hidden, true);

let resolveFetch;
let attempt = 0;
const failed = harness(async () => {
  if (++attempt === 1) throw new Error('offline');
  return new Promise(resolve => { resolveFetch = resolve; });
});
await settle();
assert.match(content(failed.ids.get('pricing-status')), /unavailable/i);
assert.equal(failed.ids.get('pricing-retry').hidden, false);
assert.equal(failed.ids.get('pricing-content').hidden, true);
const retry = failed.ids.get('pricing-retry').listeners.click;
retry(); retry();
assert.equal(failed.requests(), 2, 'Concurrent retries share one request');
resolveFetch({ok: true, json: async () => ({data: packages})});
await settle();
assert.equal(failed.ids.get('pricing-content').hidden, false);
assert.equal(failed.ids.get('pricing-retry').hidden, true);

for (const data of [[], [{...packages[0], priceOptions: [option('small', 0)]}], null]) {
  const state = harness(async () => ({ok: true, json: async () => ({data})}));
  await settle();
  assert.equal(state.ids.get('pricing-content').hidden, true);
  assert.match(content(state.ids.get('pricing-status')), data?.length === 0 ? /No grooming packages/i : /unavailable/i);
}
console.log('Public landing rendering, pricing, retry, accordion and mobile-menu regressions passed.');
