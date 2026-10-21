// Signup actions use the shared OTP input and the existing private delivery receipt.
document.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);
  const fragment = new URLSearchParams(window.location.hash.slice(1));
  if (params.get("mode") === "login" || params.has("token") || fragment.has("token")) return;

  const view = document.getElementById("statePending");
  const description = document.getElementById("verificationDescription");
  const maskedEmail = document.getElementById("verifyCodeEmail");
  const form = document.getElementById("signupCodeForm");
  const input = document.getElementById("signupCode");
  const submit = document.getElementById("signupCodeSubmit");
  const resend = document.getElementById("signupResend");
  const message = document.getElementById("signupCodeMessage");
  const recovery = document.getElementById("verificationEmailRecovery");
  const recoveryEmail = document.getElementById("verificationEmail");
  const read = (key) => { try { return sessionStorage.getItem(key) || ""; } catch { return ""; } };
  const write = (key, value) => { try { sessionStorage.setItem(key, value); } catch { /* UX only */ } };
  let email = read("pendingVerificationEmail");
  let busy = "";
  let completed = false;
  let until = Number(read("pendingVerificationResendUntil")) || 0;
  let timer = null;
  let stopDeliveryCheck = () => {};

  function checkDelivery(receipt) {
    stopDeliveryCheck();
    if (!receipt) return;
    stopDeliveryCheck = window.AuthenticationEmailDelivery.watch(receipt, (status) => {
      const text = window.AuthenticationEmailDelivery.failureMessage(status);
      if (!completed && text) {
        setMessage(text, false);
      }
    });
  }

  function setMessage(text = "", invalid = true) {
    message.textContent = text;
    message.className = text ? "verification-message" : "hidden";
    input.setAttribute("aria-invalid", text && invalid ? "true" : "false");
  }
  const otp = window.VerificationCode.bind(input, [...view.querySelectorAll(".verification-code-cell")], () => {
    setMessage();
    updateControls();
  });

  function updateControls() {
    const remaining = Math.max(0, Math.ceil((until - Date.now()) / 1000));
    input.disabled = busy === "verify" || completed;
    recoveryEmail.disabled = !!busy || completed;
    submit.disabled = !!busy || completed || input.value.length !== 6;
    resend.disabled = !!busy || completed || remaining > 0;
    submit.textContent = busy === "verify" ? "Verifying..." : "Verify and continue";
    resend.textContent = busy === "send" ? "Sending..." : remaining > 0
      ? `Resend in ${window.VerificationCode.formatCountdown(remaining)}` : "Resend code";
    if (remaining === 0 && timer) {
      window.clearInterval(timer);
      timer = null;
    }
  }

  function scheduleCountdown() {
    if (timer) window.clearInterval(timer);
    timer = window.setInterval(updateControls, 1000);
    updateControls();
  }

  function startCooldown(seconds = 45) {
    until = Date.now() + Math.max(0, Number(seconds) || 0) * 1000;
    write("pendingVerificationResendUntil", String(until));
    scheduleCountdown();
  }

  function renderEmail() {
    description.textContent = "Enter the 6-digit code sent to";
    maskedEmail.textContent = window.VerificationCode.maskEmail(email);
    recovery.classList.toggle("hidden", !!email);
  }

  function resolveEmail() {
    if (email) return true;
    if (!recoveryEmail.value.trim() || !recoveryEmail.checkValidity()) {
      recoveryEmail.reportValidity();
      recoveryEmail.focus();
      return false;
    }
    email = recoveryEmail.value.trim().toLowerCase();
    write("pendingVerificationEmail", email);
    renderEmail();
    return true;
  }

  resend.addEventListener("click", async () => {
    if (resend.disabled || busy || !resolveEmail()) return;
    busy = "send";
    stopDeliveryCheck();
    otp.clear();
    input.focus();
    updateControls();
    try {
      const result = await API.resendVerification(email);
      if (result?.email_delivery_token) write("pendingVerificationDeliveryFailed", "0");
      startCooldown(result?.resend_after ?? 45);
      checkDelivery(result?.email_delivery_token);
    } catch (error) {
      setMessage(error?.status === 429 ? "Too many attempts. Try again later." : "Couldn't send the code. Try again.", false);
      if (error?.retryAfter) startCooldown(error.retryAfter);
    } finally {
      busy = "";
      updateControls();
    }
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy || completed || !resolveEmail()) return;
    if (!/^[0-9]{6}$/.test(input.value)) return;
    busy = "verify";
    setMessage();
    updateControls();
    try {
      const result = await API.verifyEmail(email, input.value);
      if (!result?.token || result?.user?.role !== "customer") throw new Error("Invalid session");
      completed = true;
      stopDeliveryCheck();
      otp.clear();
      for (const key of ["pendingVerificationEmail", "pendingVerificationPhone", "pendingRegistrationEditToken", "pendingSignupDetails", "pendingVerificationResendUntil", "pendingVerificationDeliveryFailed", "pending_registration_email_delivery"]) {
        try { sessionStorage.removeItem(key); } catch { /* storage unavailable */ }
      }
      if (timer) window.clearInterval(timer);
      view.classList.add("hidden");
      document.getElementById("stateSuccess").classList.remove("hidden");
      window.setTimeout(() => window.location.replace("./dashboard.html"), 750);
    } catch (error) {
      setMessage(error?.status === 429 ? "Too many attempts. Try again later."
        : error?.status >= 500 || !error?.status ? "Couldn't verify the code. Try again."
        : error?.message === "These registration details are no longer available. Please register again."
          ? error.message : "Invalid or expired code.");
    } finally {
      busy = "";
      updateControls();
    }
  });

  view.classList.remove("hidden");
  document.getElementById("verificationTitle").textContent = "Verify your email";
  renderEmail();
  scheduleCountdown();
  checkDelivery(API.getEmailDeliveryToken("registration"));
  if (read("pendingVerificationDeliveryFailed") === "1") setMessage("Couldn't send the code. Try again.", false);
  if (email) input.focus();
  else recoveryEmail.focus();
});
