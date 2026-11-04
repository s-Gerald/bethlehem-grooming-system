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
  const context = window.SignupContext;
  const recovering = params.get("recover") === "1";
  let email = recovering ? "" : read("pendingVerificationEmail") || context.read()?.email || "";
  let busy = false;
  let joining = false;
  let departed = false;
  let state = recovering ? "" : read("pendingVerificationState");
  let registrationEnded = state === "expired" || state === "restart_required";
  const registrationUntil = recovering ? 0 : Number(read("pendingVerificationRegistrationUntil")) || context.read(email)?.expiresAt || 0;
  let until = recovering ? 0 : Number(read("pendingVerificationResendUntil")) || 0;
  let linkUntil = state === "resumed" ? Number(read("pendingVerificationLinkUntil")) || 0 : 0;
  let limited = !recovering && read("pendingVerificationResendLimited") === "1";
  let timer;
  let expirationTimer;
  let pollTimer;
  let suspended = false;
  let pollingStopped = false;
  let unavailableChecks = 0;
  const confirmation = document.getElementById("registrationConfirmation");
  const confirmationCode = document.getElementById("registrationConfirmationCode");
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
    document.getElementById("signupWaiting").classList.toggle("hidden", !email || registrationEnded);
    title.textContent = state === "resumed" ? "Registration in progress" : state === "requested" ? "Registration requested" : "Check your email";
    description.textContent = state === "resumed" ? linkUntil && linkUntil <= Date.now()
      ? "This link has expired. Request a new one." : "Use your latest verification link."
      : email ? "Open the verification link to continue." : "Enter your email to request a verification link.";
    instruction.textContent = state === "resumed" ? ""
      : "";
    masked.textContent = email ? window.VerificationCode.maskEmail(email) : "";
    recovery.classList.toggle("hidden", !!email);
    recoveryEmail.required = !email;
    const back = document.getElementById("verificationBackAction");
    back.textContent = "Back to sign up";
    back.setAttribute("href", "/sign-up");
    if (registrationEnded) {
      window.clearTimeout(pollTimer);
      confirmation.classList.add("hidden");
      title.textContent = state === "expired" ? "Registration expired" : "Registration ending";
      description.textContent = "";
      instruction.textContent = "";
      recovery.classList.add("hidden");
      announce(state === "expired" ? "Registration expired. Start again." : "This registration is almost expired. Start again.", true);
    }
  }
  function tick() {
    if (!registrationEnded && registrationUntil && registrationUntil <= Date.now()) {
      registrationEnded = true;
      state = "expired";
      write("pendingVerificationState", state);
      stopDelivery();
      renderEmail();
    }
    if (registrationEnded) {
      resend.disabled = true;
      resend.classList.add("hidden");
      form.setAttribute("aria-busy", "false");
      clearInterval(timer);
      window.clearTimeout(expirationTimer);
      timer = null;
      return;
    }
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
      description.textContent = "This link has expired. Request a new one.";
      instruction.textContent = "";
    }
    if (!seconds && Date.now() >= linkUntil && timer) { clearInterval(timer); timer = null; }
  }
  function startTimer() {
    if (!timer && Math.max(until, linkUntil) > Date.now()) timer = window.setInterval(tick, 1000);
    window.clearTimeout(expirationTimer);
    if (!registrationEnded && registrationUntil > Date.now()) expirationTimer = window.setTimeout(tick, registrationUntil - Date.now());
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
      description.textContent = status === "sent" ? "Open the verification link to continue."
        : status === "failed" ? "We couldn't send a verification link to:" : "Email delivery is not confirmed.";
      instruction.textContent = status === "sent" ? ""
        : status === "failed" || status === "superseded" ? "Request a new verification link when available." : "";
      const text = window.AuthenticationEmailDelivery.failureMessage(status, "link");
      if (text && !limited) announce(text, true);
    });
  }
  function schedulePoll() {
    window.clearTimeout(pollTimer);
    if (!departed && !suspended && !document.hidden && !registrationEnded && !pollingStopped
        && registrationUntil > Date.now() && read("pendingRegistrationEditToken")) {
      pollTimer = window.setTimeout(join, 5000);
    }
  }
  async function join() {
    if (departed || suspended || document.hidden || registrationEnded || joining || busy || !email) return;
    window.clearTimeout(pollTimer);
    joining = true;
    let unavailable = false;
    try {
      if (registrationUntil > Date.now() && read("pendingRegistrationEditToken")) {
        let status;
        try { status = await API.registrationStatus(email); }
        catch (error) {
          if (error.code === "registration_expired") {
            registrationEnded = true; state = "expired"; renderEmail(); tick(); return;
          }
          if (error.code === "registration_confirmation_expired" || error.code === "registration_link_expired") {
            confirmation.classList.add("hidden");
            pollingStopped = true;
            announce(error.message, true);
            return;
          }
          // Same-browser completion deletes the pending row. Its usable cookie
          // remains the sole authority for accepting that tab handoff.
          if (error.code !== "registration_unavailable") throw error;
          unavailable = true;
        }
        if (departed || suspended || document.hidden) return;
        if (status?.state === "confirmation_required") {
          confirmationCode.textContent = status.confirmation_code;
          confirmation.classList.remove("hidden");
          return;
        }
        confirmation.classList.add("hidden");
        if (status?.state === "pending") return;
        if (status?.state === "approved" || status?.state === "completed") await API.redeemRegistration(email);
      }
      if (departed || suspended || document.hidden) return;
      // Only the server-validated HttpOnly account cookie can establish a
      // dashboard session. Broadcast messages and storage never authorize it.
      const result = await API.registrationSession(email, !!email);
      if (result && !departed) {
        departed = true;
        context.clear(email);
        stopDelivery();
        clearInterval(timer);
        window.clearTimeout(expirationTimer);
        window.clearTimeout(pollTimer);
        title.textContent = "Account created!";
        description.textContent = "Redirecting to your account...";
        instruction.textContent = "";
        resend.classList.add("hidden");
        channel?.postMessage({ type: "registration-tab-ready" });
        window.location.replace("/account");
      }
    } catch (error) {
      if (unavailable && error.status === 401 && ++unavailableChecks >= 3) {
        pollingStopped = true;
        confirmation.classList.add("hidden");
        stopDelivery();
        announce("This link is no longer valid.", true);
      }
      if (!departed && (!error.status || error.status >= 500)) announce("Connection lost. Try again.", true);
    }
    finally {
      joining = false;
      schedulePoll();
    }
  }
  form.addEventListener("submit", async event => {
    event.preventDefault();
    if (busy || resend.disabled) return;
    if (!email) {
      if (!recoveryEmail.value.trim() || !recoveryEmail.checkValidity()) { recoveryEmail.reportValidity(); return; }
      email = recoveryEmail.value.trim().toLowerCase();
      write("pendingVerificationEmail", email);
      write("pendingVerificationRegistrationUntil", "0");
      renderEmail();
    }
    busy = true;
    tick();
    try {
      const result = await API.resendVerification(email);
      if (departed) return;
      state = result.registration_state === "requested" ? "requested" : result.email_delivery_token ? "queued" : "";
      linkUntil = 0;
      write("pendingVerificationLinkUntil", "0");
      write("pendingVerificationState", state);
      renderEmail();
      confirmation.classList.add("hidden");
      pollingStopped = false;
      unavailableChecks = 0;
      announce(result.email_delivery_token ? "" : result.message || "Request received. Email delivery is not confirmed.");
      write("pendingVerificationDeliveryFailed", "0");
      cooldown(result);
      delivery(result.email_delivery_token);
    } catch (error) {
      if (departed) return;
      if (error.code === "registration_expired" || error.code === "registration_restart_required") {
        registrationEnded = true;
        state = error.code === "registration_expired" ? "expired" : "restart_required";
        write("pendingVerificationState", state);
        stopDelivery();
        renderEmail();
        return;
      }
      if (error.status === 429) cooldown(error);
      announce(window.AuthenticationResend.limited(error)
        ? window.AuthenticationResend.message(window.AuthenticationResend.seconds(error))
        : error.message || "Couldn't send the verification email. Try resending.", true);
    } finally { busy = false; if (!departed) { tick(); schedulePoll(); } }
  });
  if (channel) channel.onmessage = event => { if (event.data?.type === "registration-verified") join(); };
  window.addEventListener("focus", join);
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) { window.clearTimeout(pollTimer); return; }
    return join();
  });
  window.addEventListener("pageshow", event => {
    if (!event.persisted) return;
    suspended = false;
    startTimer();
    if (!registrationEnded && state !== "requested" && state !== "resumed") delivery(API.getEmailDeliveryToken("registration"));
    join();
  });
  window.addEventListener("pagehide", event => {
    suspended = true;
    window.clearTimeout(pollTimer);
    stopDelivery();
    clearInterval(timer);
    window.clearTimeout(expirationTimer);
    timer = null;
    if (!event.persisted) channel?.close();
  });
  context.bindExits(() => {
    departed = true;
    window.clearTimeout(pollTimer);
    stopDelivery();
    clearInterval(timer);
    window.clearTimeout(expirationTimer);
  });
  view.classList.remove("hidden");
  renderEmail();
  startTimer();
  if (email && !registrationEnded && read("pendingVerificationDeliveryFailed") === "1" && !limited) {
    title.textContent = "Email not sent";
    description.textContent = "We couldn't send a verification link to:";
    announce("Couldn't send the verification email. Try resending.", true);
  }
  if (email && !registrationEnded && state !== "requested" && state !== "resumed") delivery(API.getEmailDeliveryToken("registration"));
  schedulePoll();
});
