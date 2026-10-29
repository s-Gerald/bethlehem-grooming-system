// Format transaction snapshots; never consult current inventory prices.
window.RecordedProductPricing = (() => {
  const currency = new Intl.NumberFormat('en-PH', {
    style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2,
  });
  function cents(value) {
    if (!['string', 'number'].includes(typeof value)) return null;
    const raw = String(value).trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) return null;
    const [whole, fraction = ''] = raw.split('.');
    const amount = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    return Number.isSafeInteger(amount) ? amount : null;
  }
  function quantity(line) {
    if (!['string', 'number'].includes(typeof line?.quantity)) return null;
    const value = Number(line.quantity);
    return Number.isSafeInteger(value) && value > 0 ? value : null;
  }
  function unitCents(line) {
    const snapshot = line?.priceAtSale ?? line?.price_at_sale;
    if (snapshot != null) return cents(snapshot);
    const count = quantity(line);
    const total = cents(line?.subtotal);
    return count !== null && total !== null && total % count === 0 ? total / count : null;
  }
  const money = value => value === null ? '—' : currency.format(value / 100);
  return {
    formatUnitPrice: line => money(unitCents(line)),
    formatPriceQuantity: line => `${money(unitCents(line))} × ${quantity(line) ?? '—'}`,
    formatLineTotal: line => money(cents(line?.subtotal)),
  };
})();
