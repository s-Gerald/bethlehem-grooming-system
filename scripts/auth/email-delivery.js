// A queued notification is not yet accepted by the mail transport.
window.AuthenticationEmailDelivery = {
  failureMessage(status, kind = 'code') {
    return {
      failed: kind === 'link' ? "Couldn't send the verification email. Try resending." : "Couldn't send the code. Try again.",
      delayed: "Email is delayed. Try again shortly.",
      superseded: kind === 'link' ? 'Link replaced or expired. Request a new one.' : "Code replaced or expired. Request a new code.",
    }[status] || "";
  },
  watch(receipt, onStatus) {
    let stopped = false;
    let timer;
    let checks = 0;
    async function check() {
      if (stopped) return;
      let status = "queued";
      try {
        const result = await API.emailDeliveryStatus(receipt);
        status = result.status;
      } catch { /* A transient status request failure does not imply delivery. */ }
      if (stopped) return;
      checks++;
      if (status === "sent" || status === "failed" || status === "superseded") {
        onStatus(status);
        return;
      }
      if (checks >= 24) {
        onStatus("delayed");
        return;
      }
      timer = window.setTimeout(check, 5000);
    }
    onStatus("queued");
    check();
    return () => { stopped = true; window.clearTimeout(timer); };
  },
};

window.AuthenticationResend = {
  seconds(data) {
    return Math.max(0, Number(data?.retryAfter ?? data?.resend_after ?? data?.retry_after) || 0);
  },
  limited(data) {
    return data?.data?.resend_limited === true || data?.resend_limited === true || data?.code === 'email_rate_limited';
  },
  message(seconds) {
    return `Too many requests. You've requested too many emails. Try again in about ${Math.max(1, Math.ceil(seconds / 60))} ${seconds > 60 ? 'minutes' : 'minute'}.`;
  },
};
