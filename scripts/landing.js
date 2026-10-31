import { formatPhpAmount, formatPriceOption } from './services/grooming-price-format.js?v=20261101';

const header = document.getElementById('landing-header');
const menuToggle = document.getElementById('menu-toggle');
const links = document.getElementById('section-links');

function closeMenu(restoreFocus = false) {
  header.classList.remove('menu-open');
  menuToggle.setAttribute('aria-expanded', 'false');
  menuToggle.setAttribute('aria-label', 'Open navigation');
  if (restoreFocus) menuToggle.focus();
}

menuToggle.addEventListener('click', () => {
  const open = menuToggle.getAttribute('aria-expanded') !== 'true';
  header.classList.toggle('menu-open', open);
  menuToggle.setAttribute('aria-expanded', String(open));
  menuToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
});
links.addEventListener('click', event => {
  if (event.target.closest('a')) closeMenu();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') closeMenu(true);
});
header.addEventListener('focusout', event => {
  if (!header.contains(event.relatedTarget)) closeMenu();
});
window.matchMedia('(min-width: 56rem)').addEventListener('change', () => closeMenu());

// Native buttons supply Enter/Space behavior; each group maintains its own open item.
document.addEventListener('click', event => {
  if (!header.contains(event.target)) closeMenu();
  const trigger = event.target.closest('[data-accordion-trigger]');
  if (!trigger) return;
  const group = trigger.closest('[data-accordion-group]');
  const open = trigger.getAttribute('aria-expanded') !== 'true';
  group.querySelectorAll('[data-accordion-trigger]').forEach(button => {
    const expanded = button === trigger && open;
    button.setAttribute('aria-expanded', String(expanded));
    document.getElementById(button.getAttribute('aria-controls')).hidden = !expanded;
    button.closest('[data-accordion-item]').classList.toggle('is-expanded', expanded);
  });
});

const status = document.getElementById('pricing-status');
const retry = document.getElementById('pricing-retry');
const pricingContent = document.getElementById('pricing-content');
const packageGrid = document.getElementById('package-grid');
const sizeLabels = {small: 'Small', medium: 'Medium', large: 'Large', extra_large: 'Extra Large'};
let loading = false;

function element(tag, className = '', text = '') {
  const node = document.createElement(tag);
  node.className = className;
  node.textContent = text;
  return node;
}

function icon(name, className = '') {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', `landing-icon ${className}`);
  svg.setAttribute('aria-hidden', 'true');
  const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
  use.setAttribute('href', `assets/icons/phosphor.svg?v=20261101#${name}`);
  svg.append(use);
  return svg;
}

function publicName(service) {
  if (service.id === 'cat_full_grooming' && service.name === 'Cat Full Grooming') return 'Full Grooming';
  if (service.id === 'bath_and_go' && service.name === 'Bath and Go!') return 'Bath and Go';
  if (service.id === 'deluxe_dog_grooming' && service.name === 'Deluxe Dog Grooming') return 'Deluxe Grooming';
  return service.name;
}

function packageCard(service) {
  const card = element('article', `package-card${service.petType === 'cat' ? ' package-card--cat' : ''}`);
  card.setAttribute('data-accordion-item', '');
  const button = element('button', 'package-trigger');
  button.type = 'button';
  button.id = `package-${service.id}`;
  button.setAttribute('data-accordion-trigger', '');
  button.setAttribute('aria-expanded', 'false');
  button.setAttribute('aria-controls', `package-detail-${service.id}`);
  const text = element('span', 'package-summary');
  const name = publicName(service) + (service.petType === 'cat' ? ' — Cat' : '');
  text.append(element('span', 'package-name', name),
    element('span', 'package-price', `Starts at ${formatPhpAmount(Math.min(...service.priceOptions.map(option => option.minAmount)))}`));
  const heading = element('h4');
  button.append(icon(service.petType), text, icon('plus', 'expand-indicator'));
  heading.append(button);
  const detail = element('div', 'package-detail');
  detail.id = `package-detail-${service.id}`;
  detail.setAttribute('aria-labelledby', button.id);
  detail.hidden = true;
  if (service.descriptionItems.length) {
    const list = element('ul');
    service.descriptionItems.forEach(description => list.append(element('li', '', description)));
    detail.append(list);
  } else {
    detail.append(element('p', '', 'Contact the clinic for package details.'));
  }
  card.append(heading, detail);
  return card;
}

