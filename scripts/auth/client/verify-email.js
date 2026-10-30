// Connected to pages/client/verify-email.html
// Preserves already-issued signup links and the separate login confirmation flow.

document.addEventListener("DOMContentLoaded", () => {
  const stateVerifying = document.getElementById("stateVerifying");
  const stateSuccess   = document.getElementById("stateSuccess");
  const statePending   = document.getElementById("statePending");
  const stateLoginCode = document.getElementById("stateLoginCode");
  const stateError     = document.getElementById("stateError");
  const resendSection  = document.getElementById("resendSection");
  const successTitle   = document.getElementById("successTitle");
  const successMessage = document.getElementById("successMessage");
  const errorTitle     = document.getElementById("errorTitle");
  const errorMessage   = document.getElementById("errorMessage");
  const loginCodeEmail = document.getElementById("loginCodeEmail");
  const loginCodeForm  = document.getElementById("loginCodeForm");
  const loginCodeInput = document.getElementById("loginCode");
  const loginCodeSubmit = document.getElementById("loginCodeSubmit");
  const loginCodeMessage = document.getElementById("loginCodeMessage");
  const resendEmail    = document.getElementById("resendEmail");
  const resendForm     = document.getElementById("resendForm");
  const resendSubmit   = document.getElementById("resendSubmit");
  const resendMessage  = document.getElementById("resendMessage");
  const loginCodeResendBtn       = document.getElementById("loginCodeResendBtn");
  const loginCodeResendCountdown = document.getElementById("loginCodeResendCountdown");
  const loginCodeResendLock = document.getElementById("loginCodeResendLock");
  const loginCodeResendLabel = document.getElementById("loginCodeResendLabel");

  let resendCodeTimer = null;
  let resendUntil = 0;
  let verifyUntil = 0;
  let codeExhausted = false;
  let transactionExpiresAt = 0;
  let transactionExpired = false;
  let loginBusy = false;
  let resendBusy = false;
  let resendLimited = false;
  let stopDeliveryCheck = () => {};

  function checkDelivery(receipt) {
    stopDeliveryCheck();
    if (!receipt) return;
    stopDeliveryCheck = window.AuthenticationEmailDelivery.watch(receipt, (status) => {
      const text = window.AuthenticationEmailDelivery.failureMessage(status);
      if (text && !codeExhausted && !transactionExpired) {
        showLoginCodeMessage(text, false);
      }
    });
  }

  function showState(name) {
    [stateVerifying, stateSuccess, statePending, stateLoginCode, stateError, document.getElementById("stateLink")]
      .forEach((element) => element.classList.add("hidden"));
    const element = {
      verifying: stateVerifying,
      success: stateSuccess,
      pending: statePending,
      loginCode: stateLoginCode,
      error: stateError,
      link: document.getElementById("stateLink"),
    }[name];
    if (element) element.classList.remove("hidden");
  }

  const queryParams = new URLSearchParams(window.location.search);
  let verificationToken = window.registrationMagicLinkToken || "";
  delete window.registrationMagicLinkToken;
  const loginCodeMode = queryParams.get("mode") === "login";
  if (!verificationToken && !loginCodeMode) return;

  if (verificationToken) {
    showState("link");
    let verifying = false;
    const verifyButton = document.getElementById("verifyLinkSubmit");
    const editButton = document.getElementById("verifyLinkEdit");
    const dashboard = document.getElementById("successDashboard");
    let channel;
    try { channel = new BroadcastChannel("bethlehem-registration"); } catch { /* Dashboard fallback remains available. */ }
    async function confirmHandoff() {
      try {
        const session = await API.registrationSession();
        if (session?.original_tab_confirmed) {
          successMessage.textContent = "Your account is ready. You can safely close this tab and continue to your dashboard.";
          dashboard.classList.add("hidden");
        }
      } catch { /* Keep the working dashboard entry point. */ }
    }
    if (channel) channel.onmessage = event => { if (event.data?.type === "registration-tab-ready") confirmHandoff(); };
    async function verify(review = false) {
      if (verifying || !verificationToken) return;
      verifying = true;
      verifyButton.disabled = true;
      editButton.disabled = true;
      verifyButton.textContent = "Verifying your email...";
      verifyButton.setAttribute("aria-busy", "true");
      showState("verifying");
      try {
        const result = await API.verifyEmail(verificationToken, review);
        verificationToken = "";
        if (result?.requires_registration_review && result?.registration_recovery_token && result?.details) {
          const details = result.details;
          try {
            sessionStorage.setItem("pendingRegistrationRecoveryToken", result.registration_recovery_token);
            sessionStorage.setItem("pendingRegistrationRecoveryUntil", String(Date.parse(result.registration_recovery_expires_at)));
            sessionStorage.setItem("pendingSignupDetails", JSON.stringify({firstName: details.first_name,
              lastName: details.last_name, username: details.username || "", email: details.email, phone: details.phone || ""}));
            sessionStorage.removeItem("pendingRegistrationEditToken");
          } catch {
            throw new Error("Email confirmed. Allow browser storage, then request a new link to review your account.");
          }
          window.location.replace("./signup.html?review=1");
          return;
        }
        // A response marker alone is insufficient: confirm the HttpOnly cookie.
        const session = await API.registrationSession();
        if (!session) throw new Error("Email confirmed. Please sign in to continue.");
        window.SignupContext.clear(session.user?.email);
        successTitle.textContent = "Account created successfully!";
        successMessage.textContent = "Your account is ready.";
        dashboard.classList.remove("hidden");
        showState("success");
        channel?.postMessage({type: "registration-verified"});
        window.setTimeout(confirmHandoff, 1500);
      } catch (error) {
        if (verificationToken && (!error.status || error.status >= 500)) {
          // A lost response may have committed activation. Restore only using
          // the server's usable account cookie; otherwise retain a retry.
          try {
            const session = await API.registrationSession();
            if (session) {
              verificationToken = "";
              successTitle.textContent = "Account created successfully!";
              successMessage.textContent = "Your account is ready.";
              dashboard.classList.remove("hidden");
              showState("success");
              channel?.postMessage({type: "registration-verified"});
              return;
            }
          } catch { /* Retry the deliberate confirmation below. */ }
          showState("link");
          const feedback = document.getElementById("verifyLinkMessage");
          feedback.classList.remove("hidden");
          feedback.textContent = error.message || "Couldn't verify your email. Try again.";
        } else {
          errorTitle.textContent = "Verification unavailable";
          errorMessage.textContent = error.message || "This link is invalid or has already been used.";
          showState("error");
          const expiredRegistration = error.code === "registration_expired" || error.code === "registration_restart_required";
          resendSection.classList.toggle("hidden", expiredRegistration);
          if (expiredRegistration) {
            const back = document.getElementById("verificationBackAction");
            back.textContent = "Back to sign up";
            back.setAttribute("href", "./signup.html");
          }
        }
      } finally {
        verifying = false;
        verifyButton.disabled = false;
        editButton.disabled = false;
        verifyButton.textContent = "Verify Email Address";
        verifyButton.setAttribute("aria-busy", "false");
      }
    }
    verifyButton.addEventListener("click", () => verify());
    editButton.addEventListener("click", () => verify(true));
  } else if (loginCodeMode) {
    document.title = "Verify your sign-in | Bethlehem Animal Clinic";

    if (!API.hasPendingLoginConfirmation()) {
      errorTitle.textContent = "Sign-In Request Unavailable";
      errorMessage.textContent = "This browser no longer has a pending sign-in. Please return to sign in and try again.";
      showState("error");
    } else {
      const email = API.getPendingLoginConfirmationEmail();
      loginCodeEmail.textContent = window.VerificationCode.maskEmail(email);
      showState("loginCode");
      loginCodeInput.focus();
      let deadline = 0;
      try { deadline = Number(sessionStorage.getItem("pendingLoginResendUntil")) || 0; } catch { /* UX only */ }
      try { resendLimited = sessionStorage.getItem("pendingLoginResendLimited") === "1"; } catch { /* UX only */ }
      try { verifyUntil = Number(sessionStorage.getItem("pendingLoginVerifyUntil")) || 0; } catch { /* UX only */ }
      try { codeExhausted = sessionStorage.getItem("pendingLoginCodeExhausted") === "1"; } catch { /* UX only */ }
      try { transactionExpiresAt = Number(sessionStorage.getItem("pendingLoginExpiresAt")) || 0; } catch { /* UX only */ }
      startResendCountdown(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)), resendLimited);
      if (!transactionExpired) checkDelivery(API.getEmailDeliveryToken("login"));
    }
  }

  const loginOtp = window.VerificationCode.bind(loginCodeInput,
    [...stateLoginCode.querySelectorAll(".verification-code-cell")], () => {
      updateLoginControls();
      if (!codeExhausted && Date.now() >= verifyUntil) clearLoginCodeMessage();
    });

  loginCodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const code = loginCodeInput.value;
    updateLoginControls();
    if (loginBusy || transactionExpired || codeExhausted || Date.now() < verifyUntil || !/^\d{6}$/.test(code)) return;
    clearLoginCodeMessage();

    setLoginCodeBusy(true);

    try {
      const response = await API.confirmLoginCode(code);
      transactionExpiresAt = 0;
      clearInterval(resendCodeTimer);
      stopDeliveryCheck();
      successTitle.textContent = "Sign-in verified";
      successMessage.textContent = "Redirecting to your account…";
      showState("success");
      setTimeout(() => {
        window.location.replace(
          ["admin", "staff"].includes(response?.user?.role)
            ? "../admin/dashboard.html"
            : "./dashboard.html",
        );
      }, 750);
    } catch (error) {
      if (error.expired || error.status === 403) {
        transactionExpired = true;
        clearInterval(resendCodeTimer);
        stopDeliveryCheck();
        errorTitle.textContent = "Sign-In Confirmation Failed";
        errorMessage.textContent = error.message
          || "This sign-in request is no longer available. Please sign in again.";
        showState("error");
      } else if (error.status === 429 && error.code === "login_code_locked" && error.retryAfter) {
        startVerifyLockout(error.retryAfter);
        updateLoginControls();
      } else if (error.code === "login_code_exhausted") {
        markCodeExhausted(true);
        showLoginCodeMessage("Request a new code to continue.", false);
      } else {
        showLoginCodeMessage(error.message || "Couldn't verify the code. Try again.");
      }
    } finally {
      setLoginCodeBusy(false);
    }
  });

  function startVerifyLockout(seconds) {
    verifyUntil = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    markCodeExhausted(true);
    try { sessionStorage.setItem("pendingLoginVerifyUntil", String(verifyUntil)); } catch { /* UX only */ }
  }

  function markCodeExhausted(exhausted) {
    codeExhausted = exhausted;
    try { sessionStorage.setItem("pendingLoginCodeExhausted", exhausted ? "1" : "0"); } catch { /* UX only */ }
  }

  function updateLoginControls() {
    const remaining = Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000));
    const locked = Math.max(0, Math.ceil((verifyUntil - Date.now()) / 1000));
    if (transactionExpiresAt && Date.now() >= transactionExpiresAt) {
      transactionExpired = true;
      clearInterval(resendCodeTimer);
      resendCodeTimer = null;
      stopDeliveryCheck();
      errorTitle.textContent = "Sign-in expired";
      errorMessage.textContent = "Sign-in expired. Please sign in again.";
      showState("error");
    }
    loginCodeResendBtn.disabled = remaining > 0 || loginBusy || locked > 0 || transactionExpired;
    loginCodeInput.disabled = loginBusy || locked > 0 || codeExhausted || transactionExpired;
    loginCodeSubmit.disabled = loginCodeInput.disabled || loginCodeInput.value.length !== 6;
    loginCodeResendLock.classList.toggle("hidden", !locked || transactionExpired);
    loginCodeResendBtn.classList.toggle("hidden", remaining > 0 && !locked);
    loginCodeResendCountdown.classList.toggle("hidden", remaining === 0 || locked > 0);
    loginCodeResendCountdown.textContent = locked ? "" : resendLimited
      ? window.AuthenticationResend.message(remaining)
      : `Resend code in ${window.VerificationCode.formatCountdown(remaining)}`;
    if (locked && !transactionExpired) {
      const time = window.VerificationCode.formatCountdown(locked, true);
      showLoginCodeMessage(`Too many incorrect codes. Request a new code in ${time}.`);
    } else if (verifyUntil) {
      verifyUntil = 0;
      try { sessionStorage.removeItem("pendingLoginVerifyUntil"); } catch { /* UX only */ }
      clearLoginCodeMessage();
    }
  }

  function startResendCountdown(seconds = 0, limited = false) {
    resendLimited = limited;
    try { sessionStorage.setItem("pendingLoginResendLimited", limited ? "1" : "0"); } catch { /* UX only */ }
    clearInterval(resendCodeTimer);
    resendUntil = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    try { sessionStorage.setItem("pendingLoginResendUntil", String(resendUntil)); } catch { /* UX only */ }
    resendCodeTimer = setInterval(updateLoginControls, 1000);
    updateLoginControls();
  }

  window.addEventListener("pagehide", () => {
    clearInterval(resendCodeTimer);
    resendCodeTimer = null;
    stopDeliveryCheck();
  });
  window.addEventListener("pageshow", event => {
    if (!event.persisted || !loginCodeMode || !API.hasPendingLoginConfirmation()) return;
    startResendCountdown(Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000)), resendLimited);
    if (!transactionExpired) checkDelivery(API.getEmailDeliveryToken("login"));
  });

  loginCodeResendBtn.addEventListener("click", async () => {
    updateLoginControls();
    if (loginCodeResendBtn.disabled || loginBusy || transactionExpired) return;
    clearLoginCodeMessage();
    stopDeliveryCheck();
    loginBusy = true;
    updateLoginControls();
    // Preserve the entered code when a resend is rejected.
    loginCodeResendBtn.disabled = true;
    loginCodeResendLabel.textContent = "Sending...";
    loginCodeSubmit.disabled = true;
    let issued = false;
    try {
      const result = await API.resendLoginCode();
      issued = true;
      markCodeExhausted(false);
      clearLoginCodeMessage();
      loginOtp.clear();
      startResendCountdown(window.AuthenticationResend.seconds(result), window.AuthenticationResend.limited(result));
      checkDelivery(result?.email_delivery_token);
    } catch (error) {
      if (error.status === 429) {
        const locked = error.code === "login_code_locked";
        if (locked) {
          startVerifyLockout(window.AuthenticationResend.seconds(error));
          updateLoginControls();
        } else {
          startResendCountdown(window.AuthenticationResend.seconds(error), window.AuthenticationResend.limited(error));
          showLoginCodeMessage(window.AuthenticationResend.limited(error)
            ? window.AuthenticationResend.message(window.AuthenticationResend.seconds(error)) : error.message, false);
        }
      } else if (error.expired || error.status === 422 || error.status === 403) {
        transactionExpired = true;
        clearInterval(resendCodeTimer);
        stopDeliveryCheck();
        errorTitle.textContent = "Sign-in expired";
        errorMessage.textContent = error.message || "Please sign in again.";
        showState("error");
      } else {
        showLoginCodeMessage("Couldn't send the code. Try again.", false);
      }
    } finally {
      loginCodeResendLabel.textContent = "Resend Code";
      setLoginCodeBusy(false);
      if (issued && !transactionExpired) loginCodeInput.focus();
    }
  });

  resendForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (resendBusy) return;
    clearResendMessage();

    const email = resendEmail.value.trim();
    if (!email) return;

    setResendBusy(true);

    try {
      const result = await API.resendVerification(email);
      sessionStorage.setItem("pendingVerificationEmail", email);
      sessionStorage.setItem("pendingVerificationRegistrationUntil", String(window.SignupContext.read(email)?.expiresAt || 0));
      sessionStorage.setItem("pendingVerificationResendUntil", String(Date.now() + window.AuthenticationResend.seconds(result) * 1000));
      sessionStorage.setItem("pendingVerificationResendLimited", window.AuthenticationResend.limited(result) ? "1" : "0");
      sessionStorage.setItem("pendingVerificationState", result.registration_state === "requested" ? "requested" : result.email_delivery_token ? "queued" : "");
      sessionStorage.removeItem("pendingVerificationDeliveryFailed");
      window.location.replace("./verify-email.html");
    } catch (error) {
      if (error.status === 429) {
        showResendMessage("error", error.message || "Too many requests. Please wait before trying again.");
      } else {
        showResendMessage("error", error.message || "Failed to resend. Please try again.");
      }
    } finally {
      setResendBusy(false);
    }
  });

  function setResendBusy(busy) {
    resendBusy = busy;
    resendSubmit.disabled = busy;
    const label = document.getElementById("resendBtnLabel");
    if (label) label.textContent = busy ? "Sending…" : "Resend Verification Email";
  }

  function setLoginCodeBusy(busy) {
    loginBusy = busy;
    updateLoginControls();
    const label = document.getElementById("loginCodeBtnLabel");
    if (label) label.textContent = busy ? "Verifying..." : "Verify and continue";
  }

  function showLoginCodeMessage(text, invalid = true) {
    loginCodeMessage.className = "verification-message";
    loginCodeMessage.textContent = text;
    loginCodeMessage.setAttribute("role", Date.now() < verifyUntil ? "timer" : "alert");
    loginCodeMessage.setAttribute("aria-live", Date.now() < verifyUntil ? "off" : "polite");
    loginCodeInput.setAttribute("aria-invalid", invalid ? "true" : "false");
  }

  function clearLoginCodeMessage() {
    loginCodeMessage.className = "hidden";
    loginCodeMessage.textContent = "";
    loginCodeInput.setAttribute("aria-invalid", "false");
  }

  function showResendMessage(type, text) {
    const styles = {
      success: "border-green-200 bg-green-50 text-green-700",
      error: "border-red-200 bg-red-50 text-red-700",
    };
    resendMessage.className = `mt-3 rounded-2xl border px-4 py-3 text-sm ${styles[type] || styles.error}`;
    resendMessage.textContent = text;
    resendMessage.classList.remove("hidden");
  }

  function clearResendMessage() {
    resendMessage.className = "mt-3 hidden rounded-2xl border px-4 py-3 text-sm";
    resendMessage.textContent = "";
  }
});
