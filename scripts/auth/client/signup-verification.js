// Pending registration has no account session until a deliberate magic-link POST.
document.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);
  if (params.get("mode") === "login" || window.registrationMagicLinkToken) return;
  const view = document.getElementById("statePending");
  const description = document.getElementById("verificationDescription");
  const masked = document.getElementById("verifyCodeEmail");
  const form = document.getElementById("signupLinkForm");
  const resend = document.getElementById("signupResend");
  const message = document.getElementById("signupLinkMessage");
  const recovery = document.getElementById("verificationEmailRecovery");
  const recoveryEmail = document.getElementById("verificationEmail");
  const read = (key) => { try { return sessionStorage.getItem(key) || ""; } catch { return ""; } };
  const write = (key, value) => { try { sessionStorage.setItem(key, value); } catch { /* UX only */ } };
  let email = params.get("recover") === "1" ? "" : read("pendingVerificationEmail");
  let busy = false;
  let joining = false;
  let joinAgain = false;
  let until = Number(read("pendingVerificationResendUntil")) || 0;
  let limited = read("pendingVerificationResendLimited") === "1";
  let timer;
  let stopDelivery = () => {};
  let channel;
  try { channel = new BroadcastChannel("bethlehem-registration"); } catch { /* Focus remains a recovery path. */ }

  function announce(text = "", error = false) {
    if (message.textContent === text && message.getAttribute("role") === (error ? "alert" : "status")) return;
    message.textContent = text;
    message.className = text ? "verification-message" : "hidden";
    message.setAttribute("role", error ? "alert" : "status");
  }
  function renderEmail() {
    description.textContent = email ? "We sent a verification link to:" : "Enter your email to request a verification link.";
    masked.textContent = email ? window.VerificationCode.maskEmail(email) : "";
    recovery.classList.toggle("hidden", !!email);
    recoveryEmail.required = !email;
    const editable = !!read("pendingRegistrationEditToken");
    document.getElementById("signupVerificationEdit").classList.toggle("hidden", !editable);
    document.getElementById("signupChangeEmail").classList.toggle("hidden", editable || !email);
  }
  function tick() {
    const seconds = Math.max(0, Math.ceil((until - Date.now()) / 1000));
    resend.disabled = busy || seconds > 0;
    resend.textContent = busy ? "Sending verification email..." : seconds && !limited
      ? `Resend link in ${window.VerificationCode.formatCountdown(seconds)}` : "Resend link";
    form.setAttribute("aria-busy", String(busy));
    if (limited && seconds) announce(window.AuthenticationResend.message(seconds));
    if (!seconds && limited) { limited = false; write("pendingVerificationResendLimited", "0"); announce(); }
    if (!seconds && timer) { clearInterval(timer); timer = null; }
  }
  function cooldown(data) {
    until = Date.now() + window.AuthenticationResend.seconds(data) * 1000;
    limited = window.AuthenticationResend.limited(data);
    write("pendingVerificationResendUntil", String(until));
    write("pendingVerificationResendLimited", limited ? "1" : "0");
    if (!timer && until > Date.now()) timer = window.setInterval(tick, 1000);
    tick();
  }
  function delivery(receipt) {
    stopDelivery();
    if (receipt) stopDelivery = window.AuthenticationEmailDelivery.watch(receipt, status => {
      if (status === "failed") description.textContent = "We couldn't send a verification link to:";
      else if (status === "sent") description.textContent = "We sent a verification link to:";
      const text = window.AuthenticationEmailDelivery.failureMessage(status, "link");
      if (text && !limited) announce(text, true);
    });
  }
  async function join() {
    if (joining) { joinAgain = true; return; }
    if (busy) return;
    joining = true;
    try {
      // Only the server-validated HttpOnly account cookie can establish a
      // dashboard session. Broadcast messages and storage never authorize it.
      const result = await API.registrationSession(email, !!email);
      if (result) {
        stopDelivery();
        clearInterval(timer);
        channel?.postMessage({ type: "registration-tab-ready" });
        window.location.replace("./dashboard.html");
      }
    } catch { /* Not verified in this browser; keep waiting for the email. */ }
    finally {
      joining = false;
      if (joinAgain) { joinAgain = false; join(); }
    }
  }
  form.addEventListener("submit", async event => {
    event.preventDefault();
    if (busy || resend.disabled) return;
    if (!email) {
      if (!recoveryEmail.value.trim() || !recoveryEmail.checkValidity()) { recoveryEmail.reportValidity(); return; }
      email = recoveryEmail.value.trim().toLowerCase();
      write("pendingVerificationEmail", email);
      renderEmail();
    }
    busy = true;
    tick();
    try {
      const result = await API.resendVerification(email);
      description.textContent = "We sent a verification link to:";
      announce(result.message || "Check your inbox for a verification link.");
      write("pendingVerificationDeliveryFailed", "0");
      cooldown(result);
      delivery(result.email_delivery_token);
    } catch (error) {
      if (error.status === 429) cooldown(error);
      announce(window.AuthenticationResend.limited(error)
        ? window.AuthenticationResend.message(window.AuthenticationResend.seconds(error))
        : error.message || "Couldn't send the verification email. Try resending.", true);
    } finally { busy = false; tick(); }
  });
  document.getElementById("signupChangeEmail").addEventListener("click", () => {
    email = "";
    renderEmail();
    recoveryEmail.focus();
  });
  if (channel) channel.onmessage = event => { if (event.data?.type === "registration-verified") join(); };
  window.addEventListener("focus", join);
  window.addEventListener("pageshow", join);
  window.addEventListener("pagehide", () => { stopDelivery(); clearInterval(timer); channel?.close(); });
  view.classList.remove("hidden");
  renderEmail();
  timer = window.setInterval(tick, 1000);
  tick();
  if (read("pendingVerificationDeliveryFailed") === "1" && !limited) {
    description.textContent = "Your registration is saved. Request a verification link.";
    announce("Couldn't send the verification email. Try resending.", true);
  }
  delivery(API.getEmailDeliveryToken("registration"));
  join();
});