function renderTable(container, services, title) {
  container.replaceChildren();
  container.hidden = services.length === 0;
  if (!services.length) return;
  const sizes = Object.keys(sizeLabels).filter(size => services.some(service => service.priceOptions.some(option => option.sizeKey === size)));
  const table = element('table', 'price-table');
  table.append(element('caption', '', title));
  const head = element('thead');
  const headings = element('tr');
  ['Package', ...sizes.map(size => sizeLabels[size])].forEach(label => {
    const cell = element('th', '', label);
    cell.setAttribute('scope', 'col');
    headings.append(cell);
  });
  head.append(headings);
  const body = element('tbody');
  services.forEach(service => {
    const row = element('tr');
    const name = element('th', '', publicName(service));
    name.setAttribute('scope', 'row');
    row.append(name);
    sizes.forEach(size => {
      const option = service.priceOptions.find(price => price.sizeKey === size);
      const cell = element('td', '', option ? formatPriceOption(option) : '—');
      if (!option) cell.append(element('span', 'visually-hidden', 'Unavailable'));
      row.append(cell);
    });
    body.append(row);
  });
  table.append(head, body);
  const scroll = element('div', 'table-scroll');
  scroll.tabIndex = 0;
  scroll.setAttribute('role', 'region');
  scroll.setAttribute('aria-label', `${title}, scroll horizontally for all sizes`);
  scroll.append(table);
  container.append(scroll);
}

function validCatalogue(data) {
  return Array.isArray(data) && data.every(service =>
    typeof service.id === 'string' && /^[a-z_]+$/.test(service.id) && typeof service.name === 'string'
    && ['dog', 'cat'].includes(service.petType) && Array.isArray(service.descriptionItems)
    && service.descriptionItems.every(item => typeof item === 'string')
    && Array.isArray(service.priceOptions) && service.priceOptions.length > 0
    && service.priceOptions.every(option => Object.hasOwn(sizeLabels, option.sizeKey)
      && Number.isFinite(option.minAmount) && option.minAmount > 0
      && ['fixed', 'range', 'starting_at'].includes(option.pricingType)
      && (option.pricingType === 'starting_at' ? option.maxAmount === null
        : Number.isFinite(option.maxAmount) && option.maxAmount >= option.minAmount)));
}

async function loadPackages() {
  if (loading) return;
  loading = true;
  retry.disabled = true;
  status.textContent = 'Loading grooming packages and prices…';
  packageGrid.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch(new URL('api/public/grooming/packages', document.baseURI), {
      cache: 'no-store', credentials: 'omit', headers: {Accept: 'application/json'},
    });
    if (!response.ok) throw new Error('Pricing unavailable');
    const {data} = await response.json();
    if (!validCatalogue(data)) throw new Error('Invalid catalogue');
    packageGrid.replaceChildren(...data.map(packageCard));
    renderTable(document.getElementById('dog-pricing'), data.filter(service => service.petType === 'dog'), 'Dog Grooming Pricing');
    renderTable(document.getElementById('cat-pricing'), data.filter(service => service.petType === 'cat'), 'Cat Grooming Pricing');
    pricingContent.hidden = data.length === 0;
    status.textContent = data.length ? '' : 'No grooming packages are currently available. Please contact the clinic.';
    retry.hidden = data.length > 0;
  } catch {
    pricingContent.hidden = true;
    status.textContent = 'Grooming prices are unavailable right now. Please try again.';
    retry.hidden = false;
  } finally {
    loading = false;
    retry.disabled = false;
    packageGrid.setAttribute('aria-busy', 'false');
  }
}

retry.addEventListener('click', loadPackages);
loadPackages();
