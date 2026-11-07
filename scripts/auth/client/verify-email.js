// Connected to pages/client/verify-email.html
// Deliberate signup approval, and the separate existing login OTP interface.

document.addEventListener("DOMContentLoaded", () => {
  const stateVerifying = document.getElementById("stateVerifying");
  const stateSuccess   = document.getElementById("stateSuccess");
  const stateLoginCode = document.getElementById("stateLoginCode");
  const stateError     = document.getElementById("stateError");
  const successTitle   = document.getElementById("successTitle");
  const successMessage = document.getElementById("successMessage");
  const errorTitle     = document.getElementById("errorTitle");
  const errorMessage   = document.getElementById("errorMessage");
  const loginCodeEmail = document.getElementById("loginCodeEmail");
  const loginCodeForm  = document.getElementById("loginCodeForm");
  const loginCodeInput = document.getElementById("loginCode");
  const loginCodeSubmit = document.getElementById("loginCodeSubmit");
  const loginCodeMessage = document.getElementById("loginCodeMessage");
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
    [stateVerifying, stateSuccess, stateLoginCode, stateError, document.getElementById("stateLink")]
      .forEach((element) => element.classList.add("hidden"));
    const element = {
      verifying: stateVerifying,
      success: stateSuccess,
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
  if (!verificationToken && !loginCodeMode) {
    errorTitle.textContent='Continue on your signup page';
    errorMessage.textContent='Return to the device where you started, or start a fresh registration.';
    const back=document.getElementById('verificationBackAction');back.textContent='Back to sign up';back.setAttribute('href','/sign-up');
    showState('error');return;
  }

  if (verificationToken) {
    showState('link');
    let verifying=false;
    const button=document.getElementById('verifyLinkSubmit');
    const feedback=document.getElementById('verifyLinkMessage');
    const back=document.getElementById('verificationBackAction');
    back.textContent='Back to sign up';back.setAttribute('href','/sign-up');
    button.addEventListener('click',async()=>{
      if(verifying || !verificationToken)return;
      verifying=true;button.disabled=true;feedback.textContent='';feedback.classList.add('hidden');
      showState('verifying');
      try {
        const result=await API.verifyEmail(verificationToken);
        if(!result?.registration_approved)throw new Error('Could not verify your email. Try again.');
        verificationToken='';
        successTitle.textContent='Email verified!';
        successMessage.textContent='Return to the device where you started creating your account.';
        showState('success');
      } catch(error) {
        if(error.code==='registration_link_consumed') {
          verificationToken='';successTitle.textContent='Email already verified';
          successMessage.textContent='Return to the device where you started creating your account.';showState('success');
        } else if(!error.status || error.status>=500 || error.status===429) {
          showState('link');feedback.textContent=error.message || 'Connection lost. Try again.';feedback.classList.remove('hidden');
        } else {
          errorTitle.textContent=error.code==='registration_link_expired' ? 'Link expired' : 'Link unavailable';
          errorMessage.textContent=error.message || 'Return to your signup page to request a new link.';showState('error');
        }
      } finally {verifying=false;button.disabled=false;}
    });
    return;
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
            ? "/admin/dashboard"
            : "/account",
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

});
