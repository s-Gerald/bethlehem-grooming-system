// Waiting stays on signup.html so the form/password can remain in page memory.
window.SignupVerification = {
  create({onApproved, onEdit, onExpired}) {
    const view = document.getElementById("signupWaitingState");
    const resend = document.getElementById("signupResend");
    const message = document.getElementById("signupWaitingMessage");
    let attempt, timer, countdown;
    let busy = false, checking = false, active = false;
    let receipt = "", deliveryFinished = false;
    let deliveryChecks = 0;

    function announce(text) {
      if (message.textContent !== text) message.textContent = text;
    }
    function tick() {
      const seconds = Math.max(0, Math.ceil(((attempt?.resendUntil || 0) - Date.now()) / 1000));
      resend.disabled = busy || seconds > 0;
      resend.textContent = busy ? "Sending..." : seconds
        ? `Resend link in ${window.VerificationCode.formatCountdown(seconds)}` : "Resend link";
    }
    function stop() {
      active = false;
      window.clearTimeout(timer);
      window.clearInterval(countdown);
      view.classList.add("hidden");
    }
    function schedule() {
      window.clearTimeout(timer);
      if (active && !document.hidden) timer = window.setTimeout(check, 5000);
    }
    async function check() {
      if (!active || checking || busy || document.hidden) return;
      window.clearTimeout(timer);
      if (attempt.expiresAt <= Date.now()) { stop(); onExpired(); return; }
      checking = true;
      const current = attempt.token;
      try {
        const result = await API.registrationStatus(current);
        if (!active || attempt.token !== current) return;
        if (result.state === "approved" || result.state === "completed") {
          stop();
          await onApproved(result.state);
          return;
        }
        const linkDeadline = Date.parse(result.verification_link_expires_at);
        if (linkDeadline && linkDeadline <= Date.now()) announce("This link has expired. Resend a link to continue.");
        if (receipt && !deliveryFinished) {
          const delivery = await API.emailDeliveryStatus(receipt);
          if (!active) return;
          deliveryChecks++;
          deliveryFinished = ["sent", "failed", "superseded"].includes(delivery.status);
          if (!deliveryFinished && deliveryChecks >= 24) {
            deliveryFinished = true;
            announce(window.AuthenticationEmailDelivery.failureMessage("delayed", "link"));
          }
          if (delivery.status === "sent") announce("");
          else {
            const text = window.AuthenticationEmailDelivery.failureMessage(delivery.status, "link");
            if (text) announce(text);
          }
        }
      } catch (error) {
        if (!active || attempt.token !== current) return;
        if (["registration_expired", "registration_unavailable"].includes(error.code)) {
          stop();
          onExpired(error.message);
        } else announce(error.message || "Connection lost. Checking again shortly.");
      } finally { checking = false; schedule(); }
    }
    resend.addEventListener("click", async () => {
      tick();
      if (!active || resend.disabled) return;
      busy = true;
      announce("");
      tick();
      const current = attempt.token;
      try {
        const result = await API.resendVerification(current);
        if (!active || attempt.token !== current) return;
        if (result.state === "approved" || result.state === "completed") {
          stop();
          await onApproved(result.state);
          return;
        }
        attempt = window.SignupContext.save({...attempt,
          resendUntil: Date.now() + window.AuthenticationResend.seconds(result) * 1000});
        receipt = result.email_delivery_token || "";
        deliveryFinished = false;
        deliveryChecks = 0;
      } catch (error) {
        if (!active || attempt.token !== current) return;
        if (error.status === 429) attempt = window.SignupContext.save({...attempt,
          resendUntil: Date.now() + window.AuthenticationResend.seconds(error) * 1000});
        if (["registration_expired", "registration_restart_required", "registration_unavailable"].includes(error.code)) {
          stop();
          onExpired(error.message);
        } else announce(error.message || "Could not send the verification email. Try again.");
      } finally { busy = false; tick(); schedule(); }
    });
    document.getElementById("signupEdit").addEventListener("click", () => { stop(); onEdit(); });
    window.addEventListener("focus", check);
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) window.clearTimeout(timer);
      else return check();
    });
    return {
      start(value, email, data = {}) {
        stop();
        attempt = value;
        active = true;
        receipt = data.email_delivery_token || "";
        deliveryFinished = false;
        deliveryChecks = 0;
        view.classList.remove("hidden");
        document.getElementById("signupMaskedEmail").textContent = window.VerificationCode.maskEmail(email);
        announce("");
        tick();
        countdown = window.setInterval(tick, 1000);
        schedule();
        if (data.state === "approved" || data.state === "completed") return check();
      },
      check, stop,
    };
  },
};
