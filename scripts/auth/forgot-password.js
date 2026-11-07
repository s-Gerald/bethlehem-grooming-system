document.addEventListener("DOMContentLoaded", () => {
  const emailForm = document.getElementById("forgotPasswordForm");
  const codeForm = document.getElementById("verifyCodeForm");
  const passwordForm = document.getElementById("resetPasswordForm");
  if (!emailForm || !codeForm || !passwordForm) return;
  const emailInput = document.getElementById("resetEmail");
  const codeInput = document.getElementById("verificationCode");
  const password = document.getElementById("newPassword");
  const confirmation = document.getElementById("confirmNewPassword");
  const emailSubmit = document.getElementById("forgotPasswordSubmit");
  const emailLabel = document.getElementById("forgotPasswordSubmitLabel");
  const codeSubmit = document.getElementById("verifyCodeSubmit");
  const passwordSubmit = document.getElementById("resetPasswordSubmit");
  const back = document.getElementById("forgotBack");
  const title = document.getElementById("forgotTitle");
  const description = document.getElementById("forgotDescription");
  const verifyDescription = document.getElementById("verifyDescription");
  const verifyEmail = document.getElementById("verifyEmail");
  const requestConfirmation = document.getElementById("passwordResetConfirmation");
  const enterCode = document.getElementById("enterResetCode");
  const codeCells = [...document.querySelectorAll(".verification-code-cell")];
  const resend = document.getElementById("resendCode");
  const emailMessage = document.getElementById("forgotPasswordMessage");
  const createAccount = document.getElementById("passwordResetCreateAccount");
  let emailRevision = 0;
  const codeMessage = document.getElementById("verifyCodeMessage");
  const newPasswordMessage = document.getElementById("newPasswordMessage");
  const confirmPasswordMessage = document.getElementById("confirmPasswordMessage");
  const passwordMessage = document.getElementById("resetPasswordMessage");
  const recoveryKey = "passwordResetRecovery";
  let step = "email";
  let requestedEmail = "";
  let maskedEmail = "";
  let resetGrant = "";
  let passwordMinimum = 15;
  let expiresAt = 0;
  let resendUntil = 0;
  let verifyUntil = 0;
  let resetUntil = 0;
  let failedAttempts = 0;
  let timer = null;
  let busy = false;
  let completed = false;
  let departed = false;
  let journey = 0;

  // Only a reload may resume this journey. Links and history navigation start fresh.
  try {
    const reloading = window.performance?.getEntriesByType("navigation")[0]?.type === "reload";
    if (!reloading) sessionStorage.removeItem(recoveryKey);
    const saved = reloading ? JSON.parse(sessionStorage.getItem(recoveryKey) || "null") : null;
    if (saved && typeof saved.requestedEmail === "string" && ["email", "confirmation", "code", "password"].includes(saved.step)) {
      ({ requestedEmail, maskedEmail, resetGrant, expiresAt, resendUntil, verifyUntil, resetUntil, failedAttempts, step } = saved);
      passwordMinimum = saved.passwordMinimum === 8 ? 8 : 15;
      emailInput.value = requestedEmail;
    } else {
      sessionStorage.removeItem(recoveryKey);
    }
    const email = sessionStorage.getItem("pendingPasswordResetEmail");
    sessionStorage.removeItem("pendingPasswordResetEmail");
    if (email && !resetGrant) {
      emailInput.value = email;
      step = "email";
    }
  } catch { /* Storage is optional; server-side limits remain authoritative. */ }

  function saveRecovery() {
    if (departed) return;
    try {
      sessionStorage.setItem(recoveryKey, JSON.stringify({
        requestedEmail, maskedEmail, resetGrant, passwordMinimum, expiresAt, resendUntil, verifyUntil, resetUntil, failedAttempts, step,
      }));
    } catch { /* Continue in memory when storage is unavailable. */ }
  }

  const hidePassword = installVisibilityToggle("toggleNewPassword", password, ".new-password-icon-closed", ".new-password-icon-open", "new password");
  const hideConfirmation = installVisibilityToggle("toggleConfirmNewPassword", confirmation, ".confirm-password-icon-closed", ".confirm-password-icon-open", "password confirmation");

  function showStep(next) {
    step = next;
    const content = {
      email: ["Forgot Password", "Enter your email address to request a verification code."],
      confirmation: ["Check Your Email", "Check your email for a verification code."],
      code: ["Check Your Email", ""],
      password: ["Create New Password", "Set a strong password to secure your account."],
    }[next];
    title.textContent = content[0];
    description.textContent = content[1];
    description.classList.toggle("hidden", next === "code");
    verifyDescription.classList.toggle("hidden", next !== "code");
    verifyEmail.classList.toggle("hidden", next !== "code");
    if (next === "code") verifyEmail.textContent = maskedEmail || window.VerificationCode.maskEmail(requestedEmail);
    emailForm.classList.toggle("hidden", next !== "email");
    requestConfirmation.classList.toggle("hidden", next !== "confirmation");
    codeForm.classList.toggle("hidden", next !== "code");
    passwordForm.classList.toggle("hidden", next !== "password");
    back.classList.toggle("hidden", !["confirmation", "code"].includes(next));
    if (next === "confirmation") enterCode.focus();
    if (next === "code") codeInput.focus();
    if (next === "password") password.focus();
    if (next === "email") emailInput.focus();
  }

  const otp = window.VerificationCode.bind(codeInput, codeCells, () => {
    if (failedAttempts < 5 && expiresAt > Date.now()) setError(codeMessage);
    codeInput.setAttribute("aria-invalid", "false");
    updateControls();
  });

  function remaining(until) { return Math.max(0, Math.ceil((until - Date.now()) / 1000)); }

  function updateControls() {
    const cooldown = remaining(resendUntil);
    const codeUnavailable = expiresAt <= Date.now() || failedAttempts >= 5;
    emailSubmit.disabled = busy || cooldown > 0;
    emailLabel.textContent = busy && step === "email" ? "Sending..." : cooldown > 0
      ? `Send Code in ${window.VerificationCode.formatCountdown(cooldown)}` : "Send Code";
    resend.disabled = step !== "code" || busy || cooldown > 0 || (failedAttempts >= 5 && remaining(expiresAt) > 0);
    resend.textContent = busy && step === "code" ? "Please wait..." : cooldown > 0
      ? `Resend in ${window.VerificationCode.formatCountdown(cooldown)}` : "Resend code";
    codeInput.disabled = busy || codeUnavailable || remaining(verifyUntil) > 0;
    codeSubmit.disabled = step !== "code" || codeInput.disabled || !/^[0-9]{6}$/.test(codeInput.value);
    codeSubmit.textContent = busy && step === "code" ? "Verifying..." : remaining(verifyUntil) > 0
      ? `Continue in ${window.VerificationCode.formatCountdown(remaining(verifyUntil))}` : "Continue";
    back.disabled = busy;
    enterCode.disabled = busy;
    passwordSubmit.disabled = busy || remaining(resetUntil) > 0;
    passwordSubmit.textContent = busy && step === "password" ? "Resetting..." : remaining(resetUntil) > 0
      ? `Reset Password in ${window.VerificationCode.formatCountdown(remaining(resetUntil))}` : "Reset Password";
  }

  function restartRecovery(message) {
    completed = false;
    resetGrant = "";
    expiresAt = 0;
    failedAttempts = 0;
    otp.clear();
    password.value = "";
    confirmation.value = "";
    setError(passwordMessage);
    passwordBinding.clear();
    confirmationBinding.clear();
    showStep("email");
    setError(emailMessage, message);
    createAccount.classList.toggle("hidden", true);
    saveRecovery();
  }

  function abandonRecovery() {
    requestedEmail = "";
    maskedEmail = "";
    resendUntil = verifyUntil = resetUntil = 0;
    busy = false;
    emailInput.value = "";
    verifyEmail.textContent = "";
    setError(codeMessage);
    codeInput.setAttribute("aria-invalid", "false");
    emailInput.setAttribute("aria-invalid", "false");
    hidePassword();
    hideConfirmation();
    restartRecovery("");
    updateControls();
  }

  function tick() {
    if (!busy && expiresAt && remaining(expiresAt) === 0) {
      if (resetGrant) restartRecovery("Your reset session expired. Request a new code.");
      else if (step === "code") setError(codeMessage, "Code expired. Request a new code.");
    }
    updateControls();
    if (![expiresAt, resendUntil, verifyUntil, resetUntil].some(until => remaining(until) > 0)) stopTimer();
  }

  function stopTimer() {
    if (timer !== null) window.clearInterval(timer);
    timer = null;
  }

  function startTimer() {
    if (timer === null) timer = window.setInterval(tick, 1000);
    tick();
  }

  function acceptRequest(response, next = "code") {
    if (expiresAt <= Date.now()) failedAttempts = 0;
    maskedEmail = response?.email || window.VerificationCode.maskEmail(requestedEmail);
    expiresAt = Date.now() + response.expires_in * 1000;
    resendUntil = Date.now() + (response.resend_after ?? 45) * 1000;
    otp.clear();
    setError(codeMessage);
    showStep(next);
    saveRecovery();
    startTimer();
  }

  back.addEventListener("click", () => {
    if (!["confirmation", "code"].includes(step) || busy || resetGrant) return;
    otp.clear();
    setError(emailMessage);
    showStep("email");
    saveRecovery();
    updateControls();
  });

  enterCode.addEventListener("click", () => {
    if (step !== "confirmation" || busy || !requestedEmail) return;
    showStep("code");
    saveRecovery();
    tick();
  });

  emailForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy || step !== "email" || emailSubmit.disabled) return;
    const email = emailInput.value.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setError(emailMessage, "Enter a valid email address.");
      emailInput.setAttribute("aria-invalid", "true");
      emailInput.focus();
      return;
    }
    const submittedEmailRevision = emailRevision;
    createAccount.classList.toggle("hidden", true);
    emailInput.setAttribute("aria-invalid", "false");
    if (email !== requestedEmail) failedAttempts = 0;
    requestedEmail = email;
    const currentJourney = journey;
    busy = true;
    setError(emailMessage);
    updateControls();
    try {
      const response = await API.requestPasswordReset(requestedEmail);
      if (currentJourney !== journey || submittedEmailRevision !== emailRevision) return;
      acceptRequest(response, "confirmation");
    } catch (error) {
      if (currentJourney !== journey) return;
      if (submittedEmailRevision !== emailRevision) return;
      const accountStatus = ["account_not_found", "password_reset_unavailable"].includes(error?.code);
      setError(emailMessage, accountStatus ? error.message : error?.status === 429 ? "Too many requests. Please wait before trying again."
        : error?.status === 422 ? "Enter a valid email address."
        : error?.status === 0 ? error.message : "Couldn't request a code. Try again.");
      createAccount.classList.toggle("hidden", error?.code !== "account_not_found");
      emailInput.setAttribute("aria-invalid", String(accountStatus || error?.status === 422));
      if (accountStatus || error?.status === 422) emailInput.focus();
      resendUntil = Date.now() + (error?.retryAfter || 0) * 1000;
      saveRecovery();
      startTimer();
    } finally {
      if (currentJourney === journey) {
        busy = false;
        tick();
        if (step === "confirmation") enterCode.focus();
      }
    }
  });

  resend.addEventListener("click", async () => {
    if (step !== "code" || resetGrant || resend.disabled || busy || !requestedEmail) return;
    const currentJourney = journey;
    busy = true;
    otp.clear();
    setError(codeMessage);
    updateControls();
    try {
      const response = await API.requestPasswordReset(requestedEmail);
      if (currentJourney !== journey) return;
      acceptRequest(response);
    } catch (error) {
      if (currentJourney !== journey) return;
      setError(codeMessage, error?.status === 429 ? "Too many requests. Please wait before trying again."
        : "Couldn't request a code. Try again.");
      resendUntil = Date.now() + (error?.retryAfter || 0) * 1000;
      saveRecovery();
      startTimer();
    } finally {
      if (currentJourney === journey) {
        busy = false;
        tick();
        if (!codeInput.disabled) codeInput.focus();
      }
    }
  });

  codeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy || step !== "code" || codeSubmit.disabled || resetGrant) return;
    const currentJourney = journey;
    setError(codeMessage);
    busy = true;
    updateControls();
    try {
      const response = await API.verifyPasswordResetCode(requestedEmail, codeInput.value.trim());
      if (currentJourney !== journey) return;
      resetGrant = response.token;
      passwordMinimum = response.password_min_length === 8 ? 8 : 15;
      updatePasswordHelp();
      expiresAt = Date.now() + response.expires_in * 1000;
      otp.clear();
      password.value = "";
      confirmation.value = "";
      showStep("password");
    } catch (error) {
      if (currentJourney !== journey) return;
      if (error?.status === 422) failedAttempts++;
      if (failedAttempts >= 5) resendUntil = Math.max(resendUntil, expiresAt);
      if (error?.retryAfter) verifyUntil = Date.now() + error.retryAfter * 1000;
      setError(codeMessage, error?.status === 429 ? "Too many attempts. Please wait before trying again."
        : error?.status !== 422 ? "Couldn't verify the code. Try again."
        : failedAttempts >= 5 ? "Too many incorrect attempts. Wait before requesting a new code."
        : "Invalid or expired code. Check it or request a new code.");
      codeInput.setAttribute("aria-invalid", error?.status === 422 ? "true" : "false");
    } finally {
      if (currentJourney === journey) {
        busy = false;
        saveRecovery();
        startTimer();
      }
    }
  });

  newPasswordMessage.className = confirmPasswordMessage.className = "hidden verification-message text-left";
  const passwordBinding = CredentialPolicy.bind(password, { feedback: newPasswordMessage, validate: () => CredentialPolicy.password(password.value, passwordMinimum) });
  const confirmationBinding = CredentialPolicy.bind(confirmation, { feedback: confirmPasswordMessage, validate: () => CredentialPolicy.confirmation(confirmation.value, password.value) });
  passwordForm.noValidate = true;
  password.addEventListener('input', () => { setError(passwordMessage); confirmationBinding.refresh(); });
  confirmation.addEventListener('input', () => setError(passwordMessage));
  function updatePasswordHelp() {
    document.getElementById('passwordRequirements').textContent = `${passwordMinimum}–64 characters. Use English letters, numbers, or symbols. No spaces at the start or end.`;
  }
  updatePasswordHelp();
  emailInput.addEventListener("input", () => {
    emailRevision++;
    setError(emailMessage);
    createAccount.classList.toggle("hidden", true);
    emailInput.setAttribute("aria-invalid", "false");
  });

  passwordForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (completed || busy || step !== "password" || passwordSubmit.disabled) return;
    const currentJourney = journey;
    if (!resetGrant || remaining(expiresAt) === 0) {
      restartRecovery("Your reset session expired. Request a new code.");
      tick();
      return;
    }
    setError(passwordMessage);
    passwordBinding.clear();
    confirmationBinding.clear();
    const validPassword = passwordBinding.validate();
    const validConfirmation = confirmationBinding.validate();
    if (!validPassword || !validConfirmation) {
      (validPassword ? confirmation : password).focus();
      return;
    }
    busy = true;
    updateControls();
    try {
      await API.resetPassword(resetGrant, password.value, confirmation.value);
      if (currentJourney !== journey) return;
      completed = true;
      resetGrant = "";
      expiresAt = 0;
      password.value = "";
      confirmation.value = "";
      stopTimer();
      try { sessionStorage.removeItem(recoveryKey); } catch { /* Storage unavailable. */ }
      window.location.replace("/sign-in?password_reset=1");
    } catch (error) {
      if (currentJourney !== journey) return;
      const message = firstApiError(error);
      if (error?.expired) restartRecovery("Your reset session expired. Request a new code.");
      else if (error?.errors?.password_confirmation) { confirmationBinding.setError(error.errors.password_confirmation[0] || message); confirmation.focus(); }
      else if (error?.errors?.password || message === "Choose a different password.") {
        passwordBinding.setError(error?.errors?.password?.[0] || message);
        password.focus();
      } else setError(passwordMessage, error?.status === 429 ? "Too many attempts. Please wait before trying again." : message);
      if (error?.retryAfter) resetUntil = Date.now() + error.retryAfter * 1000;
      saveRecovery();
    } finally {
      if (currentJourney === journey) {
        busy = false;
        if (!completed) startTimer();
      }
    }
  });

  window.addEventListener("pagehide", () => {
    departed = true;
    journey++;
    stopTimer();
    // Leave the stored snapshot for a true reload; scrub the document before caching.
    abandonRecovery();
  });
  window.addEventListener("pageshow", (event) => {
    if (departed || completed || event?.persisted) {
      journey++;
      departed = true;
      abandonRecovery();
      try { sessionStorage.removeItem(recoveryKey); } catch { /* Storage unavailable. */ }
      departed = false;
    }
    startTimer();
  });
  showStep(resetGrant ? "password" : step);
  startTimer();

  function firstApiError(error) {
    const messages = Object.values(error?.errors || {})[0];
    return Array.isArray(messages) && messages[0] ? messages[0] : error?.message || "The password could not be reset.";
  }

  function setError(element, message = "") {
    element.className = message
      ? "verification-message"
      : "hidden verification-message";
    element.textContent = message;
  }

  function installVisibilityToggle(buttonId, input, closedSelector, openSelector, label) {
    const button = document.getElementById(buttonId);
    const closedIcon = document.querySelector(closedSelector);
    const openIcon = document.querySelector(openSelector);
    function setVisibility(show) {
      input.type = show ? "text" : "password";
      button.setAttribute("aria-label", `${show ? "Hide" : "Show"} ${label}`);
      closedIcon.classList.toggle("hidden", show);
      openIcon.classList.toggle("hidden", !show);
    }
    button.addEventListener("click", () => setVisibility(input.type === "password"));
    return () => setVisibility(false);
  }
});
