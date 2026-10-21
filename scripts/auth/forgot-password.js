document.addEventListener("DOMContentLoaded", () => {
  const emailForm = document.getElementById("forgotPasswordForm");
  const codeForm = document.getElementById("verifyCodeForm");
  const passwordForm = document.getElementById("resetPasswordForm");
  const emailInput = document.getElementById("resetEmail");
  const codeInput = document.getElementById("verificationCode");
  const password = document.getElementById("newPassword");
  const confirmation = document.getElementById("confirmNewPassword");
  const emailSubmit = document.getElementById("forgotPasswordSubmit");
  const codeSubmit = document.getElementById("verifyCodeSubmit");
  const passwordSubmit = document.getElementById("resetPasswordSubmit");
  const back = document.getElementById("forgotBack");
  const signIn = document.getElementById("backToSignIn");
  const title = document.getElementById("forgotTitle");
  const description = document.getElementById("forgotDescription");
  const verifyDescription = document.getElementById("verifyDescription");
  const verifyEmail = document.getElementById("verifyEmail");
  const codeCells = [...document.querySelectorAll(".verification-code-cell")];
  const resend = document.getElementById("resendCode");
  const codeMessage = document.getElementById("verifyCodeMessage");
  const newPasswordMessage = document.getElementById("newPasswordMessage");
  const confirmPasswordMessage = document.getElementById("confirmPasswordMessage");
  const passwordMessage = document.getElementById("resetPasswordMessage");
  if (!emailForm || !codeForm || !passwordForm) return;

  try {
    const email = sessionStorage.getItem("pendingPasswordResetEmail");
    sessionStorage.removeItem("pendingPasswordResetEmail");
    if (email) emailInput.value = email;
  } catch { /* storage unavailable; leave the email field empty */ }

  let step = "email";
  let requestedEmail = "";
  let maskedEmail = "";
  let resetGrant = "";
  let verifiedCode = "";
  let resendUntil = 0;
  let resendTimer = null;
  let resendBusy = false;
  let codeBusy = false;

  installVisibilityToggle("toggleNewPassword", password, ".new-password-icon-closed", ".new-password-icon-open", "new password");
  installVisibilityToggle("toggleConfirmNewPassword", confirmation, ".confirm-password-icon-closed", ".confirm-password-icon-open", "password confirmation");

  function showStep(next) {
    step = next;
    const content = {
      email: ["Forgot Password", "Enter your email address to receive a verification code."],
      code: ["Verify Your Code", ""],
      password: ["Create New Password", "Set a strong password to secure your account."],
    }[next];
    title.textContent = content[0];
    description.textContent = content[1];
    description.classList.toggle("hidden", next === "code");
    verifyDescription.classList.toggle("hidden", next !== "code");
    if (next === "code") verifyEmail.textContent = maskedEmail || "your email address";
    emailForm.classList.toggle("hidden", next !== "email");
    codeForm.classList.toggle("hidden", next !== "code");
    passwordForm.classList.toggle("hidden", next !== "password");
    back.classList.toggle("hidden", next === "email");
    signIn.classList.toggle("hidden", next !== "email");
    if (next === "code") codeInput.focus();
    if (next === "password") password.focus();
  }

  back.addEventListener("click", () => {
    setError(codeMessage);
    setError(passwordMessage);
    if (step === "password") {
      showStep("code");
    } else if (step === "code") {
      otp.clear();
      resetGrant = "";
      verifiedCode = "";
      showStep("email");
    }
  });

  const otp = window.VerificationCode.bind(codeInput, codeCells, () => {
    setError(codeMessage);
    codeInput.setAttribute("aria-invalid", "false");
    codeSubmit.disabled = resendBusy || codeBusy || codeInput.value.length !== 6;
  });
  codeSubmit.disabled = true;

  function updateResend() {
    const remaining = Math.max(0, Math.ceil((resendUntil - Date.now()) / 1000));
    resend.disabled = resendBusy || codeBusy || remaining > 0;
    codeInput.disabled = codeBusy;
    back.disabled = resendBusy || codeBusy;
    resend.textContent = resendBusy ? "Sending..." : remaining > 0 ? `Resend in ${window.VerificationCode.formatCountdown(remaining)}` : "Resend code";
    if (remaining === 0 && resendTimer) {
      window.clearInterval(resendTimer);
      resendTimer = null;
    }
  }

  function startResendCooldown(seconds = 45) {
    resendUntil = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    if (resendTimer) window.clearInterval(resendTimer);
    resendTimer = window.setInterval(updateResend, 1000);
    updateResend();
  }

  emailForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (emailSubmit.disabled) return;
    requestedEmail = emailInput.value.trim().toLowerCase();
    maskedEmail = "";
    if (!requestedEmail) return;
    emailSubmit.disabled = true;
    document.getElementById("forgotPasswordSubmitLabel").textContent = "Sending...";
    try {
      const response = await API.requestPasswordReset(requestedEmail);
      maskedEmail = response?.email || "";
      resetGrant = "";
      verifiedCode = "";
      otp.clear();
      startResendCooldown(response?.resend_after ?? 45);
      showStep("code");
    } catch (error) {
      otp.clear();
      setError(codeMessage, error?.status === 429 ? "Too many attempts. Try again later." : "Couldn't send the code. Try again.");
      startResendCooldown(error?.retryAfter ?? 0);
      showStep("code");
    } finally {
      emailSubmit.disabled = false;
      document.getElementById("forgotPasswordSubmitLabel").textContent = "Send Code";
    }
  });

  resend.addEventListener("click", async () => {
    if (resend.disabled || resendBusy || codeBusy || !requestedEmail) return;
    resendBusy = true;
    otp.clear();
    codeInput.focus();
    resetGrant = "";
    verifiedCode = "";
    updateResend();
    try {
      const response = await API.requestPasswordReset(requestedEmail);
      maskedEmail = response?.email || maskedEmail;
      verifyEmail.textContent = maskedEmail || "your email address";
      startResendCooldown(response?.resend_after ?? 45);
    } catch (error) {
      setError(codeMessage, error?.status === 429
        ? "Too many requests. Please wait before trying again."
        : "The code could not be sent. Please try again.");
      if (error?.retryAfter) startResendCooldown(error.retryAfter);
    } finally {
      resendBusy = false;
      codeSubmit.disabled = codeInput.value.length !== 6;
      updateResend();
    }
  });

  codeForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (resendBusy || codeBusy) return;
    setError(codeMessage);
    if (!/^[0-9]{6}$/.test(codeInput.value)) {
      setError(codeMessage, "Invalid or expired verification code.");
      return;
    }
    if (resetGrant) {
      if (codeInput.value.trim() === verifiedCode) showStep("password");
      else setError(codeMessage, "Invalid or expired verification code.");
      return;
    }
    codeSubmit.disabled = true;
    codeBusy = true;
    updateResend();
    try {
      const response = await API.verifyPasswordResetCode(requestedEmail, codeInput.value.trim());
      resetGrant = response.token;
      verifiedCode = codeInput.value.trim();
      password.value = "";
      confirmation.value = "";
      showStep("password");
    } catch (error) {
      setError(codeMessage, error?.status === 429 ? "Too many attempts. Please wait before trying again." : "Invalid or expired verification code.");
    } finally {
      codeBusy = false;
      codeSubmit.disabled = codeInput.value.length !== 6;
      updateResend();
    }
  });

  password.addEventListener("input", () => setFieldError(password, newPasswordMessage));
  confirmation.addEventListener("input", () => setFieldError(confirmation, confirmPasswordMessage));

  passwordForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    setError(passwordMessage);
    setFieldError(password, newPasswordMessage);
    setFieldError(confirmation, confirmPasswordMessage);
    const validationError = validatePasswords(password.value, confirmation.value);
    if (validationError) {
      if (validationError.field === "confirmation") {
        setFieldError(confirmation, confirmPasswordMessage, validationError.message);
      } else {
        setFieldError(password, newPasswordMessage, validationError.message);
      }
      return;
    }
    passwordSubmit.disabled = true;
    try {
      await API.resetPassword(resetGrant, password.value, confirmation.value);
      resetGrant = "";
      verifiedCode = "";
      window.location.replace("./sign-in.html?password_reset=1");
    } catch (error) {
      const message = firstApiError(error);
      if (error?.errors?.password_confirmation) {
        setFieldError(confirmation, confirmPasswordMessage, error.errors.password_confirmation[0] || message);
      } else if (error?.errors?.password || message === "Choose a password that is different from the current password.") {
        setFieldError(password, newPasswordMessage, error?.errors?.password?.[0] || message);
      } else {
        setError(passwordMessage, message);
      }
    } finally {
      passwordSubmit.disabled = false;
    }
  });

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
      ? "rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
      : "hidden rounded-2xl border px-4 py-3 text-sm";
    element.textContent = message;
  }

  function setFieldError(input, element, message = "") {
    element.className = message ? "mt-2 text-sm text-red-700" : "hidden";
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
