// Pending registration has no account session until a deliberate magic-link POST.
document.addEventListener("DOMContentLoaded", () => {
  const params = new URLSearchParams(window.location.search);
  if (params.get("mode") === "login" || window.registrationMagicLinkToken) return;
  const view = document.getElementById("statePending");
  const description = document.getElementById("verificationDescription");
  const title = document.getElementById("verificationTitle");
  const instruction = document.getElementById("verificationInstruction");
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
  let state = read("pendingVerificationState");
  let until = Number(read("pendingVerificationResendUntil")) || 0;
  let linkUntil = state === "resumed" ? Number(read("pendingVerificationLinkUntil")) || 0 : 0;
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
    title.textContent = state === "resumed" ? "Registration already started" : state === "requested" ? "Registration requested" : "Registration pending";
    description.textContent = state === "resumed" ? "No new email was sent." : email ? "Email delivery is not confirmed." : "Enter your email to request a verification link.";
    instruction.textContent = state === "resumed" ? linkUntil > Date.now()
      ? "Use your latest verification link, or resend one when available." : "Request a new verification link when available."
      : email ? "Open a verification link you've received to continue." : "";
    masked.textContent = email ? window.VerificationCode.maskEmail(email) : "";
    recovery.classList.toggle("hidden", !!email);
    recoveryEmail.required = !email;
    let editable = false;
    try { editable = !!read("pendingRegistrationEditToken") && JSON.parse(read("pendingSignupDetails") || "null")?.email?.toLowerCase() === email.toLowerCase(); } catch { /* Normal signup remains available. */ }
    const back = document.getElementById("verificationBackAction");
    back.textContent = "Back to sign up";
    back.setAttribute("href", editable ? "./signup.html?edit=1" : "./signup.html");
  }
  function tick() {
    const seconds = Math.max(0, Math.ceil((until - Date.now()) / 1000));
    resend.disabled = busy || seconds > 0;
    resend.textContent = busy ? "Sending verification email..." : seconds && !limited
      ? `Resend link in ${window.VerificationCode.formatCountdown(seconds)}` : "Resend link";
    form.setAttribute("aria-busy", String(busy));
    if (limited && seconds) announce(window.AuthenticationResend.message(seconds));
    if (!seconds && limited) { limited = false; write("pendingVerificationResendLimited", "0"); announce(); }
    if (state === "resumed" && linkUntil && Date.now() >= linkUntil) {
      linkUntil = 0;
      write("pendingVerificationLinkUntil", "0");
      instruction.textContent = "Request a new verification link when available.";
    }
    if (!seconds && Date.now() >= linkUntil && timer) { clearInterval(timer); timer = null; }
  }
  function startTimer() {
    if (!timer && Math.max(until, linkUntil) > Date.now()) timer = window.setInterval(tick, 1000);
    tick();
  }
  function cooldown(data) {
    until = Date.now() + window.AuthenticationResend.seconds(data) * 1000;
    limited = window.AuthenticationResend.limited(data);
    write("pendingVerificationResendUntil", String(until));
    write("pendingVerificationResendLimited", limited ? "1" : "0");
    startTimer();
  }
  function delivery(receipt) {
    stopDelivery();
    if (receipt) stopDelivery = window.AuthenticationEmailDelivery.watch(receipt, status => {
      const headings = {sent: "Check your email", failed: "Email not sent", superseded: "Request a new link", delayed: "Email delayed"};
      title.textContent = headings[status] || "Sending verification email";
      description.textContent = status === "sent" ? "We sent a verification link to:"
        : status === "failed" ? "We couldn't send a verification link to:" : "Email delivery is not confirmed.";
      instruction.textContent = status === "sent" ? "Open the link to finish creating your account."
        : status === "failed" || status === "superseded" ? "Request a new verification link when available." : "";
      const text = window.AuthenticationEmailDelivery.failureMessage(status, "link");
      if (text && !limited) announce(text, true);
    });
  }
  async function join() {
    if (joining || busy || !email) return;
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
      state = result.registration_state === "requested" ? "requested" : result.email_delivery_token ? "queued" : "";
      linkUntil = 0;
      write("pendingVerificationLinkUntil", "0");
      write("pendingVerificationState", state);
      renderEmail();
      announce(result.email_delivery_token ? "" : result.message || "Request received. Email delivery is not confirmed.");
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
  if (channel) channel.onmessage = event => { if (event.data?.type === "registration-verified") join(); };
  window.addEventListener("focus", join);
  window.addEventListener("pageshow", event => {
    if (!event.persisted) return;
    startTimer();
    if (state !== "requested" && state !== "resumed") delivery(API.getEmailDeliveryToken("registration"));
    join();
  });
  window.addEventListener("pagehide", event => {
    stopDelivery();
    clearInterval(timer);
    timer = null;
    if (!event.persisted) channel?.close();
  });
  view.classList.remove("hidden");
  renderEmail();
  startTimer();
  if (read("pendingVerificationDeliveryFailed") === "1" && !limited) {
    title.textContent = "Email not sent";
    description.textContent = "We couldn't send a verification link to:";
    announce("Couldn't send the verification email. Try resending.", true);
  }
  if (state !== "requested" && state !== "resumed") delivery(API.getEmailDeliveryToken("registration"));
});
