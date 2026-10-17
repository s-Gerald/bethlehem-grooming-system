// Shared Hosted Checkout navigation and backend verification. No provider credentials.
window.OnlinePayment = {
  get(context) {
    const params = new URL(window.location.href).searchParams;
    if (params.get('payment_context') === context && /^\d+$/.test(params.get('gateway_payment') || '')) {
      return {id: Number(params.get('gateway_payment')), payable: Number(params.get('payable')), outcome: params.get('payment_return')};
    }
    try { return JSON.parse(window.sessionStorage.getItem(`bethlehem-payment:${context}`)) || null; }
    catch { return null; }
  },
  remember(context, attempt, payable) {
    try { window.sessionStorage.setItem(`bethlehem-payment:${context}`, JSON.stringify({id: attempt.id, payable, requestKey: attempt.request_key})); }
    catch { /* Return URL also carries the recovery identifiers. */ }
  },
  clear(context) {
    try { window.sessionStorage.removeItem(`bethlehem-payment:${context}`); } catch { /* Storage can be disabled. */ }
    const url = new URL(window.location.href);
    if (url.searchParams.get('payment_context') === context) {
      for (const key of ['payment_context', 'gateway_payment', 'payable', 'payment_return']) url.searchParams.delete(key);
      window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    }
  },
  open(context, attempt, payable) {
    if (attempt?.status !== 'pending' || !attempt.checkout_url) return;
    const url = new URL(attempt.checkout_url);
    if (url.protocol !== 'https:' || url.hostname !== 'checkout.paymongo.com') return;
    this.remember(context, attempt, payable);
    window.location.assign(attempt.checkout_url);
  },
  watch({load, apply, error, active}) {
    let timer = null, generation = 0;
    const stop = () => {clearTimeout(timer); timer = null; generation++;};
    const check = async () => {
      stop();
      const current = generation;
      let pending = true;
      try {
        const response = await load();
        if (current !== generation || !active()) return;
        pending = response.online_payment?.status === 'pending';
        await apply(response);
      } catch (err) {
        if (current !== generation || !active()) return;
        error(err);
      } finally {
        if (current === generation && active() && pending) timer = setTimeout(check, 4000);
      }
    };
    return {check, stop};
  },
};
