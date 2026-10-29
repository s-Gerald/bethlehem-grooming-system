// Browser presentation state only. Email proof and server grants authorize edits.
window.SignupContext = (() => {
  const key = "pendingSignupContext";
  const fields = { firstName: 100, lastName: 100, username: 50, phone: 40 };
  const normalize = email => typeof email === "string" ? email.trim().toLowerCase() : "";
  function read(email = "") {
    let raw;
    try { raw = localStorage.getItem(key); } catch { /* Session fallback. */ }
    try { raw ??= sessionStorage.getItem(key); } catch { /* Ordinary signup remains available. */ }
    try {
      const value = JSON.parse(raw || "null");
      if (!value || typeof value.email !== "string" || !Number.isFinite(value.expiresAt)
          || value.expiresAt <= Date.now() || value.expiresAt > Date.now() + 3600000
          || (email && normalize(value.email) !== normalize(email))) return null;
      return value;
    } catch { return null; }
  }
  function write(value) {
    const raw = JSON.stringify(value);
    try { localStorage.setItem(key, raw); } catch { /* Session fallback. */ }
    try { sessionStorage.setItem(key, raw); } catch { /* UX only. */ }
  }
  function remember(email) {
    const value = read(email) || {email: normalize(email), expiresAt: Date.now() + 3600000};
    write(value);
    return value;
  }
  function propose(email, values) {
    const value = read(email);
    if (!value) return;
    const draft = {};
    for (const [field, max] of Object.entries(fields)) {
      if (typeof values[field] === "string") draft[field] = values[field].slice(0, max);
    }
    write({...value, draft, review: true});
  }
  function draft(email) {
    const value = read(email);
    const result = {};
    if (!value?.review || !value.draft) return result;
    for (const [field, max] of Object.entries(fields)) {
      if (typeof value.draft[field] === "string") result[field] = value.draft[field].slice(0, max);
    }
    return result;
  }
  function clear(email) {
    if (email && !read(email)) return;
    try { localStorage.removeItem(key); } catch { /* UX only. */ }
    try { sessionStorage.removeItem(key); } catch { /* UX only. */ }
  }
  return {read, remember, propose, draft, clear};
})();
