// Only opaque credentials and deadlines survive reloads; never form values.
window.SignupContext = (() => {
  const key = 'bethlehem_signup_attempt';
  let active = null;
  try {
    const saved = JSON.parse(sessionStorage.getItem(key) || 'null');
    // The server decides expiry. A device clock change must not discard the
    // credential needed to resume an otherwise valid registration.
    if (/^[a-f0-9]{64}$/.test(saved?.token)) active = saved;
    else sessionStorage.removeItem(key);
    // Remove obsolete signup drafts from earlier deployments.
    for (const old of ['pendingSignupDetails', 'pendingVerificationEmail', 'pendingVerificationPhone',
      'pendingRegistrationEditToken', 'pendingRegistrationRecoveryToken', 'pendingRegistrationRecoveryUntil',
      'pendingVerificationRegistrationUntil', 'pendingVerificationResendUntil', 'pendingVerificationLinkUntil',
      'pendingVerificationState', 'pendingVerificationResendLimited', 'pendingVerificationDeliveryFailed',
      'registrationReplacement', 'pendingSignupContext']) sessionStorage.removeItem(old);
    localStorage.removeItem('pendingSignupContext');
  } catch { /* The active page can still register without storage. */ }
  function save(value) {
    active = value;
    try {
      if (value) sessionStorage.setItem(key, JSON.stringify(value));
      else sessionStorage.removeItem(key);
    } catch { /* No persistent password fallback. */ }
    return value;
  }
  function create() {
    const bytes = window.crypto.getRandomValues(new Uint8Array(32));
    return save({token:Array.from(bytes, byte=>byte.toString(16).padStart(2,'0')).join(''), expiresAt:Date.now()+3600000, resendUntil:0});
  }
  return {read:()=>active, create, save, clear:()=>save(null)};
})();
