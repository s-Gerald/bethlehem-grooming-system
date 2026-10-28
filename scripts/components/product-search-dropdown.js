// Presentation only. Each consumer retains its own inventory eligibility and API rules.
var ProductSearchDropdown = {
  create(onClose = () => {}) {
    let removeListeners = () => {};
    return {
      open: false, activeId: null, style: '', listStyle: '',
      show(input, popup) {
        removeListeners();
        this.open = true;
        this.activeId = null;
        if (!input || !popup) return;
        const rect = input.getBoundingClientRect();
        let top = 8, bottom = window.innerHeight - 8;
        const parents = [];
        for (let parent = input.parentElement; parent; parent = parent.parentElement) {
          if (/(auto|scroll)/.test(getComputedStyle(parent).overflowY)) {
            const bounds = parent.getBoundingClientRect();
            top = Math.max(top, bounds.top + 4);
            bottom = Math.min(bottom, bounds.bottom - 4);
            parents.push(parent);
          }
        }
        const below = bottom - rect.bottom - 6;
        const above = rect.top - top - 6;
        const upwards = below < 164 && above > below;
        const height = Math.max(38, Math.min(262, upwards ? above : below));
        this.style = `left:${Math.max(8, rect.left)}px;width:${Math.min(rect.width, window.innerWidth - 16)}px;${upwards ? `bottom:${window.innerHeight - rect.top + 6}px` : `top:${rect.bottom + 6}px`}`;
        this.listStyle = `max-height:${Math.max(0, Math.min(224, Math.floor((height - 38) / 56) * 56))}px`;
        const close = () => this.close();
        parents.forEach(parent => parent.addEventListener('scroll', close, {passive: true}));
        window.addEventListener('resize', close, {passive: true});
        removeListeners = () => {
          parents.forEach(parent => parent.removeEventListener('scroll', close));
          window.removeEventListener('resize', close);
        };
      },
      close() {
        this.open = false;
        this.activeId = null;
        removeListeners();
        onClose();
      },
      key(event, items, selectable, select, popup) {
        if (event.key === 'Tab') { this.close(); return false; }
        if (event.key === 'Escape' && this.open) {
          event.preventDefault(); event.stopPropagation(); this.close(); return true;
        }
        if (!this.open || !['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return false;
        const available = items.filter(selectable);
        if (event.key === 'Enter') {
          const item = available.find(item => String(item.item_id) === String(this.activeId));
          if (!item) return false;
          event.preventDefault(); event.stopPropagation(); select(item); return true;
        }
        event.preventDefault(); event.stopPropagation();
        const index = available.findIndex(item => String(item.item_id) === String(this.activeId));
        const next = index < 0 ? (event.key === 'ArrowDown' ? 0 : available.length - 1)
          : Math.max(0, Math.min(available.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)));
        this.activeId = available[next]?.item_id ?? null;
        popup?.querySelector(`[data-product-id="${this.activeId}"]`)?.scrollIntoView({block: 'nearest'});
        return true;
      },
    };
  },
  retailPriced(item) { return Number(item?.selling_price) > 0 && Number(item?.selling_price) <= 999999.99; },
  retailStatus(item) {
    if (!item?.is_active) return 'Inactive';
    if (!this.retailPriced(item)) return 'Price unavailable';
    if (Number(item.quantity_on_hand) <= 0) return 'Out of stock';
    if (Number(item.saleable_quantity) < 1 && Number(item.expired_quantity) > 0) return 'Expired';
    return 'Unavailable stock';
  },
};
