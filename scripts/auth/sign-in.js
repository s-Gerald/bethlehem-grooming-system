// Connected to pages/client/sign-in.html
// Depends on: api.js (loaded before this script in the HTML)
// Team note: pages/admin/login.html and pages/client/login.html were removed
// intentionally. All frontend login entry points and auth redirects should
// point to the shared sign-in page above.

document.addEventListener("DOMContentLoaded", () => {
  // When the page is reached via a logout redirect (?logout=1), forcefully
  // clear any stale auth state from localStorage/sessionStorage.  This
  // guarantees the form is always shown even if the outgoing page's api.js
  // was cached and skipped clearUserRole() / clearAdminToken().
  if (new URLSearchParams(window.location.search).get("logout") === "1") {
    try {
      if (typeof API?.clearAuthState === "function") {
        API.clearAuthState();
      } else {
        ["admin_token", "customer_token", "user_role"].forEach((k) => {
          localStorage.removeItem(k);
          sessionStorage.removeItem(k);
        });
      }
    } catch { /* storage unavailable */ }
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("logout");
    history.replaceState(null, "", `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
  }

  const signInForm              = document.getElementById("signin");
  const identifierInput         = document.getElementById("identifier");
  const passwordInput           = document.getElementById("password");
  const messageBox              = document.getElementById("sharedSignInMessage");
  const submitButton            = document.getElementById("sharedSignInSubmit");
  const rememberMeCheckbox      = document.getElementById("rememberMeCheckbox");
  const togglePasswordVisibilityButton = document.getElementById(
    "toggleSharedPasswordVisibility"
  );

  if (!signInForm || !identifierInput || !passwordInput) return;

  const replacementAlert = document.getElementById('sessionReplacedAlert');
  const signoutReasonKey = 'bethlehem.auth.signout_reason';
  try {
    if (sessionStorage.getItem(signoutReasonKey) === 'session_replaced' && replacementAlert) {
      replacementAlert.hidden = false;
      messageBox?.classList.add('hidden');
    }
  } catch { /* The form remains available when storage is blocked. */ }
  function dismissReplacementAlert() {
    if (replacementAlert) replacementAlert.hidden = true;
    try { sessionStorage.removeItem(signoutReasonKey); } catch { /* Optional UI state. */ }
  }
  document.getElementById('dismissSessionReplaced')?.addEventListener('click', () => {
    dismissReplacementAlert();
    identifierInput.focus();
  });
  signInForm.addEventListener('input', dismissReplacementAlert, { once: true });

  document.getElementById("forgotPasswordLink")?.addEventListener("click", () => {
    const email = document.createElement("input");
    email.type = "email";
    email.required = true;
    email.value = identifierInput.value.trim();
    try {
      sessionStorage.removeItem("pendingPasswordResetEmail");
      if (email.checkValidity()) {
        sessionStorage.setItem("pendingPasswordResetEmail", email.value);
      }
    } catch { /* storage unavailable; continue to Forgot Password */ }
  });

  const passwordIconClosed = document.querySelector(".shared-password-icon-closed");
  const passwordIconOpen   = document.querySelector(".shared-password-icon-open");

  if (togglePasswordVisibilityButton) {
    togglePasswordVisibilityButton.addEventListener("click", function () {
      const shouldShowPassword = passwordInput.type === "password";
      const nextLabel = shouldShowPassword ? "Hide password" : "Show password";

      passwordInput.type = shouldShowPassword ? "text" : "password";
      this.setAttribute("aria-label", nextLabel);
      this.setAttribute("title", nextLabel);

      if (passwordIconClosed && passwordIconOpen) {
        passwordIconClosed.classList.toggle("hidden", shouldShowPassword);
        passwordIconOpen.classList.toggle("hidden", !shouldShowPassword);
      }
    });
  }

  let countdownTimer = null;
  let busy = false;
  let lockoutUntil = 0;
  let lockoutIdentifier = null;
  const statusBox = document.getElementById('sharedSignInStatus');
  const currentIdentifier = () => identifierInput.value.trim().toLowerCase();

  // Old versions persisted any 429, including day-long email quotas. Storage
  // cannot establish a password restriction; only a new server response can.
  try { sessionStorage.removeItem('signInLockoutUntil'); } catch { /* Storage is optional. */ }
  identifierInput.addEventListener('input', () => {
    if (lockoutIdentifier !== null && lockoutIdentifier !== currentIdentifier()) clearCountdown();
  });

  signInForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (lockoutUntil && performance.now() >= lockoutUntil) clearCountdown();
    if (busy || performance.now() < lockoutUntil) return;
    dismissReplacementAlert();

    const identifier = identifierInput.value.trim();
    const password   = passwordInput.value;
    const rememberMe = rememberMeCheckbox?.checked ?? true;
    const submittedIdentifier = currentIdentifier();

    if (!identifier || !password) {
      showMessage("error", "Please fill in both fields.");
      return;
    }

    showMessage(null, "");
    setBusyState(true);

    try {
      const response = await API.signIn(identifier, password, rememberMe);

      if (response?.requires_login_confirmation) {
        passwordInput.value = "";
        window.location.replace("./verify-email.html?mode=login");
        return;
      }

      const role = response?.user?.role;
      const dest = (role === "admin" || role === "staff")
        ? "../admin/dashboard.html"
        : "./dashboard.html";

      showMessage("success", "Signed in successfully! Redirecting...");
      await new Promise((resolve) => setTimeout(resolve, 1000));
      window.location.href = dest;
    } catch (error) {
      if (error.status === 429 && ['sign_in_password_cooldown', 'sign_in_ip_cooldown'].includes(error.code)) {
        if (error.code === 'sign_in_ip_cooldown' || submittedIdentifier === currentIdentifier()) {
          const seconds = error.retryAfter;
          if (typeof seconds === 'number' && Number.isFinite(seconds) && seconds > 0 && seconds <= 900) {
            startCountdown(seconds, error.code === 'sign_in_ip_cooldown' ? null : submittedIdentifier);
          } else {
            showMessage('lockout', 'Too many attempts. Please try again.');
          }
        }
      } else if (error.emailNotVerified) {
        const email = error.email ?? "";
        showMessage(
          "verify",
          `Your email address hasn't been verified yet. ` +
          `<a href="./verify-email.html" ` +
          `style="font-weight:600;text-decoration:underline">` +
          `Complete registration →</a>`,
          email
        );
      } else {
        showMessage("error", error.message || "Unable to sign in.");
      }
    } finally {
      setBusyState(false);
    }
  });

  // Keep handling redirects from verification links generated by older cached
  // frontend versions, which returned customers to this page.
  if (new URLSearchParams(window.location.search).get("verified") === "1") {
    showMessage("success", "Email verified successfully. Please sign in to continue.");
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("verified");
    history.replaceState(null, "", `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
  } else if (new URLSearchParams(window.location.search).get("registered") === "1") {
    showMessage("success", "Account created successfully. Please sign in to continue.");
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("registered");
    history.replaceState(null, "", `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
  } else if (new URLSearchParams(window.location.search).get("password_reset") === "1") {
    showMessage("success", "Password reset successfully. Please sign in.");
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("password_reset");
    history.replaceState(null, "", `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
  }

  function clearCountdown() {
    clearInterval(countdownTimer);
    countdownTimer = null;
    lockoutUntil = 0;
    lockoutIdentifier = null;
    setBusyState(busy);
    showMessage(null, '');
  }

  function tickCountdown() {
    const remaining = Math.max(0, Math.ceil((lockoutUntil - performance.now()) / 1000));
    if (remaining <= 0) {
      clearCountdown();
      if (statusBox) statusBox.textContent = 'You can sign in again.';
      return;
    }
    submitButton.disabled = true;
    messageBox.textContent = `Too many attempts. Try again in ${window.VerificationCode.formatCountdown(remaining)}.`;
  }

  function startCountdown(seconds, identifier) {
    clearInterval(countdownTimer);
    // A monotonic deadline cannot grow when the device clock moves backwards.
    lockoutUntil = performance.now() + seconds * 1000;
    lockoutIdentifier = identifier;
    showMessage('lockout', '');
    tickCountdown();
    if (statusBox) statusBox.textContent = messageBox.textContent;
    countdownTimer = setInterval(tickCountdown, 1000);
  }

  function setBusyState(isBusy) {
    busy = isBusy;
    submitButton.disabled = isBusy || performance.now() < lockoutUntil;
    const label = document.getElementById("signInBtnLabel");
    if (label) label.textContent = isBusy ? "Signing In..." : "Sign In";
  }

  window.addEventListener('pagehide', () => {
    clearInterval(countdownTimer);
    countdownTimer = null;
  });
  window.addEventListener("pageshow", event => {
    if (!event.persisted || !lockoutUntil) return;
    tickCountdown();
    if (lockoutUntil && countdownTimer === null) countdownTimer = setInterval(tickCountdown, 1000);
  });
  document.addEventListener('visibilitychange', () => {
    if (lockoutUntil) tickCountdown();
  });

  function showMessage(type, text, prefillEmail = "") {
    if (type && replacementAlert && !replacementAlert.hidden) return;
    if (statusBox) statusBox.textContent = '';
    messageBox.setAttribute('role', type === 'lockout' ? 'timer' : type === 'error' ? 'alert' : 'status');
    messageBox.setAttribute('aria-live', type === 'lockout' ? 'off' : type === 'error' ? 'assertive' : 'polite');
    if (type === 'lockout' && statusBox) statusBox.textContent = text;
    if (!type) {
      messageBox.className = "hidden rounded-2xl border px-4 py-3 text-sm";
      messageBox.innerHTML = "";
      return;
    }

    const styles = {
      error:   "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700",
      success: "rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700",
      lockout: "rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700",
      verify:  "rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700",
    };

    messageBox.className = styles[type] || styles.error;

    if (type === "verify") {
      // Store email so the verify page can pre-fill the resend form
      if (prefillEmail) {
        sessionStorage.setItem("pendingVerificationEmail", prefillEmail);
      }
      messageBox.innerHTML = text;
    } else {
      messageBox.textContent = text;
    }
  }
});
