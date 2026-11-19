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
  const loginCodeResendLabel = document.getElementById("loginCodeResendLabel");

  const cooldownStatus = document.getElementById("loginCooldownStatus");
  let resendCooling = false;
  let verifyMessage = "";
  let resendCodeTimer = null;
  let resendUntil = 0;
  let verifyUntil = 0;
  let transactionExpiresAt = 0;
  let transactionExpired = false;
  let loginBusy = false;
  let resendLimited = false;
  let deliveryFeedback = "";
  let stopDeliveryCheck = () => {};

  function checkDelivery(receipt) {
    stopDeliveryCheck();
    deliveryFeedback = "";
    if (!receipt) return;
    stopDeliveryCheck = window.AuthenticationEmailDelivery.watch(receipt, (status) => {
      deliveryFeedback = window.AuthenticationEmailDelivery.failureMessage(status);
      if (deliveryFeedback && Date.now() >= verifyUntil && !transactionExpired) {
        showLoginCodeMessage(deliveryFeedback, false);
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
    let verifyLinkCoolingDown = false;
    let stopLinkCountdown;
    window.addEventListener("pagehide", event => { if (!event.persisted) stopLinkCountdown?.(); });
    const back=document.getElementById('verificationBackAction');
    back.textContent='Back to sign up';back.setAttribute('href','/sign-up');
    button.addEventListener('click',async()=>{
      if(verifying || verifyLinkCoolingDown || !verificationToken)return;
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
        } else if(!error.status || error.status>=500 || error.status===429 || error.status===419) {
          showState('link');feedback.textContent=error.message || 'Connection lost. Try again.';feedback.classList.remove('hidden');
          if (error.retryAfter > 0) {
            const label = button.textContent;
            stopLinkCountdown = window.VerificationCode.startCountdown(error.retryAfter, seconds => {
              verifyLinkCoolingDown = button.disabled = Boolean(seconds);
              button.textContent = seconds
                ? 'Try again in ' + window.VerificationCode.formatCountdown(seconds) : label;
              if (!seconds) feedback.textContent = 'You can try verifying your email again.';
            });
          }
        } else {
          errorTitle.textContent=error.code==='registration_expired' ? 'Registration expired'
            : error.code==='registration_link_expired' ? 'Link expired' : 'Link unavailable';
          errorMessage.textContent=error.message || 'Return to your signup page to request a new link.';showState('error');
        }
      } finally {
        verifying=false;
        if (!verifyLinkCoolingDown) button.disabled=false;
      }
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
      try { transactionExpiresAt = Number(sessionStorage.getItem("pendingLoginExpiresAt")) || 0; } catch { /* UX only */ }
      startResendCountdown(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)), resendLimited);
      if (!transactionExpired) checkDelivery(API.getEmailDeliveryToken("login"));
    }
  }

  const loginOtp = window.VerificationCode.bind(loginCodeInput,
    [...stateLoginCode.querySelectorAll(".verification-code-cell")], () => {
      updateLoginControls();
      if (Date.now() >= verifyUntil) clearLoginCodeMessage();
    });

  loginCodeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const code = loginCodeInput.value;
    updateLoginControls();
    if (loginBusy || transactionExpired || Date.now() < verifyUntil || !/^\d{6}$/.test(code)) return;
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
      } else if (error.status === 429 && window.AuthenticationResend.seconds(error)) {
        startVerifyLockout(window.AuthenticationResend.seconds(error), error.code);
      } else {
        showLoginCodeMessage(error.message || "Couldn't verify the code. Try again.");
      }
    } finally {
      setLoginCodeBusy(false);
    }
  });

  function startVerifyLockout(seconds, code) {
    verifyUntil = Date.now() + seconds * 1000;
    verifyMessage = code === "login_code_locked" ? "Too many incorrect codes." : "Verification temporarily unavailable.";
    try { sessionStorage.setItem("pendingLoginVerifyUntil", String(verifyUntil)); } catch { /* UX only */ }
    if (cooldownStatus) cooldownStatus.textContent = verifyMessage + " Verification is temporarily paused.";
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
    loginCodeResendBtn.disabled = remaining > 0 || loginBusy || transactionExpired;
    loginCodeInput.disabled = loginBusy || locked > 0 || transactionExpired;
    loginCodeSubmit.disabled = loginCodeInput.disabled || loginCodeInput.value.length !== 6;
    loginCodeResendBtn.classList.toggle("hidden", remaining > 0);
    loginCodeResendCountdown.classList.toggle("hidden", remaining === 0);
    loginCodeResendCountdown.textContent = !remaining ? "" : resendLimited
      ? window.AuthenticationResend.message(remaining)
      : `Resend code in ${window.VerificationCode.formatCountdown(remaining, true)}`;
    if (Boolean(remaining) !== resendCooling && cooldownStatus && !transactionExpired) {
      cooldownStatus.textContent = remaining ? "Resending is temporarily paused." : "You can resend the code now.";
    }
    resendCooling = Boolean(remaining);
    if (locked && !transactionExpired) {
      const time = window.VerificationCode.formatCountdown(locked, true);
      showLoginCodeMessage(`${verifyMessage || "Verification temporarily paused."} Try again in ${time}.`, false);
    } else if (verifyUntil) {
      verifyUntil = 0;
      try { sessionStorage.removeItem("pendingLoginVerifyUntil"); } catch { /* UX only */ }
      clearLoginCodeMessage();
      if (!transactionExpired) {
        if (deliveryFeedback) showLoginCodeMessage(deliveryFeedback, false);
        if (cooldownStatus) cooldownStatus.textContent = "You can verify the code now.";
        if (document.activeElement === document.body) loginCodeInput.focus();
      }
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
      clearLoginCodeMessage();
      loginOtp.clear();
      startResendCountdown(window.AuthenticationResend.seconds(result), window.AuthenticationResend.limited(result));
      checkDelivery(result?.email_delivery_token);
    } catch (error) {
      if (error.status === 429) {
        const seconds = window.AuthenticationResend.seconds(error);
        if (seconds) {
          if (error.code === "login_code_locked") startVerifyLockout(seconds, error.code);
          startResendCountdown(seconds, window.AuthenticationResend.limited(error));
        } else {
          showLoginCodeMessage(error.message, false);
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
