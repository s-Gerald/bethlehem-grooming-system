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
  const codeMessage = document.getElementById("verifyCodeMessage");
  const newPasswordMessage = document.getElementById("newPasswordMessage");
  const confirmPasswordMessage = document.getElementById("confirmPasswordMessage");
  const passwordMessage = document.getElementById("resetPasswordMessage");
  const recoveryKey = "passwordResetRecovery";
  let step = "email";
  let requestedEmail = "";
  let maskedEmail = "";
  let resetGrant = "";
  let expiresAt = 0;
  let resendUntil = 0;
  let verifyUntil = 0;
  let resetUntil = 0;
  let failedAttempts = 0;
  let timer = null;
  let busy = false;
  let completed = false;

  // Only recovery credentials and deadlines survive reloads; never passwords or OTPs.
  try {
    const saved = JSON.parse(sessionStorage.getItem(recoveryKey) || "null");
    if (saved && typeof saved.requestedEmail === "string" && ["email", "confirmation", "code", "password"].includes(saved.step)) {
      ({ requestedEmail, maskedEmail, resetGrant, expiresAt, resendUntil, verifyUntil, resetUntil, failedAttempts, step } = saved);
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
    try {
      sessionStorage.setItem(recoveryKey, JSON.stringify({
        requestedEmail, maskedEmail, resetGrant, expiresAt, resendUntil, verifyUntil, resetUntil, failedAttempts, step,
      }));
    } catch { /* Continue in memory when storage is unavailable. */ }
  }

  installVisibilityToggle("toggleNewPassword", password, ".new-password-icon-closed", ".new-password-icon-open", "new password");
  installVisibilityToggle("toggleConfirmNewPassword", confirmation, ".confirm-password-icon-closed", ".confirm-password-icon-open", "password confirmation");

  function showStep(next) {
    step = next;
    const content = {
      email: ["Forgot Password", "Enter your email address to request a verification code."],
      confirmation: ["Check Your Email", "If you receive a code, enter it to continue."],
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
    document.getElementById("registrationRecovery").classList.toggle("hidden", !["email", "confirmation"].includes(next));
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
    setFieldError(password, newPasswordMessage);
    setFieldError(confirmation, confirmPasswordMessage);
    showStep("email");
    setError(emailMessage, message);
    saveRecovery();
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
    if (!email) return;
    if (email !== requestedEmail) failedAttempts = 0;
    requestedEmail = email;
    busy = true;
    setError(emailMessage);
    updateControls();
    try {
      acceptRequest(await API.requestPasswordReset(requestedEmail), "confirmation");
    } catch (error) {
      setError(emailMessage, error?.status === 429 ? "Too many requests. Please wait before trying again."
        : error?.status === 0 ? error.message : "Couldn't request a code. Try again.");
      resendUntil = Date.now() + (error?.retryAfter || 0) * 1000;
      saveRecovery();
      startTimer();
    } finally {
      busy = false;
      tick();
      if (step === "confirmation") enterCode.focus();
    }
  });

  resend.addEventListener("click", async () => {
    if (step !== "code" || resetGrant || resend.disabled || busy || !requestedEmail) return;
    busy = true;
    otp.clear();
    setError(codeMessage);
    updateControls();
    try {
      acceptRequest(await API.requestPasswordReset(requestedEmail));
    } catch (error) {
      setError(codeMessage, error?.status === 429 ? "Too many requests. Please wait before trying again."
        : "Couldn't request a code. Try again.");
      resendUntil = Date.now() + (error?.retryAfter || 0) * 1000;
      saveRecovery();
      startTimer();
    } finally {
      busy = false;
      tick();
      if (!codeInput.disabled) codeInput.focus();
    }
  });

  codeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy || step !== "code" || codeSubmit.disabled || resetGrant) return;
    setError(codeMessage);
    busy = true;
    updateControls();
    try {
      const response = await API.verifyPasswordResetCode(requestedEmail, codeInput.value.trim());
      resetGrant = response.token;
      expiresAt = Date.now() + response.expires_in * 1000;
      otp.clear();
      password.value = "";
      confirmation.value = "";
      showStep("password");
    } catch (error) {
      if (error?.status === 422) failedAttempts++;
      if (failedAttempts >= 5) resendUntil = Math.max(resendUntil, expiresAt);
      if (error?.retryAfter) verifyUntil = Date.now() + error.retryAfter * 1000;
      setError(codeMessage, error?.status === 429 ? "Too many attempts. Please wait before trying again."
        : error?.status !== 422 ? "Couldn't verify the code. Try again."
        : failedAttempts >= 5 ? "Too many incorrect attempts. Wait before requesting a new code."
        : "Invalid or expired code. Check it or request a new code.");
      codeInput.setAttribute("aria-invalid", error?.status === 422 ? "true" : "false");
    } finally {
      busy = false;
      saveRecovery();
      startTimer();
    }
  });

  password.addEventListener("input", () => setFieldError(password, newPasswordMessage));
  confirmation.addEventListener("input", () => setFieldError(confirmation, confirmPasswordMessage));
  emailInput.addEventListener("input", () => setError(emailMessage));

  passwordForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (completed || busy || step !== "password" || passwordSubmit.disabled) return;
    if (!resetGrant || remaining(expiresAt) === 0) {
      restartRecovery("Your reset session expired. Request a new code.");
      tick();
      return;
    }
    setError(passwordMessage);
    setFieldError(password, newPasswordMessage);
    setFieldError(confirmation, confirmPasswordMessage);
    const validationError = validatePasswords(password.value, confirmation.value);
    if (validationError) {
      if (validationError.field === "confirmation") setFieldError(confirmation, confirmPasswordMessage, validationError.message);
      else setFieldError(password, newPasswordMessage, validationError.message);
      return;
    }
    busy = true;
    updateControls();
    try {
      await API.resetPassword(resetGrant, password.value, confirmation.value);
      completed = true;
      resetGrant = "";
      expiresAt = 0;
      password.value = "";
      confirmation.value = "";
      stopTimer();
      try { sessionStorage.removeItem(recoveryKey); } catch { /* Storage unavailable. */ }
      window.location.replace("./sign-in.html?password_reset=1");
    } catch (error) {
      const message = firstApiError(error);
      if (error?.expired) restartRecovery("Your reset session expired. Request a new code.");
      else if (error?.errors?.password_confirmation) setFieldError(confirmation, confirmPasswordMessage, error.errors.password_confirmation[0] || message);
      else if (error?.errors?.password || message === "Choose a password that is different from the current password.") {
        setFieldError(password, newPasswordMessage, error?.errors?.password?.[0] || message);
      } else setError(passwordMessage, error?.status === 429 ? "Too many attempts. Please wait before trying again." : message);
      if (error?.retryAfter) resetUntil = Date.now() + error.retryAfter * 1000;
      saveRecovery();
    } finally {
      busy = false;
      if (!completed) startTimer();
    }
  });

  window.addEventListener("pagehide", stopTimer);
  window.addEventListener("pageshow", () => {
    // A completed reset must not be restored from the browser's back/forward cache.
    try {
      if (completed || (resetGrant && !sessionStorage.getItem(recoveryKey))) restartRecovery("");
    } catch { /* In-memory recovery remains available. */ }
    startTimer();
  });
  showStep(resetGrant ? "password" : step);
  startTimer();

  function validatePasswords(value, confirmationValue) {
    if (!value) return { field: "password", message: "Complete both password fields." };
    if (!confirmationValue) return { field: "confirmation", message: "Complete both password fields." };
    if (value !== confirmationValue) return { field: "confirmation", message: "The passwords do not match." };
    if (value.length < 12 || !/[a-z]/.test(value) || !/[A-Z]/.test(value) || !/\d/.test(value) || !/[^A-Za-z0-9]/.test(value)) {
      return { field: "password", message: "Use at least 12 characters with uppercase, lowercase, a number, and a symbol." };
    }
    return null;
  }

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

  function setFieldError(input, element, message = "") {
    element.className = message ? "verification-message text-left" : "hidden";
    element.textContent = message;
    input.setAttribute("aria-invalid", message ? "true" : "false");
  }

  function installVisibilityToggle(buttonId, input, closedSelector, openSelector, label) {
    const button = document.getElementById(buttonId);
    const closedIcon = document.querySelector(closedSelector);
    const openIcon = document.querySelector(openSelector);
    button.addEventListener("click", () => {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      button.setAttribute("aria-label", `${show ? "Hide" : "Show"} ${label}`);
      closedIcon.classList.toggle("hidden", show);
      openIcon.classList.toggle("hidden", !show);
    });
  }
});
