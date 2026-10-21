// A queued notification is not yet accepted by the mail transport.
window.AuthenticationEmailDelivery = {
  failureMessage(status) {
    return {
      failed: "Couldn't send the code. Try again.",
      delayed: "Email is delayed. Try again shortly.",
      superseded: "Code replaced or expired. Request a new code.",
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
