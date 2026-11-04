// Active verification lives only in this tab. Stored values never authorize an account session.
window.SignupContext = (() => {
  const keys = ["pendingVerificationEmail", "pendingVerificationPhone", "pendingVerificationState",
    "pendingVerificationRegistrationUntil", "pendingVerificationResendUntil", "pendingVerificationLinkUntil",
    "pendingVerificationResendLimited", "pendingVerificationDeliveryFailed", "pendingRegistrationEditToken",
    "pendingRegistrationRecoveryToken", "pendingRegistrationRecoveryUntil", "pendingSignupDetails",
    "pending_registration_email_delivery", "pendingSignupContext"];
  const get = key => { try { return sessionStorage.getItem(key) || ""; } catch { return ""; } };
  function read(email = "") {
    const activeEmail = get("pendingVerificationEmail");
    const expiresAt = Number(get("pendingVerificationRegistrationUntil"));
    return activeEmail && expiresAt > Date.now() && expiresAt <= Date.now() + 3600000
      && (!email || activeEmail.toLowerCase() === email.trim().toLowerCase()) ? {email: activeEmail, expiresAt} : null;
  }
  function remember(email, expiry) {
    const value = {email, expiresAt: Date.parse(expiry) || Date.now() + 3600000};
    try {
      sessionStorage.setItem("pendingVerificationEmail", email);
      sessionStorage.setItem("pendingVerificationRegistrationUntil", String(value.expiresAt));
      sessionStorage.removeItem("registrationReplacement");
    } catch { /* Server expiry remains authoritative. */ }
    return value;
  }
  function clear() {
    for (const key of keys) { try { sessionStorage.removeItem(key); } catch { /* UX only. */ } }
    try { localStorage.removeItem("pendingSignupContext"); } catch { /* Obsolete drafts. */ }
  }
  function fresh() {
    const active = read();
    const token = get("pendingRegistrationEditToken");
    let replacement = null;
    try {
      replacement = active && token ? {...active, token} : JSON.parse(get("registrationReplacement") || "null");
      if (!replacement || replacement.expiresAt <= Date.now() || replacement.expiresAt > Date.now() + 3600000) replacement = null;
      if (replacement) sessionStorage.setItem("registrationReplacement", JSON.stringify(replacement));
      else sessionStorage.removeItem("registrationReplacement");
    } catch { /* Fresh signup remains available. */ }
    clear();
    return replacement;
  }
  async function abandon(scopedToken = "") {
    const token = scopedToken || get("pendingRegistrationEditToken") || get("pendingRegistrationRecoveryToken");
    clear();
    try { sessionStorage.removeItem("registrationReplacement"); } catch { /* UX only. */ }
    if (token) {
      try { await API.cancelRegistration(token); } catch { /* Expiry is the fallback for network loss. */ }
    }
  }
  function bindExits(onExit = () => {}, attemptToken = () => "") {
    for (const link of document.querySelectorAll('a[href="/sign-up"], a[href="/sign-in"], a[href="/"]')) {
      link.addEventListener("click", async event => {
        if (event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        const token = attemptToken();
        onExit();
        await abandon(token);
        window.location.replace(link.getAttribute("href"));
      });
    }
  }
  return {read, remember, clear, fresh, abandon, bindExits};
})();
