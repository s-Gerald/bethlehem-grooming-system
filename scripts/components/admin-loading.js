// Shared presentation only. Each data owner decides when its region is pending.
(() => {
  const sprite = new URL('../../assets/icons/phosphor.svg', document.currentScript.src);
  // A public title hint, never an authorization decision.
  try {
    const roles = [localStorage, sessionStorage].map(storage => {
      const role = storage.getItem('user_role');
      return ['admin', 'staff'].includes(role) && storage.getItem('admin_token') ? role : null;
    }).filter(Boolean);
    if (roles.length === 1) document.documentElement.dataset.adminRole = roles[0];
  } catch { /* The authenticated sidebar sets the verified display role. */ }
  if (customElements.get('admin-loader')) return;
  customElements.define('admin-loader', class extends HTMLElement {
    connectedCallback() {
      if (this.firstElementChild) return;
      this.setAttribute('role', 'status');
      this.setAttribute('aria-live', 'polite');
      this.setAttribute('aria-busy', 'true');
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('viewBox', '0 0 256 256');
      svg.setAttribute('aria-hidden', 'true');
      svg.setAttribute('focusable', 'false');
      const use = document.createElementNS(svg.namespaceURI, 'use');
      use.setAttribute('href', `${sprite.href}#spinner-gap`);
      svg.appendChild(use);
      const label = document.createElement('p');
      label.textContent = 'Loading...';
      this.append(svg, label);
    }
  });
})();
