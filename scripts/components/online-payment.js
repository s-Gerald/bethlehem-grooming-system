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
    if (url.protocol !== 'https:' || url.hostname !== 'checkout.paymongo.com' || url.username || url.password || url.port) return;
    this.remember(context, attempt, payable);
    window.location.assign(attempt.checkout_url);
  },
  watch({load, apply, error, active, exhausted = () => {}, maxRequests = 15}) {
    let timer = null, generation = 0, requests = 0, inFlight = null;
    const stop = () => {
      clearTimeout(timer); timer = null; generation++;
      window.removeEventListener?.('pagehide', stop);
    };
    const check = async () => {
      if (inFlight) return inFlight;
      clearTimeout(timer); timer = null;
      if (!active()) return;
      if (requests >= maxRequests || (typeof document !== 'undefined' && document.visibilityState === 'hidden')) { exhausted(); return; }
      const current = ++generation;
      requests++;
      let pending = true;
      try {
        inFlight = Promise.resolve(load());
        const response = await inFlight;
        if (current !== generation || !active()) return;
        pending = response.online_payment?.status === 'pending';
        await apply(response);
      } catch (err) {
        if (current !== generation || !active()) return;
        error(err);
      } finally {
        inFlight = null;
        if (current === generation && active() && pending) {
          if (requests < maxRequests) timer = setTimeout(check, 4000);
          else exhausted();
        }
      }
    };
    window.addEventListener?.('pagehide', stop, {once: true});
    const start = () => { if (active() && !timer && !inFlight) timer = setTimeout(check, 4000); };
    return {check, stop, start};
  },
};
