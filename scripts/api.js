// api.js — Central HTTP layer for all backend communication.
// All API calls live here. Auth scripts and components call these functions.
// Loaded as a plain <script> tag before any auth script that needs it.

// var (not const) so this is accessible as a global from ES module scripts
var API = (() => {
  const LOCAL_API_ORIGIN = "http://127.0.0.1:8000";
  const REQUEST_TIMEOUT_MS = 12000;

  function trimTrailingSlash(value) {
    return String(value || "").replace(/\/+$/, "");
  }

  function apiUrlFromOrigin(origin) {
    const normalized = trimTrailingSlash(origin);
    if (!normalized) return "";
    return normalized.endsWith("/api") ? normalized : `${normalized}/api`;
  }

  function resolveBaseUrl() {
    const configured = typeof window !== "undefined"
      ? window.BETHLEHEM_API_BASE_URL
      : "";
    const metaConfigured = typeof document !== "undefined"
      ? document.querySelector('meta[name="bethlehem-api-base-url"]')?.content
      : "";
    const explicitBase = apiUrlFromOrigin(configured || metaConfigured);

    if (explicitBase) return explicitBase;

    if (typeof window === "undefined" || !window.location) {
      return `${LOCAL_API_ORIGIN}/api`;
    }

    const { protocol, hostname, port, origin } = window.location;
    const localHostnames = new Set(["localhost", "127.0.0.1", "::1"]);
    const isLocalDevServer = localHostnames.has(hostname) && port && port !== "8000";

    if (protocol === "file:" || isLocalDevServer) {
      return `${LOCAL_API_ORIGIN}/api`;
    }

    return `${origin}/api`;
  }

  const BASE_URL = resolveBaseUrl();

  function getBaseUrl() {
    return BASE_URL;
  }

  // ── Token keys ────────────────────────────────────────────────────────────
  const CUSTOMER_TOKEN_KEY = "customer_token";
  const ADMIN_TOKEN_KEY    = "admin_token";
  const USER_ROLE_KEY      = "user_role";
  const LOGIN_POLL_TOKEN_KEY = "pending_login_poll_token";
  const LOGIN_CONFIRMATION_EMAIL_KEY = "pending_login_confirmation_email";
  const AUTH_LOGOUT_EVENT_KEY = "bethlehem.auth.logout";
  const AUTH_LAST_ACTIVITY_KEY = "bethlehem.auth.last_activity";
  const AUTH_SESSION_STATE_KEY = "bethlehem.auth.session_state";
  const SIGNOUT_REASON_KEY = "bethlehem.auth.signout_reason";
  // One-time upgrade of credentials left by older deployed frontend versions.
  // Raw credentials are removed from storage immediately and never persisted again.
  const legacyCredentials = new Map();
  let observedSession = null;
  let authRedirectStarted = false;
  const CUSTOMER_PROFILE_NAME_KEY = "bethlehem.customer.profile_name";
  const CUSTOMER_PROFILE_CHANGED_KEY = "bethlehem.customer.profile_changed";
  const CUSTOMER_CACHE_KEY = "bethlehem.customer.data.v1";
  const CUSTOMER_CACHE_SESSION_KEY = "bethlehem.customer.cache_session";
  const customerCache = new Map();
  const customerRequests = new Map();
  const customerCacheRevisions = new Map();
  let customerCacheOwner = null;
  let customerCacheToken = null;
  let customerCacheEpoch = 0;

  const ADMIN_CACHE_KEY = "bethlehem.admin.presentation.v3";
  const ADMIN_DATA_CHANGED_KEY = "bethlehem.admin.data_changed";
  const adminCache = new Map();
  const adminRequests = new Map();
  const adminCacheRevisions = new Map();
  let adminCacheOwner = null;
  let adminCacheToken = null;
  let adminCacheEpoch = 0;
  let notificationReadSequence = 0;
  let notificationCountSequence = 0;
  let staffIdentityRequest = null;
  const ADMIN_NAVIGATION_KEY = "bethlehem.admin.navigation.v1";
  let staffIdentity = null;
  let staffIdentityToken = null;
  let staffIdentityEpoch = 0;
  let verifiedSidebarRevision = null;

  function clearStaffIdentity(notify = false) {
    staffIdentityEpoch++;
    staffIdentity = staffIdentityToken = staffIdentityRequest = null;
    safeStorageRemove(sessionStorage, ADMIN_NAVIGATION_KEY);
    if (typeof window.dispatchEvent === 'function') {
      window.dispatchEvent(new CustomEvent('admin-navigation:invalidated', { detail: { reload: notify } }));
    }
  }

  function staffIdentityOwner() {
    const session = getAuthSession();
    const id = /^(\d+)\|/.exec(session?.token || '')?.[1];
    return id && isAdminRole(session?.role) ? `${BASE_URL}:${session.role}:${id}` : null;
  }

  function getCachedStaffIdentity() {
    const token = getAdminToken(), owner = staffIdentityOwner();
    if (!token || !owner) return null;
    if (staffIdentityToken !== token) {
      staffIdentity = null;
      staffIdentityToken = token;
      try { staffIdentity = JSON.parse(safeStorageGet(sessionStorage, ADMIN_NAVIGATION_KEY) || 'null'); }
      catch { /* Invalid cache is a normal first-load path. */ }
    }
    const cached = staffIdentity;
    if (cached?.version !== 2 || cached.owner !== owner || !Number.isFinite(cached.at)
      || Date.now() < cached.at || Date.now() - cached.at >= 12 * 60 * 60 * 1000
      || !cached.data?.sidebar_revision || !cached.data?.user?.user_id || !cached.data.user.staff_permissions
      || cached.data.user.role !== getUserRole()
      || (verifiedSidebarRevision && cached.data.sidebar_revision !== verifiedSidebarRevision)) return null;
    return cached.data;
  }

  function rememberStaffIdentity(response, token) {
    const user = response?.user, owner = staffIdentityOwner();
    if (!owner || token !== getAdminToken() || user?.role !== getUserRole() || !response.sidebar_revision) return;
    if (verifiedSidebarRevision && verifiedSidebarRevision !== response.sidebar_revision) return;
    // Navigation presentation only: no contact details, passwords or page data.
    const data = { sidebar_revision: response.sidebar_revision, user: {
      user_id: user.user_id, role: user.role, first_name: user.first_name, last_name: user.last_name, username: user.username,
      staff_type: user.staff_type, staff_subrole: user.staff_subrole, clinic_permissions: user.clinic_permissions || {}, staff_permissions: user.staff_permissions || {},
    } };
    staffIdentityToken = token;
    staffIdentity = { version: 2, owner, at: Date.now(), data };
    try { sessionStorage.setItem(ADMIN_NAVIGATION_KEY, JSON.stringify(staffIdentity)); }
    catch { /* Storage restrictions retain same-page request sharing. */ }
  }

  // Explicit allowlist: never retain credentials, attachments or case workspaces.
  function adminCachePolicy(endpoint) {
    const [path, query = ""] = endpoint.split("?");
    const params = new URLSearchParams(query);
    const presentation = (group, fresh, retain) => {
      const persist = [...params].every(([key, value]) => (key === 'page' && value === '1')
        || (key === 'status' && value === 'active') || (key === 'view' && value === 'overview'));
      return { group, persist, fresh: persist ? fresh : Math.min(fresh, 5000), retain: persist ? retain : Math.min(retain, 15000) };
    };
    if (path === '/admin/grooming/services' || path === '/admin/grooming/services/catalogue') return { group: 'services-grooming', fresh: 60000, retain: 300000 };
    if (/^\/admin\/clinic-services(?:\/options)?$/.test(path)) return presentation('services-clinic', 60000, 300000);
    if (/^\/inventory\/(items|summary|low-stock|alerts\/expiry|transactions)$/.test(path)) return presentation('inventory', 5000, 30000);
    // Legacy archive lists include payment/medical fields: reuse only in memory.
    if (path === '/admin/bookings/archived') return { group: 'history-grooming', fresh: 5000, retain: 15000, persist: false };
    if (path === '/admin/clinic-appointments/archived') return { group: 'history-clinic', fresh: 5000, retain: 15000, persist: false };
    if (path === "/admin/bookings") return { group: "grooming", fresh: 5000, retain: 60000 };
    if (/^\/admin\/dashboard\/(summary|activity)$/.test(path)) return { group: "dashboard", fresh: 15000, retain: 120000 };
    if (path === "/admin/grooming/activity") return { group: "grooming", fresh: 15000, retain: 60000 };
    if (path === "/admin/notifications") return { group: "notifications", fresh: params.get('mode') === 'count' ? 30000 : 10000, retain: 60000 };
    if (path === "/admin/clinic/settings/availability") return { group: "availability", fresh: 0, retain: 0, persist: false };
    if (path === "/clinic/status") return { group: "clinic-status", fresh: 15000, retain: 30000 };
    if (path === "/admin/customers") return params.get("search")
      ? { group: "customers", fresh: 5000, retain: 15000, persist: false }
      : { group: "customers", fresh: 30000, retain: 180000 };
    if (path === "/admin/clinic-cases") return { group: "clinic-active", fresh: 5000, retain: 60000 };
    if (path === "/admin/clinic-records" && params.get("page") === "1") return { group: "clinic-records", fresh: 30000, retain: 180000 };
    // Confined currently includes workspace details; deduplicate only, never persist.
    if (path === "/admin/clinic-confinements") return { group: "clinic-confined", fresh: 0, retain: 0, persist: false };
    return null;
  }

  function clearAdminCache() {
    adminCacheEpoch++;
    adminCache.clear();
    adminRequests.clear();
    adminCacheRevisions.clear();
    adminCacheOwner = adminCacheToken = null;
    safeStorageRemove(sessionStorage, ADMIN_CACHE_KEY);
    safeStorageRemove(sessionStorage, 'bethlehem.admin.presentation.v2');
    safeStorageRemove(sessionStorage, "bethlehem.admin.presentation.v1");
  }

  function ensureAdminCache() {
    const token = getAdminToken(), owner = staffIdentityOwner();
    if (!token || !owner) { clearAdminCache(); return false; }
    if (adminCacheToken === token && adminCacheOwner === owner) return true;
    adminCacheEpoch++;
    adminCache.clear(); adminRequests.clear(); adminCacheRevisions.clear();
    adminCacheToken = token; adminCacheOwner = owner;
    safeStorageRemove(sessionStorage, 'bethlehem.admin.presentation.v2');
    safeStorageRemove(sessionStorage, "bethlehem.admin.presentation.v1");
    try {
      const stored = JSON.parse(safeStorageGet(sessionStorage, ADMIN_CACHE_KEY) || "null");
      if (stored?.owner === owner) {
        for (const [key, entry] of Object.entries(stored.entries || {})) {
          const policy = adminCachePolicy(key), age = Date.now() - entry?.at;
          if (policy && policy.persist !== false && Number.isFinite(age) && age >= 0 && age < policy.retain) adminCache.set(key, entry);
        }
      }
    } catch { /* Storage is optional; network reads remain available. */ }
    return true;
  }

  function persistAdminCache() {
    for (const [key, entry] of adminCache) {
      const policy = adminCachePolicy(key), age = Date.now() - entry.at;
      if (!policy || age < 0 || age >= policy.retain) adminCache.delete(key);
    }
    while (adminCache.size > 24) adminCache.delete(adminCache.keys().next().value);
    const entries = Object.fromEntries([...adminCache].filter(([key]) => adminCachePolicy(key)?.persist !== false));
    try { sessionStorage.setItem(ADMIN_CACHE_KEY, JSON.stringify({ owner: adminCacheOwner, entries })); }
    catch { /* Quota/privacy restrictions only disable navigation reuse. */ }
  }

  function readAdminCache(endpoint) {
    if (!ensureAdminCache() || (endpoint !== "/clinic/status" && isManagedProtectedSession() && (sessionLocked || sessionChecking))) return null;
    const entry = adminCache.get(endpoint), policy = adminCachePolicy(endpoint);
    const age = Date.now() - entry?.at;
    if (!policy || !entry || age < 0 || age >= policy.retain) return null;
    adminCache.delete(endpoint); adminCache.set(endpoint, entry); // Bounded LRU.
    return { data: entry.data, fresh: age < policy.fresh };
  }

  function invalidateAdminCache(groups, broadcast = true) {
    if (!ensureAdminCache() || !groups.length) return;
    for (const group of groups) adminCacheRevisions.set(group, (adminCacheRevisions.get(group) || 0) + 1);
    for (const key of adminCache.keys()) if (groups.includes(adminCachePolicy(key)?.group)) adminCache.delete(key);
    // A Dashboard read already in flight must settle before another can start.
    // Its revision check still rejects the superseded result after a mutation.
    for (const key of adminRequests.keys()) {
      const group = adminCachePolicy(key)?.group;
      if (group !== 'dashboard' && groups.includes(group)) adminRequests.delete(key);
    }
    persistAdminCache();
    if (typeof window.dispatchEvent === 'function') {
      window.dispatchEvent(new CustomEvent('admin-data:invalidated', { detail: { groups } }));
    }
    if (broadcast) {
      try { localStorage.setItem(ADMIN_DATA_CHANGED_KEY, JSON.stringify({ owner: activitySessionId(getAuthSession()), groups, at: Date.now(), nonce: Math.random() })); }
      catch { /* Polling still refreshes other tabs when storage is unavailable. */ }
    }
  }

  function invalidateAdminMutation(endpoint) {
    const path = endpoint.split("?")[0];
    let groups = [];
    if (/^\/inventory\/(?:items|stock-in|stock-out)(?:\/|$)/.test(path) || path === '/pos/transactions'
      || /^\/pos\/online-payment(?:\/|$)/.test(path)) groups = ['inventory'];
    else if (/^\/admin\/clinic-services(?:\/|$)/.test(path)) groups = ['services-clinic'];
    else if (/^\/admin\/notifications\//.test(path)) groups = ["notifications"];
    else if (/^\/admin\/bookings\//.test(path)) {
      groups = ["grooming", "history-grooming"];
      if (!/\/(internal-staff-note|grooming-visit-notes|sedation-consent)$/.test(path)) {
        groups.push("dashboard");
        if (/\/(mark-done|cancel|pay|pay-now)$/.test(path)) groups.push("notifications");
        if (/\/(check-in|revert-check-in|cancel)$/.test(path)) groups.push("customers");
      }
    }
    else if (path === "/admin/walk-in") groups = ["grooming", "dashboard", "notifications", "customers", "clinic-records"];
    else if (/^\/admin\/clinic\/(stop-today|reopen-today)$/.test(path)) groups = ["clinic-status", "availability", "dashboard", "grooming"];
    else if (/^\/admin\/(customers|customer-pets)(?:\/|$)/.test(path)) groups = ["customers", "clinic-records", "clinic-active"];
    else if (/^\/admin\/pets\//.test(path)) {
      groups = /\/vaccinations(?:\/|$)/.test(path) ? ["clinic-active"]
        : ["customers", "clinic-records", "clinic-active", "clinic-confined", "grooming", "dashboard"];
    } else if (/^\/admin\/(clinic-cases|clinic-appointments)(?:\/|$)/.test(path) || path === "/admin/clinic-walk-in") {
      groups = ["clinic-active", "dashboard", "history-clinic"];
      if (/\/(pay|finish|finish-consultation|services|treatments|vaccinations)(?:\/|$)/.test(path)) groups.push('inventory');
      if (/\/(check-in|cancel|finish|finish-consultation|pay)$/.test(path) || path === "/admin/clinic-walk-in") groups.push("notifications");
      if (/\/confinement(?:\/|$)/.test(path)) groups.push("clinic-confined");
      if (path === "/admin/clinic-walk-in" || path === "/admin/clinic-cases") groups.push("customers", "clinic-records");
    } else if (/^\/(?:settings\/availability|admin\/clinic\/settings\/(?:availability|capacity|groomers-on-duty)|admin\/pet-groomers|admin\/grooming\/services)/.test(path)) {
      groups = ["grooming", "dashboard", "availability", "clinic-status"];
      if (path.startsWith('/admin/grooming/services')) groups.push('services-grooming');
    }
    if (/^\/admin\/clinic\/blocked-dates(?:\/|$)/.test(path)) groups.push('availability', 'clinic-status', 'dashboard', 'grooming');
    if (groups.includes('grooming')) groups.push('availability');
    if (groups.includes('availability')) invalidateCustomerCache('capacity');
    if (/^\/admin\/bookings\/[^/]+\/(pay|pay-now|online-payment)$/.test(path)) groups.push('inventory');
    invalidateAdminCache(groups);
  }

  function adminRead(endpoint, token, options) {
    if (!ensureAdminCache()) return networkRequest("GET", endpoint, null, token, options);
    const policy = adminCachePolicy(endpoint), cached = readAdminCache(endpoint);
    if (!options.force && cached?.fresh) return Promise.resolve(cached.data);
    if (adminRequests.has(endpoint)) return adminRequests.get(endpoint);
    const owner = adminCacheOwner, epoch = adminCacheEpoch, revision = adminCacheRevisions.get(policy.group) || 0;
    const notificationSequence = policy.group === 'notifications' ? ++notificationReadSequence : 0;
    const pending = networkRequest("GET", endpoint, null, token, options).then(data => {
      if (adminCacheToken !== getAdminToken() || owner !== adminCacheOwner || epoch !== adminCacheEpoch
        || revision !== (adminCacheRevisions.get(policy.group) || 0)) {
        throw new Error("Admin data changed while loading. Please retry.");
      }
      if (policy.retain) {
        // Scheduled events have no browser mutation to invalidate cached pages.
        if (policy.group === 'notifications' && notificationSequence >= notificationCountSequence
          && Number.isFinite(data.unread_count)) {
          const previousCount = adminCache.get('/admin/notifications?mode=count')?.data?.unread_count;
          if (previousCount !== undefined && previousCount !== data.unread_count) {
            for (const key of adminCache.keys()) {
              if (adminCachePolicy(key)?.group === 'notifications') adminCache.delete(key);
            }
          }
        }
        if (endpoint !== '/admin/notifications?mode=count' || notificationSequence >= notificationCountSequence) {
          adminCache.delete(endpoint); adminCache.set(endpoint, { at: Date.now(), data });
        }
        // Any notification response already carries the global unread count.
        if (notificationSequence >= notificationCountSequence && policy.group === 'notifications' && Number.isFinite(data.unread_count)) {
          notificationCountSequence = notificationSequence;
          adminCache.set('/admin/notifications?mode=count', { at: Date.now(), data: { success: true, unread_count: data.unread_count } });
        }
        persistAdminCache();
      }
      return data;
    }).finally(() => { if (adminRequests.get(endpoint) === pending) adminRequests.delete(endpoint); });
    adminRequests.set(endpoint, pending);
    return pending;
  }

  async function loadAdminData(endpoint, load, render, { force = false, onRefreshError } = {}) {
    const token = getAdminToken();
    // Re-read retention after the server verifies/unlocks this document's session.
    // Otherwise a stale cache hit during startup would become a cold network load.
    if (isManagedProtectedSession() && (sessionLocked || sessionChecking)) {
      await new Promise((resolve, reject) => sessionWaiters.push({ resolve, reject }));
      if (token !== getAdminToken()) throw new Error("Session ended.");
    }
    const cached = readAdminCache(endpoint);
    if (cached) render(cached.data);
    if (cached?.fresh && !force) return cached.data;
    let data;
    try { data = await load(); }
    catch (error) {
      if (cached && token === getAdminToken() && readAdminCache(endpoint)?.data === cached.data
        && (error.status === 0 || error.status >= 500)) {
        onRefreshError?.(error);
        return cached.data;
      }
      throw error;
    }
    if (!cached || JSON.stringify(data) !== JSON.stringify(cached.data)) render(data);
    return data;
  }

  // Only customer presentation data is eligible. Medical records, pet detail,
  // slot availability and scheduling rules always go to the server.
  function customerCachePolicy(endpoint) {
    if (endpoint === "/me") return { group: "profile", fresh: 60000, retain: 300000 };
    if (/^\/pets\?archived=[01]$/.test(endpoint)) return { group: "pets", fresh: 60000, retain: 300000 };
    if (endpoint === "/booking/history") return { group: "bookings", fresh: 15000, retain: 120000 };
    if (/^\/booking\/history\?history_limit=\d+$/.test(endpoint)) return { group: "bookings", fresh: 5000, retain: 120000 };
    if (endpoint === "/booking/grooming-capacity") return { group: "capacity", fresh: 5000, retain: 15000 };
    if (/^\/customer\/notifications(?:\?|$)/.test(endpoint)) return { group: "notifications", fresh: 5000, retain: 30000 };
    if (endpoint === "/pre-registration/access") return { group: "access", fresh: 5000, retain: 5000 };
    return null;
  }

  function clearCustomerCache() {
    customerCacheEpoch += 1;
    customerCache.clear();
    customerRequests.clear();
    customerCacheRevisions.clear();
    customerCacheOwner = null;
    customerCacheToken = null;
    safeStorageRemove(sessionStorage, CUSTOMER_CACHE_KEY);
  }

  function ensureCustomerCacheSession() {
    const token = getCustomerToken();
    // Sanctum IDs identify a session without storing its secret. Newly created
    // sessions also carry a random generation shared with their auth storage.
    const tokenId = /^(\d+)\|/.exec(token || "")?.[1];
    const generation = safeStorageGet(customerSessionStorage(), CUSTOMER_CACHE_SESSION_KEY);
    const owner = token ? `${BASE_URL}:${tokenId || ""}:${generation || ""}` : null;
    if (!owner || (!tokenId && !generation)) {
      clearCustomerCache();
      return false;
    }
    if (customerCacheOwner === owner && customerCacheToken === token) return true;
    customerCacheEpoch += 1;
    customerCache.clear();
    customerRequests.clear();
    customerCacheRevisions.clear();
    customerCacheOwner = owner;
    customerCacheToken = token;
    let profileChange = null;
    if (customerSessionStorage() === localStorage) {
      try { profileChange = JSON.parse(safeStorageGet(localStorage, CUSTOMER_PROFILE_CHANGED_KEY) || 'null'); }
      catch { /* A malformed signal does not invalidate unrelated retained data. */ }
    }
    try {
      const stored = JSON.parse(safeStorageGet(sessionStorage, CUSTOMER_CACHE_KEY) || "null");
      if (stored?.owner === owner) {
        Object.entries(stored.entries || {}).forEach(([key, entry]) => {
          const policy = customerCachePolicy(key);
          const age = Date.now() - entry?.at;
          if (policy && Number.isFinite(age) && age >= 0 && age < policy.retain && entry.data) {
            if (key === '/me' && entry.data.user && profileChange?.owner === activitySessionId(getAuthSession())
              && profileChange.at >= entry.at && typeof profileChange.first_name === 'string' && typeof profileChange.last_name === 'string') {
              entry.data.user = { ...entry.data.user, first_name: profileChange.first_name, last_name: profileChange.last_name };
            }
            customerCache.set(key, entry);
          }
        });
      }
    } catch { /* Corrupt or unavailable storage is a cache miss. */ }
    persistCustomerCache();
    return true;
  }

  function persistCustomerCache() {
    customerCache.forEach((entry, key) => {
      const policy = customerCachePolicy(key);
      if (!policy || Date.now() - entry.at >= policy.retain) customerCache.delete(key);
    });
    // Bound parameterized notification variants in this small per-tab cache.
    while (customerCache.size > 24) customerCache.delete(customerCache.keys().next().value);
    try {
      // Cached customer views do not use free-text booking notes.
      sessionStorage.setItem(CUSTOMER_CACHE_KEY, JSON.stringify({
        owner: customerCacheOwner, entries: Object.fromEntries(customerCache),
      }, (key, value) => key === "special_notes" ? undefined : value));
    } catch { /* Memory caching still works when storage is full or disabled. */ }
  }

  function readCustomerCache(endpoint) {
    if (!ensureCustomerCacheSession()) return null;
    const policy = customerCachePolicy(endpoint);
    const entry = customerCache.get(endpoint);
    const age = Date.now() - entry?.at;
    if (!policy || !entry || age < 0 || age >= policy.retain) return null;
    return { data: entry.data, fresh: age < policy.fresh };
  }

  function invalidateCustomerCache(...groups) {
    if (!ensureCustomerCacheSession()) return;
    groups.forEach((group) => customerCacheRevisions.set(group, (customerCacheRevisions.get(group) || 0) + 1));
    customerCache.forEach((entry, key) => {
      if (groups.includes(customerCachePolicy(key)?.group)) customerCache.delete(key);
    });
    customerRequests.forEach((promise, key) => {
      if (groups.includes(customerCachePolicy(key)?.group)) customerRequests.delete(key);
    });
    persistCustomerCache();
    if (groups.includes("profile")) {
      safeStorageRemove(customerSessionStorage(), CUSTOMER_PROFILE_NAME_KEY);
    }
  }

  // Render retained data synchronously, then refresh without hiding that data.
  // Access/authorization failures still propagate through the normal handlers.
  async function loadCustomerData(endpoint, load, render, { force = false } = {}) {
    const token = getCustomerToken();
    const cached = readCustomerCache(endpoint);
    if (cached) render(cached.data);
    if (cached?.fresh && !force) return cached.data;
    let data;
    try {
      data = await load();
    } catch (error) {
      if (cached && token === getCustomerToken() && readCustomerCache(endpoint)?.data === cached.data
        && (error.status === 0 || error.status >= 500)) {
        return cached.data;
      }
      throw error;
    }
    if (cached && JSON.stringify(data) === JSON.stringify(cached.data)) return cached.data;
    render(data);
    return data;
  }

  function invalidateCustomerMutation(endpoint) {
    if (endpoint === "/phone-verification/verify") invalidateCustomerCache("profile", "access");
    else if (["/phone-verification/send", "/phone-verification/cancel"].includes(endpoint)) invalidateCustomerCache("profile");
    else if (/^\/pets(?:\/|$)/.test(endpoint)) invalidateCustomerCache("pets", "bookings", "notifications", "access");
    else if (/^\/(?:booking\/(?:store|cancel|reschedule)|clinic\/pre-register)$/.test(endpoint)) {
      invalidateCustomerCache("bookings", "capacity", "notifications", "access");
      // Grooming pre-registration can create pets inside the same transaction.
      if (endpoint === "/booking/store") invalidateCustomerCache("pets");
    } else if (/^\/customer\/notifications\//.test(endpoint)) invalidateCustomerCache("notifications");
  }
  const INACTIVITY_WARNING_ID = "bethlehem-session-inactivity-warning";
  const ACTIVITY_WRITE_THROTTLE_MS = 1000;
  const SESSION_INACTIVITY_POLICIES = Object.freeze({
    admin: Object.freeze({ timeoutMs: 30 * 60 * 1000, warningMs: 29 * 60 * 1000 }),
    staff: Object.freeze({ timeoutMs: 30 * 60 * 1000, warningMs: 29 * 60 * 1000 }),
    customer: Object.freeze({ timeoutMs: 30 * 60 * 1000, warningMs: 29 * 60 * 1000 }),
  });
  const ADMIN_ONLY_PAGE_NAMES = [
    "reports.html",
  ];
  const PUBLIC_CLIENT_PAGE_NAMES = new Set([
    "sign-in.html",
    "signup.html",
    "verify-email.html",
    "forgot-password.html",
    "set-up-password.html",
    "confirm-email-recovery.html",
  ]);
  const INVALID_SESSION_CODES = new Set([
    "account_disabled",
    "email_not_verified",
    "password_setup_required",
  ]);
  const AUTH_STORAGE_KEYS = [
    CUSTOMER_TOKEN_KEY,
    ADMIN_TOKEN_KEY,
    USER_ROLE_KEY,
    AUTH_LAST_ACTIVITY_KEY,
    CUSTOMER_PROFILE_NAME_KEY,
    CUSTOMER_PROFILE_CHANGED_KEY,
  ];

  let inactivityTimerId = null;
  let inactivityManagerInstalled = false;
  let inactivityLogoutStarted = false;
  let lastActivityWriteAt = 0;
  let sessionLocked = false;
  let sessionChecking = false;
  let serverSession = null;
  let sessionSync = null;
  let emailChangeSignOut = null;
  let sessionPollTimer = null;
  let activityReportTimer = null;
  let lastActivityReportAt = 0;
  let sessionRevision = 0;
  let previousSessionFocus = null;
  const sessionWaiters = [];
  const pausedSessionReads = new Map();

  // ── Booking session keys to wipe on customer logout / login ──────────────
  const BOOKING_SESSION_KEYS = [
    "bookingStep2",
    "bookingStep3",
    "bookingStep4Review",
    "bookingReview",
    "bookingConsentStep",
    "bookingConfirmation",
    "bethlehem.bookingFormLock",
    "bookingPets",
    "clinicVisitDraft",
    "clinicVisitPets",
    "clinicVisitConfirmation",
  ];
  const BOOKING_LOCAL_KEYS = [
    "bethlehem.bookingFormLock",
    "shownPickupNotifs",
    "clientPets",
  ];

  function clearBookingDraft() {
    BOOKING_SESSION_KEYS.forEach((key) => safeStorageRemove(sessionStorage, key));
    BOOKING_LOCAL_KEYS.forEach((key) => safeStorageRemove(localStorage, key));
  }

  // ── Token helpers ─────────────────────────────────────────────────────────

  function isAdminRole(role) {
    return role === "admin" || role === "staff";
  }

  function safeStorageGet(storage, key) {
    try {
      return storage.getItem(key);
    } catch {
      return null;
    }
  }

  function safeStorageRemove(storage, key) {
    try {
      storage.removeItem(key);
    } catch {
      // Storage can be unavailable in privacy-restricted browser contexts.
    }
  }

  function rawCustomerToken() {
    return safeStorageGet(localStorage, CUSTOMER_TOKEN_KEY)
      || safeStorageGet(sessionStorage, CUSTOMER_TOKEN_KEY);
  }

  function rawAdminToken() {
    return safeStorageGet(localStorage, ADMIN_TOKEN_KEY)
      || safeStorageGet(sessionStorage, ADMIN_TOKEN_KEY);
  }

  function readAuthSessionFrom(storage, persistent) {
    const role = safeStorageGet(storage, USER_ROLE_KEY);
    const tokenKey = isAdminRole(role) ? ADMIN_TOKEN_KEY : CUSTOMER_TOKEN_KEY;
    let token = role === "customer" || isAdminRole(role)
      ? safeStorageGet(storage, tokenKey)
      : null;
    const legacy = /^(\d+)\|[^|]*[A-Za-z0-9]{48}$/.exec(token || "");
    if (legacy) {
      const publicId = `${legacy[1]}|cookie`;
      legacyCredentials.set(publicId, token);
      safeStorageRemove(storage, tokenKey);
      try { storage.setItem(tokenKey, publicId); } catch { return null; }
      token = publicId;
    }

    return token ? { token, role, tokenKey, persistent } : null;
  }

  function getAuthSession() {
    const persistent = readAuthSessionFrom(localStorage, true);
    const temporary = readAuthSessionFrom(sessionStorage, false);

    // Mixed persistent/temporary sessions are ambiguous legacy state. Treat
    // them as signed out instead of guessing and sending an obsolete token.
    if (persistent && temporary) return null;

    return persistent || temporary;
  }

  function clearAuthStorage() {
    observedSession = null;
    safeStorageRemove(sessionStorage, SIGNOUT_REASON_KEY);
    clearAdminCache();
    clearStaffIdentity();
    verifiedSidebarRevision = null;
    sessionRevision += 1;
    serverSession = null;
    sessionSync = null;
    if (sessionPollTimer) clearTimeout(sessionPollTimer);
    if (activityReportTimer) clearTimeout(activityReportTimer);
    sessionPollTimer = activityReportTimer = null;
    sessionWaiters.splice(0).forEach(({ reject }) => reject(new Error("Session ended.")));
    pausedSessionReads.clear();
    clearCustomerCache();
    safeStorageRemove(localStorage, CUSTOMER_CACHE_SESSION_KEY);
    safeStorageRemove(sessionStorage, CUSTOMER_CACHE_SESSION_KEY);
    AUTH_STORAGE_KEYS.forEach((key) => {
      safeStorageRemove(localStorage, key);
      safeStorageRemove(sessionStorage, key);
    });
    clearPendingLoginConfirmation();
    pauseInactivityTracking();
  }

  function clearPendingLoginConfirmation() {
    safeStorageRemove(sessionStorage, LOGIN_POLL_TOKEN_KEY);
    safeStorageRemove(sessionStorage, LOGIN_CONFIRMATION_EMAIL_KEY);
    safeStorageRemove(sessionStorage, "pending_login_email_delivery");
    safeStorageRemove(sessionStorage, "pendingLoginResendUntil");
    safeStorageRemove(sessionStorage, "pendingLoginVerifyUntil");
  }

  function storePendingLoginConfirmation(pollToken, email = "") {
    if (!pollToken) {
      throw new Error("The server did not return a valid login confirmation.");
    }

    clearAuthStorage();
    try {
      sessionStorage.setItem(LOGIN_POLL_TOKEN_KEY, pollToken);
      sessionStorage.setItem(LOGIN_CONFIRMATION_EMAIL_KEY, email);
    } catch (error) {
      clearPendingLoginConfirmation();
      throw error;
    }
  }

  function hasPendingLoginConfirmation() {
    return !!safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY);
  }

  function getPendingLoginConfirmationEmail() {
    return safeStorageGet(sessionStorage, LOGIN_CONFIRMATION_EMAIL_KEY) || "";
  }

  function setAuthSession(token, role, remember = true) {
    if (!token || (role !== "customer" && !isAdminRole(role))) {
      throw new Error("The server returned an invalid authentication session.");
    }

    const persistent = isAdminRole(role) || remember;
    const storage = persistent ? localStorage : sessionStorage;
    const tokenKey = isAdminRole(role) ? ADMIN_TOKEN_KEY : CUSTOMER_TOKEN_KEY;

    // A browser has one current Bethlehem session. Clear every old token/role
    // first so localStorage can never shadow a newer sessionStorage login.
    clearAuthStorage();

    try {
      storage.setItem(tokenKey, token);
      storage.setItem(USER_ROLE_KEY, role);
    } catch (error) {
      // Do not leave a half-written token without its matching role.
      clearAuthStorage();
      throw new Error("Browser storage is unavailable. Allow storage to continue.");
    }

    if (role === "customer") {
      try {
        storage.setItem(CUSTOMER_CACHE_SESSION_KEY, window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`);
      } catch { /* Optional cache metadata must never prevent sign-in. */ }
    }

    inactivityLogoutStarted = false;
    authRedirectStarted = false;
    const session = getAuthSession();
    observedSession = session;
    const timestamp = Date.now();
    writeSessionActivity(session, timestamp);
    lastActivityWriteAt = timestamp;
    if (inactivityManagerInstalled) evaluateSessionInactivity(timestamp);
  }

  function getCustomerToken() {
    const session = getAuthSession();
    return session?.role === "customer" ? session.token : null;
  }

  function setCustomerToken(token, remember = true) {
    setAuthSession(token, "customer", remember);
  }

  function clearCustomerToken() {
    clearCustomerCache();
    const role = getUserRole();
    safeStorageRemove(localStorage, CUSTOMER_TOKEN_KEY);
    safeStorageRemove(sessionStorage, CUSTOMER_TOKEN_KEY);

    if (role === "customer") {
      clearUserRole();
    }
  }

  function getUserRole() {
    return getAuthSession()?.role ?? null;
  }

  function setUserRole(role, remember = true) {
    const token = isAdminRole(role) ? rawAdminToken() : rawCustomerToken();
    if (token) {
      setAuthSession(token, role, remember);
      return;
    }

    clearUserRole();
  }

  function clearUserRole() {
    safeStorageRemove(localStorage, USER_ROLE_KEY);
    safeStorageRemove(sessionStorage, USER_ROLE_KEY);
  }

  function getAdminToken() {
    const session = getAuthSession();
    return isAdminRole(session?.role) ? session.token : null;
  }

  function setAdminToken(token) {
    setAuthSession(token, "admin", true);
  }

  function clearAdminToken() {
    clearStaffIdentity();
    const role = getUserRole();
    safeStorageRemove(localStorage, ADMIN_TOKEN_KEY);
    safeStorageRemove(sessionStorage, ADMIN_TOKEN_KEY);

    if (isAdminRole(role)) {
      clearUserRole();
    }
  }

  function isAdminPage() {
    return window.location.pathname.replace(/\\/g, "/").includes("/pages/admin/")
      || window.location.pathname.endsWith("/api/admin/activity-log/page")
      || window.location.pathname.endsWith("/api/admin/availability/page");
  }

  function isSignInPage() {
    return window.location.pathname.replace(/\\/g, "/").includes("/pages/client/sign-in.html");
  }

  function isProtectedClientPage() {
    const path = window.location.pathname.replace(/\\/g, "/");
    if (!path.includes("/pages/client/")) return false;
    return !PUBLIC_CLIENT_PAGE_NAMES.has(currentPageName());
  }

  function appBasePath(pathname = window.location.pathname) {
    const path = String(pathname || "/").replace(/\\/g, "/");
    const markerIndex = path.toLowerCase().indexOf(path.endsWith("/api/admin/activity-log/page") ? "/api/" : "/pages/");

    if (markerIndex >= 0) {
      return path.slice(0, markerIndex).replace(/\/$/, "");
    }

    const directory = path.endsWith("/")
      ? path.replace(/\/$/, "")
      : path.slice(0, path.lastIndexOf("/"));

    return directory === "/" ? "" : directory;
  }

  function appPath(relativePath) {
    const suffix = String(relativePath || "").replace(/^\/+/, "");
    return `${appBasePath()}/${suffix}`.replace(/\/{2,}/g, "/");
  }

  function signInPath() {
    return appPath("pages/client/sign-in.html");
  }

  function redirectToSignIn({ replace = true } = {}) {
    if (!isSignInPage() && !authRedirectStarted) {
      authRedirectStarted = true;
      const target = signInPath();
      if (replace && typeof window.location.replace === "function") {
        window.location.replace(target);
      } else {
        window.location.href = target;
      }
    }
  }

  function currentPageName() {
    const path = window.location.pathname.replace(/\\/g, "/");
    return path.split("/").filter(Boolean).pop() || "";
  }

  function isAdminOnlyPage() {
    return isAdminPage() && (ADMIN_ONLY_PAGE_NAMES.includes(currentPageName()) || window.location.pathname.endsWith("/api/admin/activity-log/page"));
  }

  function redirectToAdminDashboard() {
    if (currentPageName() !== "dashboard.html") {
      window.location.replace(appPath("pages/admin/dashboard.html"));
    }
  }

  function hasAuthenticatedSession(scope = "any") {
    const session = getAuthSession();
    if (!session) return false;
    if (scope === "customer") return session.role === "customer";
    if (scope === "admin") return isAdminRole(session.role);
    return true;
  }

  function enforceAdminPageAccess() {
    if (!isAdminPage()) return true;

    const role = getUserRole();
    if (!getAdminToken() || (role !== "admin" && role !== "staff")) {
      redirectToSignIn();
      return false;
    }

    const user = getCachedStaffIdentity()?.user;
    if (user?.staff_permissions?.clinic_workspace && ['appointments.html', 'services.html', 'pos.html', 'stock-in.html', 'stock-out.html'].includes(currentPageName())) {
      redirectToAdminDashboard();
      return false;
    }

    if (isAdminOnlyPage() && role !== "admin") {
      redirectToAdminDashboard();
      return false;
    }

    return true;
  }

  function enforceProtectedPageAccess() {
    if (isAdminPage()) return enforceAdminPageAccess();

    if (isProtectedClientPage() && !hasAuthenticatedSession("customer")) {
      redirectToSignIn();
      return false;
    }

    return true;
  }

  function logoutSessionId(session) {
    // Broadcast only the Sanctum row ID, never the bearer token's secret.
    const tokenId = /^(\d+)\|/.exec(session?.token || "")?.[1];
    return tokenId && session?.role ? `${session.role}:${tokenId}` : null;
  }

  function notifyLogout(role, reason = "logout", session = null) {
    try {
      localStorage.setItem(
        AUTH_LOGOUT_EVENT_KEY,
        JSON.stringify({ role, reason, sessionId: logoutSessionId(session), at: Date.now() })
      );
    } catch {
      // Non-fatal: removing the token still syncs logout in supported browsers.
    }
  }

  function invalidateSession(role = null, reason = "expired") {
    const session = getAuthSession();
    const resolvedRole = session?.role ?? role ?? "customer";
    clearAuthStorage();
    if (resolvedRole === "customer") clearBookingDraft();
    notifyLogout(resolvedRole, reason, session);
    rememberSignoutReason(reason);
    redirectToSignIn();
  }

  function rememberSignoutReason(reason) {
    if (reason === 'session_replaced') {
      try { sessionStorage.setItem(SIGNOUT_REASON_KEY, reason); } catch { /* Storage may be unavailable. */ }
    } else safeStorageRemove(sessionStorage, SIGNOUT_REASON_KEY);
  }

  function handleCrossTabLogout(event) {
    const session = getAuthSession() || observedSession;
    if (!session) {
      // Persistent auth may already have been removed by another tab.
      // Do not erase a pending login confirmation or unrelated draft.
      enforceProtectedPageAccess();
      return;
    }
    if (!event?.sessionId || event.sessionId !== logoutSessionId(session)) return;

    clearAuthStorage();
    if (session.role === "customer") clearBookingDraft();
    rememberSignoutReason(event.reason);

    if (isAdminPage() || isProtectedClientPage()) {
      redirectToSignIn();
    }
  }

  function getSessionInactivityPolicy(role = getUserRole()) {
    return SESSION_INACTIVITY_POLICIES[role] || null;
  }

  function activitySessionId(session) {
    if (!session?.token || !session?.role) return "";

    const tokenId = String(session.token).split("|", 1)[0];
    return `${session.role}:${tokenId}`;
  }

  function readSessionActivity(session) {
    const expectedSessionId = activitySessionId(session);
    if (!expectedSessionId) return null;

    const stored = safeStorageGet(localStorage, AUTH_LAST_ACTIVITY_KEY)
      || safeStorageGet(sessionStorage, AUTH_LAST_ACTIVITY_KEY);
    if (!stored) return null;

    try {
      const activity = JSON.parse(stored);
      const timestamp = Number(activity?.at);
      return activity?.session === expectedSessionId && Number.isFinite(timestamp)
        ? timestamp
        : null;
    } catch {
      return null;
    }
  }

  function writeSessionActivity(session, timestamp) {
    const sessionId = activitySessionId(session);
    if (!sessionId) return;

    const value = JSON.stringify({ session: sessionId, at: timestamp });
    try {
      localStorage.setItem(AUTH_LAST_ACTIVITY_KEY, value);
    } catch {
      try {
        sessionStorage.setItem(AUTH_LAST_ACTIVITY_KEY, value);
      } catch {
        // The in-memory timer still protects this tab when storage is unavailable.
      }
    }
  }

  function isManagedProtectedSession(session = getAuthSession()) {
    if (!session || !getSessionInactivityPolicy(session.role)) return false;
    if (isAdminPage()) return isAdminRole(session.role);
    return isProtectedClientPage() && session.role === "customer";
  }

  function inactivityWarningElement() {
    if (typeof document === "undefined") return null;
    return document.getElementById?.(INACTIVITY_WARNING_ID) || null;
  }

  function ensureInactivityWarningElement() {
    const existing = inactivityWarningElement();
    if (existing) return existing;
    if (
      typeof document === "undefined"
      || typeof document.createElement !== "function"
      || !document.body
    ) {
      return null;
    }

    const toast = document.createElement("div");
    toast.id = INACTIVITY_WARNING_ID;
    toast.setAttribute("role", "status");
    toast.setAttribute("aria-live", "polite");
    toast.textContent = "Your session will expire soon due to inactivity.";
    toast.style.cssText = [
      "position: fixed",
      "right: 1rem",
      "bottom: 1rem",
      "z-index: 10001",
      "display: none",
      "max-width: min(24rem, calc(100vw - 2rem))",
      "border: 1px solid #f59e0b",
      "border-radius: 0.875rem",
      "background: #fff7ed",
      "padding: 0.875rem 1rem",
      "color: #9a3412",
      "font-size: 0.875rem",
      "font-weight: 700",
      "line-height: 1.4",
      "box-shadow: 0 16px 35px rgba(15, 23, 42, 0.22)",
    ].join(";");
    document.body.appendChild(toast);
    return toast;
  }

  function showInactivityWarning() {
    if (isAdminRole(getAuthSession()?.role)) return;
    const toast = ensureInactivityWarningElement();
    if (toast) toast.style.display = "block";
  }

  function hideInactivityWarning() {
    const toast = inactivityWarningElement();
    if (toast) toast.style.display = "none";
  }

  function pauseInactivityTracking() {
    if (inactivityTimerId !== null) {
      clearTimeout(inactivityTimerId);
      inactivityTimerId = null;
    }
    hideInactivityWarning();
  }

  function scheduleInactivityCheck(delayMs) {
    if (inactivityTimerId !== null) clearTimeout(inactivityTimerId);
    inactivityTimerId = setTimeout(
      () => evaluateSessionInactivity(),
      Math.max(0, Math.ceil(delayMs)),
    );
  }

  function expireSessionForInactivity(session) {
    if (inactivityLogoutStarted) return;
    inactivityLogoutStarted = true;
    pauseInactivityTracking();

    // logout() clears browser state synchronously and starts a keepalive
    // revocation request before navigation, so the abandoned token is unusable.
    void logout(session.role, { reason: "inactivity", keepalive: true }).catch(() => {});
    redirectToSignIn({ replace: true });
  }

  function evaluateSessionInactivity(timestamp = Date.now()) {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session)) {
      pauseInactivityTracking();
      return { state: "inactive", idleMs: 0 };
    }

    const policy = getSessionInactivityPolicy(session.role);
    if (serverSession?.token === session.token && timestamp >= serverSession.expiresAt) {
      expireSessionForInactivity(session);
      return { state: "expired", idleMs: 0 };
    }
    if (isAdminRole(session.role) && (sessionLocked || sessionChecking)) {
      return { state: sessionLocked ? "locked" : "checking", idleMs: 0 };
    }
    let lastActivityAt = serverSession?.token === session.token
      ? serverSession.idleAt - policy.timeoutMs
      : readSessionActivity(session);
    if (lastActivityAt === null) {
      lastActivityAt = timestamp;
      writeSessionActivity(session, lastActivityAt);
      lastActivityWriteAt = lastActivityAt;
    }

    const idleMs = Math.max(0, timestamp - lastActivityAt);
    if (idleMs >= policy.timeoutMs) {
      if (isAdminRole(session.role)) {
        showSessionLock();
        void syncSessionState();
        return { state: "locked", idleMs };
      }
      expireSessionForInactivity(session);
      return { state: "expired", idleMs };
    }

    if (idleMs >= policy.warningMs) {
      showInactivityWarning();
      scheduleInactivityCheck(policy.timeoutMs - idleMs);
      return { state: "warning", idleMs };
    }

    hideInactivityWarning();
    scheduleInactivityCheck(policy.warningMs - idleMs);
    return { state: "active", idleMs };
  }

  function recordSessionActivity(force = false) {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session) || inactivityLogoutStarted || sessionLocked || sessionChecking) return;
    if (["expired", "locked"].includes(evaluateSessionInactivity().state)) return;

    const timestamp = Date.now();
    if (!force && timestamp - lastActivityWriteAt < ACTIVITY_WRITE_THROTTLE_MS) {
      return;
    }

    lastActivityWriteAt = timestamp;
    writeSessionActivity(session, timestamp);
    if (serverSession?.token === session.token) serverSession.idleAt = timestamp + 30 * 60 * 1000;
    hideInactivityWarning();
    const policy = getSessionInactivityPolicy(session.role);
    scheduleInactivityCheck(policy.warningMs);
    reportSessionActivity();
  }

  function sessionGate(state) {
    const root = document.documentElement;
    if (state) root?.setAttribute("data-session-gate", state);
    else root?.removeAttribute("data-session-gate");
    document.querySelectorAll?.('.admin-sidebar-menu [data-admin-navigation]').forEach(region => {
      region.inert = Boolean(state);
    });
    const card = document.getElementById("bethlehem-session-lock");
    if (!card) return;
    card.hidden = state !== "locked" && state !== "unavailable";
    if (card.hidden) return;
    card.querySelector("#session-lock-form").hidden = state !== "locked";
    card.querySelector("#session-retry").hidden = state !== "unavailable";
    card.querySelector("#session-lock-heading").textContent = state === "locked" ? "Session locked" : "Unable to verify session";
    card.querySelector("#session-lock-description").textContent = state === "locked" ? "Your session was locked due to inactivity." : "Check your connection and try again.";
    card.setAttribute("aria-busy", String(state === "locked" && card.querySelector("#session-unlock").disabled));
  }

  function ensureSessionLockCard() {
    let card = document.getElementById("bethlehem-session-lock");
    if (card || !document.body) return card;
    card = document.createElement("section");
    card.id = "bethlehem-session-lock";
    card.className = "portal-theme session-lock";
    card.hidden = true;
    card.setAttribute("role", "dialog");
    card.setAttribute("aria-modal", "true");
    card.setAttribute("aria-labelledby", "session-lock-heading");
    card.setAttribute("aria-describedby", "session-lock-description");
    card.innerHTML = `
      <div class="session-lock__card">
        <span class="session-lock__icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg></span>
        <h1 id="session-lock-heading" tabindex="-1">Session locked</h1>
        <p id="session-lock-description">Your session was locked due to inactivity.</p>
        <form id="session-lock-form">
          <label for="session-password">Password</label>
          <div class="session-lock__password">
            <input id="session-password" name="password" type="password" autocomplete="current-password" maxlength="100" required aria-describedby="session-error">
            <button id="session-password-toggle" type="button" aria-label="Show password" aria-pressed="false">Show</button>
          </div>
          <p id="session-error" role="alert" hidden></p>
          <button id="session-unlock" class="session-lock__primary" type="submit">Unlock session</button>
        </form>
        <button id="session-retry" class="session-lock__primary" type="button" hidden>Retry</button>
        <button id="session-other-account" class="session-lock__secondary" type="button">Sign in with another account</button>
      </div>`;
    document.body.appendChild(card);
    card.querySelector("#session-retry").addEventListener("click", async () => {
      const button = card.querySelector("#session-retry");
      if (button.disabled) return;
      button.disabled = true;
      button.textContent = "Retrying…";
      card.setAttribute("aria-busy", "true");
      try { await syncSessionState(); }
      finally {
        button.disabled = false;
        button.textContent = "Retry";
        card.setAttribute("aria-busy", "false");
        if (!card.hidden && !button.hidden) button.focus();
      }
    });
    const password = card.querySelector("#session-password");
    const toggle = card.querySelector("#session-password-toggle");
    toggle.addEventListener("click", () => {
      const reveal = password.type === "password";
      password.type = reveal ? "text" : "password";
      toggle.textContent = reveal ? "Hide" : "Show";
      toggle.setAttribute("aria-label", reveal ? "Hide password" : "Show password");
      toggle.setAttribute("aria-pressed", String(reveal));
    });
    card.querySelector("#session-lock-form").addEventListener("submit", async (event) => {
      event.preventDefault();
      const button = card.querySelector("#session-unlock");
      if (button.disabled) return;
      const token = getAuthSession()?.token;
      const revision = ++sessionRevision;
      button.disabled = true;
      button.textContent = "Unlocking…";
      card.setAttribute("aria-busy", "true");
      sessionLockError("");
      password.setAttribute("aria-invalid", "false");
      try {
        const data = await networkRequest("POST", "/session/unlock", { password: password.value }, token);
        if (getAuthSession()?.token !== token) return;
        if (revision === sessionRevision) {
          applyServerSession(data, token);
        }
        broadcastSessionState();
      } catch (error) {
        if (getAuthSession()?.token !== token) return;
        sessionLockError(error.message);
        password.setAttribute("aria-invalid", String(error.status === 422));
        password.focus();
        password.select?.();
      } finally {
        button.disabled = false;
        button.textContent = "Unlock session";
        card.setAttribute("aria-busy", "false");
      }
    });
    card.querySelector("#session-other-account").addEventListener("click", async () => {
      const button = card.querySelector("#session-other-account");
      button.disabled = true;
      try {
        await logout(null, { keepalive: true, requireRevocation: true });
        redirectToSignIn();
      } catch (error) {
        // Keep the token available for a revocation retry if the network failed.
        sessionLockError("Unable to sign out. Please try again.");
      } finally { button.disabled = false; }
    });
    card.addEventListener("keydown", (event) => {
      if (event.key !== "Tab") return;
      const controls = [...card.querySelectorAll("input:not(:disabled), button:not(:disabled)")]
        .filter(control => !control.closest("[hidden]"));
      const first = controls[0], last = controls.at(-1);
      if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && (document.activeElement === last || !controls.includes(document.activeElement))) { event.preventDefault(); first?.focus(); }
    });
    return card;
  }

  function sessionLockError(message) {
    const error = document.getElementById(sessionChecking && !sessionLocked ? "session-lock-description" : "session-error");
    if (error) { error.textContent = message; error.hidden = !message; }
  }

  function resetSessionPassword() {
    const password = document.getElementById("session-password");
    const toggle = document.getElementById("session-password-toggle");
    if (password) {
      password.value = "";
      password.type = "password";
      password.setAttribute("aria-invalid", "false");
    }
    if (toggle) {
      toggle.textContent = "Show";
      toggle.setAttribute("aria-label", "Show password");
      toggle.setAttribute("aria-pressed", "false");
    }
    sessionLockError("");
  }

  function concealSession() {
    if (!isAdminRole(getAuthSession()?.role) || !isManagedProtectedSession()) return;
    // Navigation/BFCache restoration requires a response newer than suspension.
    sessionRevision += 1;
    if (!sessionLocked && !sessionChecking) previousSessionFocus = document.activeElement;
    sessionChecking = !sessionLocked;
    sessionGate(sessionLocked ? "locked" : "checking");
  }

  async function waitForSessionVerification() {
    if (!enforceAdminPageAccess()) return false;
    const token = getAdminToken();
    if (!token) return false;
    while (sessionLocked || sessionChecking) {
      try { await new Promise((resolve, reject) => sessionWaiters.push({ resolve, reject })); }
      catch { return false; }
      if (getAdminToken() !== token) return false;
    }
    return getAdminToken() === token;
  }

  function showSessionLock() {
    if (!isAdminRole(getAuthSession()?.role) || !isManagedProtectedSession()) return;
    const wasLocked = sessionLocked;
    if (!wasLocked) sessionRevision += 1;
    sessionLocked = true;
    sessionChecking = false;
    if (!wasLocked && typeof window.dispatchEvent === 'function') window.dispatchEvent(new CustomEvent('admin-session:locked'));
    pauseInactivityTracking();
    if (!wasLocked && !previousSessionFocus) previousSessionFocus = document.activeElement;
    ensureSessionLockCard();
    if (!wasLocked) resetSessionPassword();
    sessionGate("locked");
    if (serverSession) scheduleInactivityCheck(Math.max(0, serverSession.expiresAt - Date.now()));
    if (!wasLocked) document.getElementById("session-password")?.focus();
  }

  function broadcastSessionState() {
    try { localStorage.setItem(AUTH_SESSION_STATE_KEY, JSON.stringify({ session: activitySessionId(getAuthSession()), at: Date.now(), nonce: Math.random() })); }
    catch { /* Server checks on resume and polling still enforce the lock. */ }
  }

  function applyServerSession(data, token) {
    if (getAuthSession()?.token !== token || typeof data?.locked !== "boolean") return false;
    if (isAdminRole(getAuthSession()?.role) && data.sidebar_revision) {
      if (data.role !== getUserRole()) { invalidateSession(null, 'permissions_changed'); return true; }
      const cached = getCachedStaffIdentity();
      verifiedSidebarRevision = data.sidebar_revision;
      if (cached && (cached.sidebar_revision !== data.sidebar_revision || cached.user.user_id !== data.user_id)) {
        clearAdminCache();
        clearStaffIdentity(true);
        // Existing page state may contain data from the former permissions.
        // Reload only on verified identity changes, never during normal polling.
        window.location.reload?.();
      }
    }
    const timestamp = Date.now();
    const serverNow = Date.parse(data.server_now);
    // Keep the verified clock offset: later response latency must not move deadlines.
    const clockOffset = serverSession?.token === token ? serverSession.clockOffset : timestamp - serverNow;
    const idleAt = Date.parse(data.idle_at) + clockOffset;
    const expiresAt = Date.parse(data.expires_at) + clockOffset;
    if (!Number.isFinite(serverNow) || !Number.isFinite(idleAt) || !Number.isFinite(expiresAt)) return false;
    serverSession = { token, idleAt, expiresAt, clockOffset };
    if (expiresAt <= timestamp) { expireSessionForInactivity(getAuthSession()); return true; }
    if (isAdminRole(getAuthSession()?.role)) {
      if (data.locked || idleAt <= timestamp) { showSessionLock(); return true; }
      const wasLocked = sessionLocked;
      const wasChecking = sessionChecking;
      sessionLocked = sessionChecking = false;
      if (wasLocked || wasChecking) {
        resetSessionPassword();
        sessionGate(null);
        if (wasLocked) {
          // Discard responses that still carry the state from before recovery.
          sessionRevision += 1;
          clearAdminCache();
        }
        previousSessionFocus?.focus?.();
        previousSessionFocus = null;
      }
      sessionWaiters.splice(0).forEach(({ resolve }) => resolve());
      if (wasLocked && typeof window.dispatchEvent === 'function') {
        window.dispatchEvent(new CustomEvent('admin-session:unlocked'));
      }
    }
    evaluateSessionInactivity(timestamp);
    return true;
  }

  function syncSessionState(force = false) {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session)) return Promise.resolve();
    if (!force && sessionSync?.revision === sessionRevision) return sessionSync.promise;
    const revision = sessionRevision;
    let pending;
    pending = networkRequest("GET", "/session", null, session.token, { cache: "no-store" })
      .then((data) => {
        if (sessionSync?.promise === pending && revision === sessionRevision && !applyServerSession(data, session.token)) throw new Error("Invalid session status.");
      })
      .catch(() => {
        if (sessionSync?.promise === pending && revision === sessionRevision && getAuthSession()?.token === session.token && isAdminRole(session.role)) {
          if (sessionLocked) sessionLockError("Unable to check session. Please try again.");
          else if (sessionChecking) {
            ensureSessionLockCard();
            sessionGate("unavailable");
            document.getElementById("session-retry")?.focus();
          }
        }
      }).finally(() => {
        if (sessionSync?.promise !== pending) return;
        sessionSync = null;
        if (sessionPollTimer) clearTimeout(sessionPollTimer);
        if (getAuthSession()?.token === session.token) sessionPollTimer = setTimeout(() => {
          if (document.visibilityState !== "hidden") void syncSessionState();
        }, 60000);
      });
    sessionSync = { revision, promise: pending, verifyChange: force };
    return pending;
  }

  function reportSessionActivity() {
    if (activityReportTimer) return;
    const token = getAuthSession()?.token;
    const report = () => {
      activityReportTimer = null;
      if (getAuthSession()?.token !== token || sessionLocked || sessionChecking) return;
      lastActivityReportAt = Date.now();
      const revision = sessionRevision;
      void networkRequest("POST", "/session/activity", null, token, { keepalive: true })
        .then((data) => {
          if (revision === sessionRevision) { applyServerSession(data, token); broadcastSessionState(); }
        }).catch(() => { /* Expiration/lock responses use the central handler. */ });
    };
    const delay = Math.max(0, 10000 - (Date.now() - lastActivityReportAt));
    if (delay) activityReportTimer = setTimeout(report, delay);
    else report();
  }

  function startSessionInactivityManager() {
    const session = getAuthSession();
    if (!isManagedProtectedSession(session)) return false;
    if (
      typeof document === "undefined"
      || typeof document.addEventListener !== "function"
      || typeof document.createElement !== "function"
    ) {
      return false;
    }

    inactivityLogoutStarted = false;

    if (!inactivityManagerInstalled) {
      const onActivity = (event) => {
        if (event.isTrusted && document.visibilityState !== "hidden") recordSessionActivity(false);
      };
      document.addEventListener("pointerdown", onActivity, { passive: true });
      document.addEventListener("mousemove", onActivity, { passive: true });
      document.addEventListener("keydown", onActivity);
      document.addEventListener("touchstart", onActivity, { passive: true });
      document.addEventListener("wheel", onActivity, { passive: true, capture: true });
      document.addEventListener("visibilitychange", resumeSession);
      window.addEventListener("focus", resumeSession);
      window.addEventListener("pagehide", () => concealSession());
      inactivityManagerInstalled = true;
    }

    concealSession();
    evaluateSessionInactivity();
    void syncSessionState();
    return true;
  }

  function resumeSession() {
    if (document.visibilityState === "hidden") return;
    evaluateSessionInactivity();
    void syncSessionState();
  }

  if (typeof window !== "undefined") {
    observedSession = getAuthSession();
    window.addEventListener("storage", (event) => {
      if (event.storageArea !== localStorage) return;
      const current = getAuthSession();
      if (current) observedSession = current;
      if (event.key === CUSTOMER_TOKEN_KEY || event.key === CUSTOMER_CACHE_SESSION_KEY || event.key === USER_ROLE_KEY) {
        clearCustomerCache();
      }
      if (event.key === CUSTOMER_PROFILE_CHANGED_KEY && event.newValue && current?.role === 'customer') {
        try {
          const change = JSON.parse(event.newValue);
          if (change.owner === activitySessionId(current) && customerSessionStorage() === localStorage
            && typeof change.first_name === 'string' && typeof change.last_name === 'string') {
            const cached = readCustomerCache('/me')?.data;
            invalidateCustomerCache('profile');
            const user = { ...cached?.user, first_name: change.first_name, last_name: change.last_name };
            if (cached) {
              customerCache.set('/me', { at: Date.now(), data: { ...cached, user } });
              persistCustomerCache();
            }
            rememberCustomerProfileName(user.first_name, user.last_name);
            showCachedCustomerProfileName();
            if (typeof window.dispatchEvent === 'function') window.dispatchEvent(new CustomEvent('customer-profile:updated', { detail: { user } }));
          }
        } catch { /* Ignore malformed cross-tab signals. */ }
      }
      if (event.key === ADMIN_TOKEN_KEY || event.key === USER_ROLE_KEY) {
        const previousIdentityToken = staffIdentityToken;
        clearAdminCache();
        clearStaffIdentity();
        verifiedSidebarRevision = null;
        const token = getAdminToken();
        if (isAdminPage() && previousIdentityToken && token && token !== previousIdentityToken) {
          // Replace workspace state belonging to the account displayed here.
          window.location.reload?.();
        }
      }
      if (event.key === ADMIN_DATA_CHANGED_KEY && event.newValue) {
        try {
          const change = JSON.parse(event.newValue);
          if (Array.isArray(change.groups)) {
            if (change.owner === activitySessionId(getAuthSession())) invalidateAdminCache(change.groups, false);
            if (change.groups.includes('availability')) invalidateCustomerCache('capacity');
          }
        } catch { /* Ignore malformed cross-tab signals. */ }
      }

      if (event.key === AUTH_LAST_ACTIVITY_KEY && event.newValue) {
        // Local hints never unlock or trigger a request per mouse movement.
        // Confirmed activity reports use the separately throttled state signal.
        return;
      }
      if (event.key === AUTH_SESSION_STATE_KEY && event.newValue) {
        evaluateSessionInactivity();
        // A signal may describe a change newer than an already pending check.
        void syncSessionState(true);
        return;
      }

      if (event.key !== AUTH_LOGOUT_EVENT_KEY || !event.newValue) return;

      try {
        const data = JSON.parse(event.newValue);
        handleCrossTabLogout(data);
      } catch {
        // Ignore malformed auth sync events.
      }
    });

    if (enforceProtectedPageAccess()) startSessionInactivityManager();
    window.addEventListener("pageshow", (event) => {
      // A logout page navigation can leave a protected dashboard in the
      // browser back-forward cache. Re-check storage whenever it is restored.
      if (event.persisted && enforceProtectedPageAccess()) startSessionInactivityManager();
    });
  }

  // ── Core request function ─────────────────────────────────────────────────
  // Callers get back the parsed JSON on success.
  // On failure, a structured Error is thrown with:
  //   error.status  — HTTP status code (e.g. 401, 422, 500)
  //   error.message — backend message or generic fallback
  //   error.errors  — Laravel validation errors object (422 only), or null

  function request(method, endpoint, body = null, token = null, requestOptions = {}) {
    if (method === "GET" && token && token === getAdminToken() && isManagedProtectedSession()
      && (sessionLocked || sessionChecking)) {
      const key = `${token}:${endpoint}:${requestOptions.responseType || "json"}`;
      if (pausedSessionReads.has(key)) return pausedSessionReads.get(key);
      const pending = new Promise((resolve, reject) => sessionWaiters.push({ resolve, reject }))
        .then(() => {
          if (getAdminToken() !== token) throw new Error("Session ended.");
          return request(method, endpoint, body, token, requestOptions);
        })
        .finally(() => { if (pausedSessionReads.get(key) === pending) pausedSessionReads.delete(key); });
      pausedSessionReads.set(key, pending);
      return pending;
    }
    if (method === "GET" && getAdminToken() && (token === getAdminToken() || endpoint === "/clinic/status") && adminCachePolicy(endpoint)) {
      return adminRead(endpoint, token, requestOptions);
    }
    const customerRequest = !!token && token === getCustomerToken();
    const policy = method === "GET" && customerRequest ? customerCachePolicy(endpoint) : null;
    if (!policy || !ensureCustomerCacheSession()) {
      return networkRequest(method, endpoint, body, token, requestOptions).then((data) => {
        if (method !== "GET" && customerRequest && token === getCustomerToken()) invalidateCustomerMutation(endpoint);
        if (method !== "GET" && token && token === getAdminToken() && /^\/(admin|settings|inventory|pos)\//.test(endpoint)) {
          invalidateAdminMutation(endpoint);
        }
        return data;
      });
    }
    const cached = readCustomerCache(endpoint);
    if (customerRequests.has(endpoint)) return customerRequests.get(endpoint);
    if (!requestOptions.force && cached?.fresh) return Promise.resolve(cached.data);
    const revision = customerCacheRevisions.get(policy.group) || 0;
    const owner = customerCacheOwner;
    const epoch = customerCacheEpoch;
    let pending;
    pending = networkRequest(method, endpoint, body, token, requestOptions).then((data) => {
      if (token !== getCustomerToken() || owner !== customerCacheOwner || epoch !== customerCacheEpoch
        || revision !== (customerCacheRevisions.get(policy.group) || 0)) {
        throw new Error("Customer data changed while loading. Please try again.");
      }
      customerCache.set(endpoint, { at: Date.now(), data });
      persistCustomerCache();
      return data;
    }).finally(() => {
      if (customerRequests.get(endpoint) === pending) customerRequests.delete(endpoint);
    });
    customerRequests.set(endpoint, pending);
    return pending;
  }

  async function networkRequest(method, endpoint, body = null, token = null, requestOptions = {}) {
    const sessionControl = endpoint === "/session" || endpoint.startsWith("/session/") || endpoint === "/logout";
    if (!sessionControl && token && token === getAdminToken() && isManagedProtectedSession()
      && (sessionLocked || sessionChecking || (sessionSync?.verifyChange && sessionSync.revision === sessionRevision && method !== "GET"))) {
      if (method !== "GET") {
        const error = new Error(sessionLocked ? "Unlock your session to continue." : "Verify your session before continuing.");
        error.status = sessionLocked ? 423 : 503;
        error.code = sessionLocked ? "session_locked" : "session_verification_unavailable";
        throw error;
      }
      await new Promise((resolve, reject) => sessionWaiters.push({ resolve, reject }));
      if (getAuthSession()?.token !== token) throw new Error("Session ended.");
    }
    const requestRevision = sessionRevision;
    const isFormData = typeof FormData !== "undefined" && body instanceof FormData;
    const headers = {
      Accept: "application/json",
      "X-Requested-With": "XMLHttpRequest",
    };

    if (!isFormData) {
      headers["Content-Type"] = "application/json";
    }

    if (token) {
      headers["Authorization"] = `Bearer ${legacyCredentials.get(token) || token}`;
      if (legacyCredentials.has(token)) headers['X-Session-Persistent'] = getAuthSession()?.persistent ? '1' : '0';
    }

    const options = { method, headers, credentials: "include" };
    if (requestOptions.cache) options.cache = requestOptions.cache;

    if (body !== null) {
      options.body = isFormData ? body : JSON.stringify(body);
    }

    let response;
    const controller = typeof AbortController !== "undefined"
      ? new AbortController()
      : null;
    const timeoutId = controller
      ? setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
      : null;

    if (controller) {
      options.signal = controller.signal;
    }
    if (requestOptions.keepalive) {
      options.keepalive = true;
    }

    try {
      response = await fetch(`${BASE_URL}${endpoint}`, options);
    } catch (error) {
      // Network failure (server down, no internet, CORS preflight killed)
      const networkError = new Error(
        error?.name === "AbortError"
          ? "The server is taking too long to respond. Please try again."
          : "Connection lost. Please try again."
      );
      networkError.status = 0;
      networkError.errors = null;
      throw networkError;
    } finally {
      if (timeoutId) clearTimeout(timeoutId);
    }

    const expectsBlob = requestOptions.responseType === "blob";
    const data = response.ok && expectsBlob
      ? null
      : await response.json().catch(() => ({}));

    // A response for an older in-flight request must never erase a newer
    // login that replaced its bearer token while the request was pending.
    const currentSession = getAuthSession();
    const responseBelongsToCurrentSession = !!token
      && currentSession?.token === token;
    if (response.ok && endpoint === '/session') legacyCredentials.delete(token);
    // Creation can commit a stock hold before returning an uncertain HTTP 502;
    // provider-confirmed expiry can release it during a GET status check.
    if (responseBelongsToCurrentSession && token === getAdminToken() && data.online_payment?.id
      && /^(?:\/pos\/online-payment|\/admin\/bookings\/[^/]+\/online-payment)(?:\/\d+)?(?:\?|$)/.test(endpoint)
      && ((method === 'POST' && data.online_payment.status === 'pending')
        || (method === 'GET' && ['expired', 'cancelled', 'failed'].includes(data.online_payment.status)))) {
      invalidateAdminCache(['inventory']);
    }
    if (response.ok && responseBelongsToCurrentSession && token === getAdminToken() && method === "GET"
      && /^\/pos\/online-payment(?:\/\d+)?(?:\?|$)/.test(endpoint)
      && data.online_payment?.status === 'paid' && data.receipt) invalidateAdminCache(['inventory']);
    if (response.ok && responseBelongsToCurrentSession && token === getAdminToken() && method === "GET"
      && /^\/admin\/(bookings|clinic-appointments)\/[^/]+\/online-payment(?:\?|$)/.test(endpoint)
      && (data.online_payment?.status === "paid" || data.appointment?.paid || data.booking?.paid || data.booking_status === "released")) {
      invalidateAdminMutation(endpoint.replace(/\/online-payment(?=\?|$)/, '/pay'));
    }
    if (response.status === 423 && responseBelongsToCurrentSession && data.code === "session_locked") {
      if (requestRevision !== sessionRevision && method === "GET" && !sessionControl) {
        return networkRequest(method, endpoint, body, token, requestOptions);
      }
      if (requestRevision === sessionRevision) showSessionLock();
      if (method === "GET" && !sessionControl) {
        await new Promise((resolve, reject) => sessionWaiters.push({ resolve, reject }));
        if (getAuthSession()?.token !== token) throw new Error("Session ended.");
        return networkRequest(method, endpoint, body, token, requestOptions);
      }
    }
    const invalidAccountSession = response.status === 403
      && responseBelongsToCurrentSession
      && INVALID_SESSION_CODES.has(data.code);
    const lostAuthenticatedSession = response.status === 401
      && responseBelongsToCurrentSession;

    // Revocation can reach a background read before the final email-change
    // response. Let that response settle before deciding whether to redirect.
    const completingEmail = emailChangeSignOut;
    if ((lostAuthenticatedSession || invalidAccountSession) && completingEmail?.token === token
      && endpoint !== completingEmail.endpoint) await completingEmail.settled;

    if (
      (lostAuthenticatedSession || invalidAccountSession)
      && getAuthSession()?.token === token
      && !requestOptions.suppressAuthRedirect
    ) {
      // Public sign-in/register failures have no bearer token and therefore
      // fall through for their forms to render. Only a real authenticated
      // session failure clears state and navigates away.
      const failedRole = token === rawAdminToken() ? "admin" : "customer";
      invalidateSession(
        failedRole,
        invalidAccountSession ? data.code : data.code === 'session_replaced' ? 'session_replaced' : "expired",
      );
    }

    if (response.status === 429) {
      const error = new Error(data.message || "Too many attempts. Please wait before trying again.");
      error.status = 429;
      error.code = data.code || null;
      error.data = data;
      const retryHeader = response.headers.get('Retry-After');
      const seconds = value => {
        if (!['number', 'string'].includes(typeof value) || String(value).trim() === '') return null;
        const parsed = Number(value);
        return Number.isFinite(parsed) && parsed >= 0 ? Math.ceil(parsed) : null;
      };
      // HTTP-date durations use the server's Date, never the device clock.
      const serverDate = Date.parse(response.headers.get('Date'));
      const retryDate = retryHeader && !/^[\d.+-]+$/.test(retryHeader.trim()) ? Date.parse(retryHeader) : NaN;
      error.retryAfter = seconds(retryHeader)
        ?? (Number.isFinite(serverDate) && Number.isFinite(retryDate) ? Math.max(0, Math.ceil((retryDate - serverDate) / 1000)) : null)
        ?? seconds(data.retry_after);
      error.errors = null;
      throw error;
    }

    if (!response.ok) {
      if (
        response.status === 403
        && !invalidAccountSession
        && token === getAdminToken()
        && isAdminOnlyPage()
      ) {
        redirectToAdminDashboard();
      }

      const authenticationEndpoint = /^\/(?:register|sign-in|email\/|password\/)/.test(endpoint);
      const internal = (authenticationEndpoint && response.status >= 500) || data.exception
        || /Trying to|array offset|SQLSTATE|Stack trace|Undefined (?:variable|array)|PDOException|Illuminate\\|Symfony\\/i.test(data.message || "");
      const error = new Error(internal ? (endpoint.startsWith("/register")
        ? "Couldn't create your account. Please try again." : "Something went wrong. Please try again.")
        : data.message || "Something went wrong. Please try again.");
      error.status = response.status;
      error.errors = internal ? null : data.errors || null;
      error.emailNotVerified = data.email_not_verified ?? false;
      error.email = data.email ?? null;
      error.expired = data.expired ?? false;
      error.code = data.code || null;
      error.data = data;
      throw error;
    }

    if (expectsBlob) {
      const contentDisposition = response.headers.get("Content-Disposition") || "";
      const encodedFileName = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const quotedFileName = contentDisposition.match(/filename="([^"]+)"/i)?.[1];
      const plainFileName = contentDisposition.match(/filename=([^;]+)/i)?.[1]?.trim();
      let fileName = encodedFileName || quotedFileName || plainFileName || null;

      if (fileName) {
        try {
          fileName = decodeURIComponent(fileName.replace(/^"|"$/g, ""));
        } catch {
          fileName = fileName.replace(/^"|"$/g, "");
        }
      }

      return {
        blob: await response.blob(),
        fileName,
        contentType: response.headers.get("Content-Type") || "application/octet-stream",
      };
    }

    return data;
  }

  function adminRequest(method, endpoint, body = null, requestOptions = {}) {
    return request(method, endpoint, body, getAdminToken(), requestOptions);
  }

  function isAuthenticationError(error) {
    return error?.status === 401
      || (error?.status === 403 && INVALID_SESSION_CODES.has(error?.code));
  }

  // ── Auth API calls ────────────────────────────────────────────────────────

  async function register(payload) {
    // POST /api/register
    // payload: { first_name, last_name, username?, email, phone, password, password_confirmation }
    // Registration establishes a session only after deliberate magic-link verification.
    const data = await request("POST", "/register", {...payload, registration_token: pendingRegistrationToken(payload.email) || undefined});
    if (data.registration_token) storeEmailDelivery("registration", data.email_delivery_token);
    return data;
  }

  async function signIn(identifier, password, remember = true) {
    // POST /api/sign-in
    // Accepts email, local/international mobile phone, or username. Saves to the
    // correct token key based on role. remember=false uses sessionStorage so
    // the session dies when the tab closes.
    const normalized = normalizePhoneLikeIdentifier(identifier);
    const resolvedIdentifier = normalized || identifier;
    const revision = sessionRevision, previousToken = getAuthSession()?.token;
    const data = await request("POST", "/sign-in", {
      identifier: resolvedIdentifier,
      password,
      remember,
    });

    if (revision !== sessionRevision || previousToken !== getAuthSession()?.token) {
      throw new Error('Sign-in changed while loading. Please try again.');
    }

    if (data?.requires_login_confirmation) {
      storePendingLoginConfirmation(data?.login_poll_token, data?.email);
      try {
        sessionStorage.setItem("pendingLoginResendUntil", String(Date.now() + (data.resend_after ?? 60) * 1000));
        sessionStorage.setItem("pendingLoginResendLimited", data.resend_limited ? "1" : "0");
      } catch { /* Server-side cooldown remains authoritative. */ }
      storeEmailDelivery("login", data.email_delivery_token);
      return data;
    }

    const role = data?.user?.role;
    if (role === "customer") clearBookingDraft();
    setAuthSession(data?.token, role, isAdminRole(role) ? true : remember);
    if (role === "customer") rememberCustomerProfileName(data.user.first_name, data.user.last_name);
    return data;
  }

  async function requestPasswordReset(email) {
    return request("POST", "/password/forgot", { email });
  }

  async function verifyPasswordResetCode(email, code) {
    return request("POST", "/password/code/verify", { email, code });
  }

  async function resetPassword(token, password, passwordConfirmation) {
    const data = await request("POST", "/password/reset", {
      token,
      password,
      password_confirmation: passwordConfirmation,
    });

    return data;
  }

  async function verifyStaffPasswordSetupToken(token) {
    return request("POST", "/staff/password-setup/verify", { token });
  }

  async function completeStaffPasswordSetup(token, password, passwordConfirmation, username = null) {
    const data = await request("POST", "/staff/password-setup/complete", {
      token,
      username,
      password,
      password_confirmation: passwordConfirmation,
    });

    if (data?.completed_setup && data?.token && data?.user?.role === "staff") {
      setAuthSession(data.token, "staff", true);
    }

    return data;
  }

  async function requestNewStaffPasswordSetupLink(token) {
    return request("POST", "/staff/password-setup/request-new-link", { token });
  }

  async function updatePendingRegistration(payload, recoveryToken) {
    const data = await request("PUT", "/register/pending", { ...payload, registration_recovery_token: recoveryToken });
    storeEmailDelivery("registration", data.email_delivery_token);
    acceptRegistrationSession(data);
    return data;
  }

  async function requestRegistrationReview(email) {
    return request("POST", "/email/registration/review", { email });
  }

  async function verifyEmail(token, review = false) {
    const data = await request("POST", "/email/verify", { token, review });
    acceptRegistrationSession(data);
    return data;
  }

  const registrationSessionRequests = new Map();
  function registrationSession(email = "", acknowledge = false) {
    const key = JSON.stringify([email.toLowerCase(), acknowledge, sessionRevision]);
    if (registrationSessionRequests.has(key)) return registrationSessionRequests.get(key);
    const pending = checkRegistrationSession(email, acknowledge).finally(() => registrationSessionRequests.delete(key));
    registrationSessionRequests.set(key, pending);
    return pending;
  }

  async function checkRegistrationSession(email, acknowledge) {
    const revision = sessionRevision, previousToken = getAuthSession()?.token;
    const data = await request("POST", "/email/registration/session", { email, acknowledge: false }, null, {cache: "no-store"});
    if (revision !== sessionRevision || previousToken !== getAuthSession()?.token) {
      throw new Error("Registration session changed. Please try again.");
    }
    if (email && data?.user?.email?.toLowerCase() !== email.toLowerCase()) return null;
    if (!data?.session_token || data?.user?.role !== "customer") return null;
    acceptRegistrationSession({ ...data, token: data.session_token });
    // Announce readiness only after this tab has accepted the server session.
    if (acknowledge) await request("POST", "/email/registration/session", {email, acknowledge: true}, null, {cache: "no-store"});
    return data;
  }

  function acceptRegistrationSession(data) {
    if (data?.token && data?.user?.role === "customer") {
      if (getCustomerToken() === data.token) return;
      // Presentation-only session cookie: shared across tabs, never used for auth.
      // Leave it through sign-out/sign-in so the initial browser session stays new.
      if (data.user.user_id != null) {
        document.cookie = `bethlehem_customer_initial_session_${encodeURIComponent(data.user.user_id)}=1; Path=/; SameSite=Lax`;
      }
      clearBookingDraft();
      setAuthSession(data.token, "customer", true);
      rememberCustomerProfileName(data.user.first_name, data.user.last_name);
    }
  }

  async function adminGetPetProfile(petId, history = null, page = 1, view = null) {
    const query = history ? `?history=${encodeURIComponent(history)}&page=${page}${view ? '&view=' + encodeURIComponent(view) : ''}` : '';
    return request("GET", `/admin/pets/${encodeURIComponent(petId)}/profile${query}`, null, getAdminToken());
  }

  function adminGetPetCaseDetails(petId, caseId, view = null) {
    return request("GET", `/admin/pets/${encodeURIComponent(petId)}/cases/${encodeURIComponent(caseId)}/details${view ? '?view=' + encodeURIComponent(view) : ''}`, null, getAdminToken());
  }

  async function resendVerification(email) {
    // POST /api/email/resend  { email }
    const data = await request("POST", "/email/resend", {
      email,
      registration_token: pendingRegistrationToken(email) || undefined,
    });
    storeEmailDelivery("registration", data.email_delivery_token);
    return data;
  }

  function pendingRegistrationToken(email) {
    try {
      const details = JSON.parse(safeStorageGet(sessionStorage, "pendingSignupDetails") || "null");
      return details?.email?.toLowerCase() === email?.toLowerCase()
        ? safeStorageGet(sessionStorage, "pendingRegistrationEditToken") || "" : "";
    } catch { return ""; }
  }

  function storeEmailDelivery(kind, receipt) {
    const key = `pending_${kind}_email_delivery`;
    safeStorageRemove(sessionStorage, key);
    if (receipt) {
      try { sessionStorage.setItem(key, receipt); } catch { /* Verification remains available. */ }
    }
  }

  function getEmailDeliveryToken(kind) {
    return safeStorageGet(sessionStorage, `pending_${kind}_email_delivery`) || "";
  }

  function emailDeliveryStatus(receipt) {
    return request("POST", "/email/delivery-status", { delivery_token: receipt });
  }

  async function resendLoginCode() {
    const pollToken = safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY);
    if (!pollToken) {
      throw new Error("This browser no longer has a pending sign-in. Please sign in again.");
    }
    const data = await request("POST", "/email/login/resend", { poll_token: pollToken });
    storeEmailDelivery("login", data.email_delivery_token);
    return data;
  }

  async function confirmLoginCode(code) {
    const pollToken = safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY);
    const revision = sessionRevision, previousToken = getAuthSession()?.token;
    if (!pollToken) {
      throw new Error("This browser no longer has a pending sign-in. Please sign in again.");
    }

    try {
      const data = await request("POST", "/email/login/confirm", {
        poll_token: pollToken,
        code,
      });

      if (revision !== sessionRevision || previousToken !== getAuthSession()?.token
        || pollToken !== safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY)) {
        throw new Error('Sign-in changed while confirming. Please try again.');
      }

      const role = data?.user?.role;
      if (!data?.token || !["customer", "admin", "staff"].includes(role)) {
        throw new Error("The server returned an invalid session.");
      }

      if (role === "customer") clearBookingDraft();
      setAuthSession(
        data.token,
        role,
        isAdminRole(role) ? true : data?.remember_me !== false,
      );
      if (role === "customer") rememberCustomerProfileName(data.user.first_name, data.user.last_name);

      // Acknowledge only after the browser has stored the session. Failure is
      // non-fatal: the code was already consumed atomically by the server.
      try {
        await request(
          "POST",
          "/email/login/complete",
          { poll_token: pollToken },
          data.token,
          { suppressAuthRedirect: true },
        );
      } catch {
        // The authenticated session is already safely stored.
      }

      return data;
    } catch (error) {
      if (pollToken === safeStorageGet(sessionStorage, LOGIN_POLL_TOKEN_KEY)
        && (error?.expired || error?.status === 403 || error?.status === 429)) {
        clearPendingLoginConfirmation();
      }
      throw error;
    }
  }

  async function logout(role = null, logoutOptions = {}) {
    // POST /api/logout  (protected — sends the correct token in the header)
    // Capture the current bearer token, then clear the browser before waiting
    // for the network. Logout remains immediate even if the server is down.
    const currentSession = getAuthSession();
    const resolvedRole = currentSession?.role ?? role ?? "customer";
    const token = currentSession?.token
      ?? (isAdminRole(role) ? rawAdminToken() : rawCustomerToken());

    const revoke = () => request("POST", "/logout", null, token, {
      suppressAuthRedirect: true, keepalive: logoutOptions.keepalive === true,
    });
    if (logoutOptions.requireRevocation && token) {
      try { await revoke(); }
      catch (error) { if (error?.status !== 401) throw error; }
      if (getAuthSession()?.token !== currentSession?.token) return { success: true };
    }

    clearAuthStorage();
    if (resolvedRole === "customer") clearBookingDraft();
    notifyLogout(resolvedRole, logoutOptions.reason || "logout", currentSession);

    if (logoutOptions.requireRevocation) return { success: true };

    if (!token) return { success: true, local_only: true };

    try {
      return await revoke();
    } catch (error) {
      // A missing/expired server session is already equivalent to logout.
      if (error?.status === 401) {
        return { success: true, local_only: true };
      }
      throw error;
    }
  }

  async function getMe(role = "customer", options = {}) {
    // GET /api/me  (protected)
    const token =
      isAdminRole(role) ? getAdminToken() : getCustomerToken();
    const identityEpoch = staffIdentityEpoch;
    const response = await request("GET", "/me", null, token, options);
    if (isAdminRole(role) && token === getAdminToken() && identityEpoch === staffIdentityEpoch) rememberStaffIdentity(response, token);
    if (!isAdminRole(role) && response?.user) {
      rememberCustomerProfileName(response.user.first_name, response.user.last_name);
    }
    return response;
  }

  async function updateCustomerName(payload) {
    const token = getCustomerToken();
    const response = await request("PATCH", "/customer/profile/name", payload, token);
    if (token !== getCustomerToken()) throw new Error("Session changed while saving your name.");
    invalidateCustomerCache("profile");
    if (response?.user && ensureCustomerCacheSession()) {
      customerCache.set("/me", { at: Date.now(), data: response });
      persistCustomerCache();
      rememberCustomerProfileName(response.user.first_name, response.user.last_name);
      showCachedCustomerProfileName();
      if (customerSessionStorage() === localStorage) {
        try {
          localStorage.setItem(CUSTOMER_PROFILE_CHANGED_KEY, JSON.stringify({ owner: activitySessionId(getAuthSession()),
            first_name: response.user.first_name, last_name: response.user.last_name, at: Date.now() }));
        } catch { /* Profile synchronization in this tab still works without storage. */ }
      }
    }
    return response;
  }

  function sendPhoneVerification(phone, currentPassword = null) {
    return request("POST", "/phone-verification/send", { phone, current_password: currentPassword }, getCustomerToken());
  }

  function getPhoneVerificationState() {
    return request("GET", "/phone-verification/state", null, getCustomerToken(), { force: true });
  }

  function cancelPhoneVerification(challengeId) {
    return request("POST", "/phone-verification/cancel", { challenge_id: challengeId }, getCustomerToken());
  }

  function verifyPhoneVerification(challengeId, code) {
    return request("POST", "/phone-verification/verify", { challenge_id: challengeId, code }, getCustomerToken());
  }

  function resendPhoneVerification(challengeId) {
    return request("POST", "/phone-verification/resend", { challenge_id: challengeId }, getCustomerToken());
  }

  function getStaffIdentity() {
    const cached = getCachedStaffIdentity();
    if (cached) return Promise.resolve(cached);
    if (!staffIdentityRequest) {
      const token = getAdminToken(), epoch = staffIdentityEpoch;
      if (!token) return Promise.reject(new Error('Session ended.'));
      const pending = getMe(getUserRole()).then(response => {
        if (token !== getAdminToken() || epoch !== staffIdentityEpoch) throw new Error('Session changed while loading identity.');
        if (verifiedSidebarRevision && response.sidebar_revision !== verifiedSidebarRevision) throw new Error('Permissions changed while loading identity. Please retry.');
        if (response?.user?.role !== getUserRole()) {
          invalidateSession(null, 'permissions_changed');
          throw new Error('Permissions changed. Please sign in again.');
        }
        return response;
      }).finally(() => {
        if (staffIdentityRequest === pending) staffIdentityRequest = null;
      });
      staffIdentityRequest = pending;
    }
    return staffIdentityRequest;
  }

  // Lets every client page show the sidebar name on first paint instead of
  // "Loading..." while GET /me is in flight. It lives in the same storage as the
  // customer token, so clearAuthStorage() (and closing a session-only login)
  // removes it together with the session.
  function customerSessionStorage() {
    return safeStorageGet(localStorage, CUSTOMER_TOKEN_KEY) ? localStorage : sessionStorage;
  }

  function rememberCustomerProfileName(firstName, lastName) {
    try {
      customerSessionStorage().setItem(
        CUSTOMER_PROFILE_NAME_KEY,
        JSON.stringify({ first: firstName || "", last: lastName || "" }),
      );
    } catch {
      // Storage may be unavailable; the page falls back to its placeholder.
    }
  }

  function showCachedCustomerProfileName() {
    const nameEl = document.getElementById("clientProfileName");
    const initialsEl = document.getElementById("clientProfileInitials");
    if (!nameEl || !getCustomerToken()) return;

    let stored;
    try {
      stored = JSON.parse(safeStorageGet(customerSessionStorage(), CUSTOMER_PROFILE_NAME_KEY) || "null");
    } catch {
      return;
    }
    const first = String(stored?.first || "");
    const last = String(stored?.last || "");
    const fullName = `${first} ${last}`.trim();
    if (!fullName) return;

    nameEl.textContent = fullName;
    if (initialsEl) {
      initialsEl.textContent = ((first[0] || "") + (last[0] || "")).toUpperCase() || "--";
    }
  }

  // Apply as soon as api.js runs. Waiting for DOMContentLoaded would also wait
  // for every deferred/module script, which is what left "Loading..." on screen.
  if (typeof document !== "undefined") {
    showCachedCustomerProfileName();
    if (!document.getElementById("clientProfileName")) {
      document.addEventListener("DOMContentLoaded", showCachedCustomerProfileName);
    }
  }

  async function getTimeslots(date) {
    return request("GET", `/timeslots?date=${encodeURIComponent(date)}`);
  }


  async function getClinicTimeslots(date) {
    // GET /api/clinic/timeslots?date=YYYY-MM-DD (public)
    return request("GET", `/clinic/timeslots?date=${encodeURIComponent(date)}`);
  }

  async function getUserPets({ archived = 0, force = false } = {}) {
    // GET /api/pets?archived=0|1  (protected)
    // archived=0 → active pets (booking form default)
    // archived=1 → archived pets (My Pets page toggle)
    return request("GET", `/pets?archived=${archived}`, null, getCustomerToken(), { force });
  }

  async function getPet(petId) {
    // GET /api/pets/{id}  (protected and owner-scoped)
    return request("GET", `/pets/${petId}`, null, getCustomerToken());
  }

  async function getPetMedicalRecords(petId) {
    // GET /api/pets/{petId}/medical-records  (protected and owner-scoped)
    return request("GET", `/pets/${petId}/medical-records`, null, getCustomerToken());
  }

  async function getPetVaccinations(petId) {
    // GET /api/pets/{petId}/vaccinations  (protected, owner-scoped, and client-safe)
    return request("GET", `/pets/${petId}/vaccinations`, null, getCustomerToken());
  }

  async function getCustomerClinicHistory() {
    return request("GET", "/clinic/history", null, getCustomerToken());
  }

  async function addPet(payload) {
    // POST /api/pets  (protected)
    return request("POST", "/pets", payload, getCustomerToken());
  }

  async function updatePet(petId, payload) {
    // PUT /api/pets/{id}  (protected)
    return request("PUT", `/pets/${petId}`, payload, getCustomerToken());
  }

  async function adminUpdatePet(petId, payload) {
    // PUT /api/admin/pets/{id}  (admin/staff — updates any customer's pet)
    return request("PUT", `/admin/pets/${petId}`, payload, getAdminToken());
  }

  async function adminAddCustomerPet(ownerType, ownerId, payload) {
    return request(
      "POST",
      `/admin/customer-pets/${encodeURIComponent(ownerType)}/${encodeURIComponent(ownerId)}`,
      payload,
      getAdminToken(),
    );
  }

  async function archivePet(petId) {
    // POST /api/pets/{id}/archive  (protected)
    return request("POST", `/pets/${petId}/archive`, null, getCustomerToken());
  }

  async function unarchivePet(petId) {
    // POST /api/pets/{id}/unarchive  (protected)
    return request("POST", `/pets/${petId}/unarchive`, null, getCustomerToken());
  }

  async function storeBooking(payload) {
    // POST /api/booking/store  (protected)
    return request("POST", "/booking/store", payload, getCustomerToken());
  }

  async function getPreRegistrationAccess(options = {}) {
    // GET /api/pre-registration/access (protected)
    return request(
      "GET",
      "/pre-registration/access",
      null,
      getCustomerToken(),
      options,
    );
  }

  async function getBookingHistory({ historyLimit = null, petId = null, force = false } = {}) {
    // GET /api/booking/history  (protected)
    const params = new URLSearchParams();
    if (historyLimit !== null) {
      params.set("history_limit", String(Math.max(0, Number(historyLimit) || 0)));
    }
    if (petId !== null) {
      params.set("pet_id", String(petId));
    }
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/booking/history${query}`, null, getCustomerToken(), { force });
  }

  async function getGroomingCapacity(options = {}) {
    // GET /api/booking/grooming-capacity  (protected)
    return request("GET", "/booking/grooming-capacity", null, getCustomerToken(), options);
  }

  async function getBooking(bookingId) {
    return request("GET", `/booking/${encodeURIComponent(bookingId)}`, null, getCustomerToken(), { force: true });
  }

  async function getClinicRegistration(id) {
    return request("GET", `/clinic/registrations/${encodeURIComponent(id)}`, null, getCustomerToken(), { force: true });
  }

  async function cancelBooking(bookingId, reason = null) {
    // POST /api/booking/cancel  (protected)
    return request("POST", "/booking/cancel", { booking_id: bookingId, reason }, getCustomerToken());
  }

  async function rescheduleBooking(bookingId, newDate, newWindowId) {
    // POST /api/booking/reschedule  (protected)
    return request("POST", "/booking/reschedule", {
      booking_id:    bookingId,
      new_date:      newDate,
      new_window_id: newWindowId,
    }, getCustomerToken());
  }

  async function submitClinicPreRegistration(payload) {
    // POST /api/clinic/pre-register (protected — customer token)
    // payload: { appointment_date, window_id, pet_id, common_concerns, chief_complaint? }
    return request(
      "POST",
      "/clinic/pre-register",
      payload,
      getCustomerToken(),
    );
  }

  // ── Admin ─────────────────────────────────────────────────────────────────

  async function getAdminBookings(date = null, { includeFuture = false, boardOnly = false, force = false } = {}) {
    // GET /api/admin/bookings?date=YYYY-MM-DD&include_future=1
    const params = new URLSearchParams();
    if (date) params.set("date", date);
    if (includeFuture) params.set("include_future", "1");
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/bookings${query}${boardOnly ? `${query ? "&" : "?"}view=board` : ""}`, null, getAdminToken(), { force });
  }

  function getAdminDashboardSummary({ force = false } = {}) {
    return request("GET", "/admin/dashboard/summary", null, getAdminToken(), { force });
  }
  function getAdminDashboardActivity({ force = false, area = '' } = {}) {
    const query = area ? `?area=${encodeURIComponent(area)}` : '';
    return request("GET", `/admin/dashboard/activity${query}`, null, getAdminToken(), { force });
  }
  function getAdminActivityLog(params = {}) {
    const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== '' && value != null));
    return request('GET', `/admin/activity-log?${query}`, null, getAdminToken());
  }
  function getAdminActivityDetails(id) {
    return request('GET', `/admin/activity-log/${encodeURIComponent(id)}`, null, getAdminToken());
  }
  function getAdminActivityOptions() {
    return request('GET', '/admin/activity-log/options', null, getAdminToken());
  }

  function getAdminGroomingActivity({ force = false } = {}) {
    return request("GET", "/admin/grooming/activity", null, getAdminToken(), { force });
  }

  async function adminCheckIn(bookingId, petSizes = [], internalStaffNote = null) {
    // POST /api/admin/bookings/{id}/check-in  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/check-in`, { pet_sizes: petSizes, internal_staff_note: internalStaffNote }, getAdminToken());
  }

  async function adminUpdateInternalStaffNote(bookingId, note) {
    return request("PATCH", `/admin/bookings/${bookingId}/internal-staff-note`, { internal_staff_note: note }, getAdminToken());
  }

  async function adminUpdateGroomingVisitNotes(bookingId, bookingPetId, note) {
    return request("PATCH", `/admin/bookings/${bookingId}/pets/${bookingPetId}/grooming-visit-notes`, { grooming_visit_notes: note }, getAdminToken());
  }

  async function adminRecordSedationConsent(bookingId) {
    return request(
      "POST",
      `/admin/bookings/${bookingId}/sedation-consent`,
      { customer_understood_and_agreed: true },
      getAdminToken(),
    );
  }

  async function adminRevertCheckIn(bookingId) {
    return request(
      "POST",
      `/admin/bookings/${bookingId}/revert-check-in`,
      null,
      getAdminToken(),
    );
  }

  async function adminStartGrooming(bookingId) {
    // POST /api/admin/bookings/{id}/start-grooming  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/start-grooming`, null, getAdminToken());
  }

  async function adminUndoPetStart(bookingId, bookingPetId) {
    return request("POST", `/admin/bookings/${bookingId}/pets/${bookingPetId}/undo-start`, null, getAdminToken());
  }

  async function adminCancelPet(bookingId, bookingPetId, cancellationReason) {
    return request("POST", `/admin/bookings/${bookingId}/pets/${bookingPetId}/cancel`,
      { cancellation_reason: String(cancellationReason ?? "").trim() }, getAdminToken());
  }

  async function adminStartPetGrooming(bookingId, bookingPetId, assessment = {}) {
    // Starts grooming for one pet while preserving the rest of the owner's queue card.
    return request(
      "POST",
      `/admin/bookings/${bookingId}/pets/${bookingPetId}/start-grooming`,
      assessment,
      getAdminToken(),
    );
  }

  async function adminMarkDone(bookingId) {
    // POST /api/admin/bookings/{id}/mark-done  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/mark-done`, null, getAdminToken());
  }

  async function adminMarkPetDone(bookingId, bookingPetId) {
    // Finishes one pet while the owner booking remains in progress until all pets are done.
    return request(
      "POST",
      `/admin/bookings/${bookingId}/pets/${bookingPetId}/mark-done`,
      null,
      getAdminToken(),
    );
  }

  async function adminCancelBooking(bookingId, cancellationReason = "") {
    // POST /api/admin/bookings/{id}/cancel  (protected — admin token)
    const reason = String(cancellationReason ?? "").trim();

    return request(
      "POST",
      `/admin/bookings/${bookingId}/cancel`,
      { cancellation_reason: reason || null },
      getAdminToken(),
    );
  }

  async function getNotifications(filters = {}) {
    // GET /api/admin/notifications  (protected — admin token)
    const params = new URLSearchParams();

    if (filters.status) params.set("status", filters.status);
    if (filters.category) params.set("category", filters.category);
    if (filters.service) params.set("service", filters.service);
    if (filters.activity) params.set("activity", filters.activity);
    if (filters.mode) params.set("mode", filters.mode);
    if (filters.page) params.set("page", String(filters.page));
    if (filters.per_page) params.set("per_page", String(filters.per_page));

    if (filters.selected_id) params.set("selected_id", String(filters.selected_id));

    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/notifications${query}`, null, getAdminToken());
  }

  async function markNotificationRead(notificationId) {
    // PATCH /api/admin/notifications/{id}/read  (protected — admin token)
    return request("PATCH", `/admin/notifications/${notificationId}/read`, null, getAdminToken());
  }

  // ── Customer management (admin only) ─────────────────────────────────────

  const phoneSearch = value => window.PhilippineMobile?.searchQuery(value) ?? String(value).trim();

  async function searchAdminDashboard(search = "") {
    const params = new URLSearchParams({ q: phoneSearch(search) });
    return request("GET", `/admin/dashboard/search?${params.toString()}`, null, getAdminToken());
  }

  async function getCustomers({ status = "active", tier = "", search = "" } = {}, { force = false } = {}) {
    // GET /api/admin/customers  (protected — admin token)
    const params = new URLSearchParams();
    if (status) params.set("status", status);
    if (tier)   params.set("tier",   tier);
    if (search) params.set("search", phoneSearch(search));
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/customers${query}`, null, getAdminToken(), { force });
  }

  async function getCustomerDetails(customerId) {
    // GET /api/admin/customers/{id}  (protected — admin token)
    return request("GET", `/admin/customers/${customerId}`, null, getAdminToken());
  }

  async function createUnregisteredCustomer(payload) {
    return request("POST", "/admin/customers/unregistered", payload, getAdminToken());
  }

  async function getUnregisteredCustomerDetails(customerId) {
    return request("GET", `/admin/customers/unregistered/${customerId}`, null, getAdminToken());
  }

  async function archiveUnregisteredCustomer(customerId) {
    return request("POST", `/admin/customers/unregistered/${customerId}/archive`, null, getAdminToken());
  }

  async function restoreUnregisteredCustomer(customerId) {
    return request("POST", `/admin/customers/unregistered/${customerId}/restore`, null, getAdminToken());
  }

  async function searchWalkInCustomers(search = "") {
    const params = new URLSearchParams({ q: phoneSearch(search) });
    return request("GET", `/admin/walk-in/customers?${params.toString()}`, null, getAdminToken());
  }

  async function validateWalkInNewOwner(payload) {
    return request(
      "POST",
      "/admin/walk-in/customers/validate-new-owner",
      payload,
      getAdminToken(),
    );
  }

  async function deactivateCustomer(customerId) {
    // POST /api/admin/customers/{id}/deactivate  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/deactivate`, null, getAdminToken());
  }

  async function reactivateCustomer(customerId) {
    // POST /api/admin/customers/{id}/reactivate  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/reactivate`, null, getAdminToken());
  }

  async function archiveCustomer(customerId) {
    // POST /api/admin/customers/{id}/archive  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/archive`, null, getAdminToken());
  }

  async function unarchiveCustomer(customerId) {
    // POST /api/admin/customers/{id}/unarchive  (protected — admin token)
    return request("POST", `/admin/customers/${customerId}/unarchive`, null, getAdminToken());
  }

  async function adminArchiveBooking(bookingId) {
    // POST /api/admin/bookings/{id}/archive  (protected — admin token)
    return request("POST", `/admin/bookings/${bookingId}/archive`, null, getAdminToken());
  }

  async function getArchivedBookings({ search = "", date = "" } = {}, { force = false } = {}) {
    // GET /api/admin/bookings/archived  (protected — admin token)
    const params = new URLSearchParams();
    if (search) params.set("search", phoneSearch(search));
    if (date)   params.set("date", date);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/bookings/archived${query}`, null, getAdminToken(), {force});
  }

  async function getGroomingSignedConsent(bookingId) {
    return request("GET", `/admin/bookings/${bookingId}/signed-consent`, null, getAdminToken());
  }

  async function getArchivedClinicAppointments({ search = "", date = "" } = {}, { force = false } = {}) {
    const params = new URLSearchParams();
    if (search) params.set("search", phoneSearch(search));
    if (date) params.set("date", date);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/clinic-appointments/archived${query}`, null, getAdminToken(), {force});
  }

  async function markAllNotificationsRead() {
    // PATCH /api/admin/notifications/read-all  (protected — admin token)
    return request("PATCH", "/admin/notifications/read-all", null, getAdminToken());
  }

  // ── Clinic closures ─────────────────────────────────────────────

  async function getClinicStatus({ force = false } = {}) {
    // GET /api/clinic/status  (public — no token needed)
    return request("GET", "/clinic/status", null, null, { force });
  }

  async function adminStopToday(service) {
    // POST /api/admin/clinic/stop-today  (protected — admin token)
    return request("POST", "/admin/clinic/stop-today", { service }, getAdminToken());
  }

  async function adminReopenToday(service) {
    return request("POST", "/admin/clinic/reopen-today", { service }, getAdminToken());
  }

  async function adminUpdateGroomersOnDuty(groomersOnDuty) {
    return request(
      "PATCH",
      "/admin/clinic/settings/groomers-on-duty",
      { groomers_on_duty: groomersOnDuty },
      getAdminToken(),
    );
  }

  async function getPetGroomers() {
    return request("GET", "/grooming/pet-groomers", null, isAdminPage() ? getAdminToken() : getCustomerToken());
  }

  async function adminSavePetGroomer(data, id = null) {
    return request(id === null ? "POST" : "PATCH", `/admin/pet-groomers${id === null ? "" : `/${id}`}`, data, getAdminToken());
  }

  async function getAvailabilitySettings(options = {}) {
    return request(
      "GET",
      "/admin/clinic/settings/availability",
      null,
      getAdminToken(),
      options,
    );
  }

  async function adminUpdateCapacity(payload) {
    return request('PATCH', '/admin/clinic/settings/capacity', payload, getAdminToken());
  }

  async function adminUpdateAvailability(payload) {
    return request(
      "PATCH",
      "/admin/clinic/settings/availability",
      payload,
      getAdminToken(),
    );
  }

  async function getAdminSecurityAccounts() {
    return request("GET", "/admin/security/accounts", null, getAdminToken());
  }

  async function getSettingsSecurityAccount() {
    return request("GET", "/settings/security/account", null, getAdminToken());
  }

  function validateStaffProfile(payload) {
    return request("POST", "/settings/security/profile/validate", payload, getAdminToken());
  }

  async function updateStaffProfile(payload) {
    const token = getAdminToken(), epoch = staffIdentityEpoch;
    const response = await request("PATCH", "/settings/security/profile", payload, token);
    if (token !== getAdminToken() || epoch !== staffIdentityEpoch) throw new Error('Session changed while saving profile.');
    // Supersede older identity reads without clearing operational data or fetching /me again.
    staffIdentityEpoch++;
    staffIdentityRequest = null;
    verifiedSidebarRevision = response.sidebar_revision;
    rememberStaffIdentity(response, token);
    window.dispatchEvent(new CustomEvent('admin-navigation:updated', { detail: { user: response.user } }));
    return response;
  }

  async function requestStaffPasswordChange(payload) {
    return request(
      "POST",
      "/settings/security/password-change",
      payload,
      getAdminToken(),
    );
  }

  async function confirmStaffPasswordChange(changeId, code) {
    return request(
      "POST",
      `/settings/security/credential-changes/${encodeURIComponent(changeId)}/confirm`,
      { code },
      getAdminToken(),
    );
  }

  async function resendStaffPasswordChangeCode(changeId) {
    return request(
      "POST",
      `/settings/security/credential-changes/${encodeURIComponent(changeId)}/resend`,
      null,
      getAdminToken(),
    );
  }

  async function requestAdminCredentialChange(payload) {
    return request(
      "POST",
      "/admin/security/account/credential-change",
      payload,
      getAdminToken(),
    );
  }

  async function requestStaffAccount(payload) {
    return request(
      "POST",
      "/admin/security/staff-accounts",
      payload,
      getAdminToken(),
    );
  }

  async function updateStaffAccountStatus(staffId, active, currentPassword) {
    return request(
      "PATCH",
      `/admin/security/staff/${encodeURIComponent(staffId)}/status`,
      { active, current_password: currentPassword },
      getAdminToken(),
    );
  }

  async function updateStaffAccountRole(staffId, staffRole, currentPassword) {
    return request(
      "PATCH",
      `/admin/security/staff/${encodeURIComponent(staffId)}/role`,
      { staff_role: staffRole, current_password: currentPassword },
      getAdminToken(),
    );
  }

  async function resendStaffSetupEmail(staffId) {
    return request("POST", `/admin/security/staff/${encodeURIComponent(staffId)}/setup-email`, null, getAdminToken());
  }

  async function correctStaffSetupEmail(staffId, email, currentPassword) {
    return request("PATCH", `/admin/security/staff/${encodeURIComponent(staffId)}/setup-email`,
      { email, current_password: currentPassword }, getAdminToken());
  }

  function startStaffEmailRecovery(staffId, payload) {
    return request('POST', `/admin/security/staff/${encodeURIComponent(staffId)}/email-recovery`, payload, getAdminToken());
  }
  function confirmStaffEmailRecovery(staffId, id, code) {
    return request('POST', `/admin/security/staff/${encodeURIComponent(staffId)}/email-recovery/${encodeURIComponent(id)}/confirm`, { code }, getAdminToken());
  }
  function resendStaffRecoveryCode(staffId, id) {
    return request('POST', `/admin/security/staff/${encodeURIComponent(staffId)}/email-recovery/${encodeURIComponent(id)}/code`, null, getAdminToken());
  }
  function resendStaffEmailRecovery(staffId, id, password) {
    return request('POST', `/admin/security/staff/${encodeURIComponent(staffId)}/email-recovery/${encodeURIComponent(id)}/resend`, { current_password: password }, getAdminToken());
  }
  function cancelStaffEmailRecovery(staffId, id, password, safe) {
    return request('POST', `/admin/security/staff/${encodeURIComponent(staffId)}/email-recovery/${encodeURIComponent(id)}/cancel`, { current_password: password || null, old_email_safe: safe }, getAdminToken());
  }
  function verifyStaffEmailRecovery(token) { return request('POST', '/staff/email-recovery/verify', { token }); }
  function completeStaffEmailRecovery(token, password) { return request('POST', '/staff/email-recovery/complete', { token, current_password: password }); }

  async function requestStaffEmailChange(payload) {
    return request('POST', '/settings/security/email-change', payload, getAdminToken());
  }

  async function confirmStaffEmailChange(id, stage, code) {
    const token = getAdminToken();
    const endpoint = `/settings/security/email-change/${encodeURIComponent(id)}/confirm`;
    let settle;
    const completing = stage === 'new' ? { token, endpoint, settled: new Promise(resolve => { settle = resolve; }) } : null;
    if (completing) emailChangeSignOut = completing;
    try {
      const response = await request('POST', endpoint, { stage, code }, token);
      if (response.completed) {
        if (getAuthSession()?.token !== token) throw new Error('Your account session changed. Sign in again to continue.');
        const session = getAuthSession();
        clearAuthStorage();
        notifyLogout(session.role, 'email_changed', session);
      }
      return response;
    } finally {
      if (completing) {
        if (emailChangeSignOut === completing) emailChangeSignOut = null;
        settle();
      }
    }
  }

  async function resendStaffEmailChange(id, stage) {
    return request('POST', `/settings/security/email-change/${encodeURIComponent(id)}/resend`, { stage }, getAdminToken());
  }

  async function cancelStaffEmailChange(id) {
    return request('DELETE', `/settings/security/email-change/${encodeURIComponent(id)}`, null, getAdminToken());
  }

  async function cancelStaffSetup(staffId, currentPassword) {
    return request("DELETE", `/admin/security/staff/${encodeURIComponent(staffId)}/setup`, { current_password: currentPassword }, getAdminToken());
  }

  async function confirmSecurityCredentialChange(changeId, code) {
    return request(
      "POST",
      `/admin/security/credential-changes/${encodeURIComponent(changeId)}/confirm`,
      { code },
      getAdminToken(),
    );
  }

  async function resendSecurityCredentialChangeCode(changeId) {
    return request(
      "POST",
      `/admin/security/credential-changes/${encodeURIComponent(changeId)}/resend`,
      null,
      getAdminToken(),
    );
  }

  async function getBlockedDates() {
    // GET /api/admin/clinic/blocked-dates  (protected — admin token)
    return request("GET", "/admin/clinic/blocked-dates", null, getAdminToken());
  }

  async function addBlockedDate(payload) {
    // POST /api/admin/clinic/blocked-dates  (protected — admin token)
    // payload: { start_date, end_date, reason }
    return request("POST", "/admin/clinic/blocked-dates", payload, getAdminToken());
  }

  async function removeBlockedDate(id) {
    // DELETE /api/admin/clinic/blocked-dates/{id}  (protected — admin token)
    return request("DELETE", `/admin/clinic/blocked-dates/${id}`, null, getAdminToken());
  }
  async function processPayment(bookingId, payload) {
    // POST /api/admin/bookings/{id}/pay  (protected — admin token)
    // payload: { final_price, amount_paid, payment_method?, notes?, service_prices? }
    return request("POST", `/admin/bookings/${bookingId}/pay`, paymentPayload(payload), getAdminToken());
  }

  function paymentPayload(payload) {
    if (!payload.receipt) return payload;
    const form = new FormData();
    const append = (key, value) => {
      if (value == null) return;
      if (Array.isArray(value)) value.forEach((item, index) => append(`${key}[${index}]`, item));
      else if (typeof value === 'object' && !(value instanceof Blob)) Object.entries(value).forEach(([field, item]) => append(`${key}[${field}]`, item));
      else if (value instanceof Blob) form.append(key, value, value.name || `Payment proof.${({'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp', 'application/pdf': 'pdf'})[value.type] || 'bin'}`);
      else form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
    };
    Object.entries(payload).forEach(([key, value]) => append(key, value));
    return form;
  }

  function getQrPaymentProof(id) {
    return request('GET', `/admin/qr-payments/${encodeURIComponent(id)}/proof`, null, getAdminToken(), {responseType: 'blob', cache: 'no-store'});
  }

  function getQrPaymentConfig() {
    return request('GET', '/admin/qr-payments/config', null, getAdminToken(), {cache: 'no-store'});
  }

  function getQrPaymentSettings() {
    return request('GET', '/admin/qr-payments/settings', null, getAdminToken(), {cache: 'no-store'});
  }

  function saveQrPaymentSettings(payload) {
    return request('POST', '/admin/qr-payments/settings', payload, getAdminToken());
  }

  function getQrPaymentImage(version) {
    return request('GET', `/admin/qr-payments/image?version=${encodeURIComponent(version)}`, null, getAdminToken(), {responseType: 'blob', cache: 'no-store'});
  }

  async function payNow(bookingId, payload) {
    // POST /api/admin/bookings/{id}/pay-now  (protected — admin token)
    // Early payment while booking is still checked_in; accepts the same payload as processPayment.
    return request("POST", `/admin/bookings/${bookingId}/pay-now`, paymentPayload(payload), getAdminToken());
  }


  function createGroomingOnlinePayment(bookingId, payload) {
    return request("POST", `/admin/bookings/${bookingId}/online-payment`, payload, getAdminToken());
  }

  function groomingOnlinePaymentStatus(bookingId, attemptId = null) {
    return request("GET", `/admin/bookings/${bookingId}/online-payment${attemptId ? `?gateway_payment=${attemptId}` : ""}`, null, getAdminToken());
  }

  function createClinicOnlinePayment(id) {
    return request("POST", `/admin/clinic-appointments/${id}/online-payment`, {}, getAdminToken());
  }
  function clinicOnlinePaymentStatus(id, attemptId = null) {
    return request("GET", `/admin/clinic-appointments/${id}/online-payment${attemptId ? `?gateway_payment=${attemptId}` : ""}`, null, getAdminToken());
  }
  function cancelClinicOnlinePayment(id) {
    return request("DELETE", `/admin/clinic-appointments/${id}/online-payment`, null, getAdminToken());
  }

  function cancelGroomingOnlinePayment(bookingId) {
    return request("DELETE", `/admin/bookings/${bookingId}/online-payment`, null, getAdminToken());
  }

  async function releaseBooking(bookingId) {
    // POST /api/admin/bookings/{id}/release  (protected — admin token)
    // Moves an already-paid for_payment booking to released (To Be Picked Up)
    return request("POST", `/admin/bookings/${bookingId}/release`, null, getAdminToken());
  }

  async function markPickedUp(bookingId) {
    // POST /api/admin/bookings/{id}/picked-up  (protected — admin token)
    // Archives a released booking and notifies the customer
    return request("POST", `/admin/bookings/${bookingId}/picked-up`, null, getAdminToken());
  }

  async function getCustomerNotifications(options = {}) {
    // GET /api/customer/notifications  (protected — customer token)
    const page = Number(options.page || 1);
    const params = new URLSearchParams();
    if (page > 1) params.set("page", String(page));
    if (options.sort === "recent") params.set("sort", "recent");
    if (options.status === "unread") params.set("status", "unread");
    const suffix = params.size ? `?${params.toString()}` : "";
    return request("GET", `/customer/notifications${suffix}`, null, getCustomerToken(), { force: options.force === true });
  }

  async function markCustomerNotificationRead(id) {
    // PATCH /api/customer/notifications/{id}/read  (protected — customer token)
    return request("PATCH", `/customer/notifications/${id}/read`, null, getCustomerToken());
  }

  async function markAllCustomerNotificationsRead() {
    // PATCH /api/customer/notifications/read-all  (protected — customer token)
    return request("PATCH", "/customer/notifications/read-all", null, getCustomerToken());
  }

  async function getTransactions({ search = "", period = "day", date = "", week = "", month = "", year = "", transaction_type = "all", page = 1, exclude_test = false } = {}) {
    // GET /api/admin/transactions  (protected — admin token)
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    params.set("transaction_type", transaction_type);
    if (exclude_test) params.set("exclude_test", "1");
    params.set("page", page);
    if (period) params.set("period", period);
    if (date)   params.set("date",   date);
    if (week)   params.set("week",   week);
    if (month)  params.set("month",  month);
    if (year)   params.set("year",   year);
    const query = params.toString() ? `?${params.toString()}` : "";
    return request("GET", `/admin/transactions${query}`, null, getAdminToken());
  }

  async function getGroomingPerformanceReport({ period = "this_month", start = "", end = "" } = {}) {
    return getPerformanceReport("grooming", { period, start, end });
  }

  async function getClinicPerformanceReport({ period = "this_month", start = "", end = "" } = {}) {
    return getPerformanceReport("clinic", { period, start, end });
  }

  async function getPerformanceReport(section, { period, start, end }) {
    const params = new URLSearchParams({ period });
    if (period === "custom") { params.set("start", start); params.set("end", end); }
    return request("GET", `/admin/reports/${section}?${params}`, null, getAdminToken());
  }

  async function getGroomingCatalogue() {
    return request("GET", "/grooming/services", null,
      getAdminToken() || getCustomerToken(), { force: true, cache: "no-store" });
  }

  async function updateGroomingPricing(serviceId, payload) {
    return request("PATCH", `/admin/grooming/services/${encodeURIComponent(serviceId)}/pricing`,
      payload, getAdminToken());
  }

  async function submitWalkIn(payload) {
    // POST /api/admin/walk-in  (protected — admin token)
    // payload: { fname, lname, mname?, email?, phone, pets: [...], sedation_consent, terms_agreed }
    return request("POST", "/admin/walk-in", payload, getAdminToken());
  }


  async function submitClinicWalkIn(payload) {
    // POST /api/admin/clinic-walk-in  (protected — admin token)
    // payload: { owner_record_type, customer_user_id?, unregistered_customer_id?, pet_id?, pet_name, species, breed?, gender?, birthdate?, is_neutered?, neutered_date?, fur_type?, weight?, size?, color?, medical_conditions?, common_concerns, chief_complaint?, terms_agreed }
    return request("POST", "/admin/clinic-walk-in", payload, getAdminToken());
  }

  async function getClinicRecords(page = 1) {
    return request("GET", `/admin/clinic-records?page=${page}`, null, getAdminToken());
  }

  async function getActiveClinicCases({ force = false } = {}) {
    return request("GET", "/admin/clinic-cases", null, getAdminToken(), { force });
  }

  function getClinicCase(id) {
    return request("GET", `/admin/clinic-cases/${encodeURIComponent(id)}`, null, getAdminToken());
  }

  async function getCurrentPetClinicCase(petId) {
    return request("GET", `/admin/pets/${encodeURIComponent(petId)}/clinic-case`, null, getAdminToken());
  }

  async function createClinicCase(payload) {
    return request("POST", "/admin/clinic-cases", payload, getAdminToken());
  }

  function getClinicCaseServices(id, page = 1, scope = '') {
    return request("GET", `/admin/clinic-cases/${id}/services?page=${page}${scope === 'imaging' ? '&scope=imaging' : ''}`, null, getAdminToken());
  }

  function clinicCaseServiceOptions(q = "", page = 1, scope = '') {
    return request("GET", `/admin/clinic-service-options?q=${encodeURIComponent(q)}&page=${page}${scope === 'imaging' ? '&scope=imaging' : ''}`, null, getAdminToken());
  }

  function clinicCaseServicePricing(id, serviceId) {
    return request("GET", `/admin/clinic-cases/${id}/service-pricing/${serviceId}`, null, getAdminToken());
  }

  function clinicCreateCaseService(id, payload) {
    return request("POST", `/admin/clinic-cases/${id}/services`, payload, getAdminToken());
  }

  function clinicDeleteCaseService(id, serviceId) {
    return request("DELETE", `/admin/clinic-cases/${id}/services/${serviceId}`, null, getAdminToken());
  }

  function getClinicCaseCharges(id, page = 1, scope = '') {
    return request("GET", `/admin/clinic-cases/${id}/charges?page=${page}${scope === 'treatment' ? '&scope=treatment' : ''}`, null, getAdminToken());
  }

  function getClinicConfinedCases(page = 1) {
    return request("GET", `/admin/clinic-confinements?page=${page}`, null, getAdminToken());
  }

  function getClinicConfinement(id) {
    return request("GET", `/admin/clinic-cases/${id}/confinement`, null, getAdminToken());
  }

  function clinicConfinementOptions(page = 1) {
    return request("GET", `/admin/clinic-confinement-service-options?page=${page}`, null, getAdminToken());
  }

  function clinicAdmitConfinement(id, payload) {
    return request("POST", `/admin/clinic-cases/${id}/confinement`, payload, getAdminToken());
  }

  function getClinicConfinementObservations(id, page = 1) {
    return request("GET", `/admin/clinic-cases/${id}/confinement/observations?page=${page}`, null, getAdminToken());
  }

  function clinicRecordConfinementObservation(id, payload) {
    return request("POST", `/admin/clinic-cases/${id}/confinement/observations`, payload, getAdminToken());
  }

  function clinicConfinementDischargePreview(id) {
    return request("GET", `/admin/clinic-cases/${id}/confinement/discharge-preview`, null, getAdminToken());
  }

  function clinicDischargeConfinement(id, payload) {
    return request("POST", `/admin/clinic-cases/${id}/confinement/discharge`, payload, getAdminToken());
  }

  async function startClinicCase(id) {
    return request("POST", `/admin/clinic-cases/${id}/start`, {}, getAdminToken());
  }

  async function finishClinicCase(id) {
    return request("POST", `/admin/clinic-cases/${id}/finish`, {}, getAdminToken());
  }

  function clinicCheckIn(id) {
    return request("POST", `/admin/clinic-appointments/${id}/check-in`, {}, getAdminToken());
  }

  function clinicRecordPayment(id, payload) {
    return request("POST", `/admin/clinic-appointments/${id}/pay`, payload, getAdminToken());
  }

  async function clinicCancel(id) {
    return request("POST", `/admin/clinic-appointments/${id}/cancel`, {}, getAdminToken());
  }

  function clinicMedicineOptions(q, suggestions = false, itemId = null) {
    return request("GET", `/admin/clinic-medicine-options?q=${encodeURIComponent(q)}${suggestions ? "&suggestions=1" : ""}${itemId ? "&item_id=" + encodeURIComponent(itemId) : ""}`, null, getAdminToken());
  }

  async function clinicSaveRecord(id, payload) {
    return request("POST", `/admin/clinic-appointments/${id}/record`, payload, getAdminToken());
  }

  async function clinicUploadAttachment(id, file, label = "", caseServiceId = null) {
    const formData = new FormData();
    formData.append("file", file);
    if (label) formData.append("label", label);
    if (caseServiceId) formData.append("clinic_case_service_id", caseServiceId);

    return request(
      "POST",
      `/admin/clinic-appointments/${id}/attachments`,
      formData,
      getAdminToken(),
    );
  }

  async function clinicDownloadAttachment(id, attachmentId) {
    return request(
      "GET",
      `/admin/clinic-appointments/${id}/attachments/${attachmentId}/download`,
      null,
      getAdminToken(),
      { responseType: "blob" },
    );
  }

  async function clinicDeleteAttachment(id, attachmentId) {
    return request(
      "DELETE",
      `/admin/clinic-appointments/${id}/attachments/${attachmentId}`,
      null,
      getAdminToken(),
    );
  }

  async function getAdminPetVaccinations(petId, clinicAppointmentId = null) {
    const query = clinicAppointmentId == null ? '' : `?clinic_appointment_id=${encodeURIComponent(clinicAppointmentId)}`;
    return request(
      "GET",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations${query}`,
      null,
      getAdminToken(),
    );
  }

  async function getAdminPetProfile(petId, history = null) {
    return adminGetPetProfile(petId, history);
  }

  async function getAdminPetVaccination(petId, vaccinationId) {
    return request(
      "GET",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}`,
      null,
      getAdminToken(),
    );
  }

  async function createAdminPetVaccination(petId, payload) {
    return request(
      "POST",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations`,
      payload,
      getAdminToken(),
    );
  }

  async function updateAdminPetVaccination(petId, vaccinationId, payload) {
    return request(
      "PATCH",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}`,
      payload,
      getAdminToken(),
    );
  }

  async function deleteAdminPetVaccination(petId, vaccinationId) {
    return request("DELETE", `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}`, null, getAdminToken());
  }

  async function publishAdminPetVaccination(petId, vaccinationId, { finishCase = false, consumeInventory = null } = {}) {
    return request(
      "POST",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}/publish`,
      { ...(finishCase ? { finish_case: true } : {}), ...(consumeInventory === null ? {} : { consume_inventory: consumeInventory }) },
      getAdminToken(),
    );
  }

  async function voidAdminPetVaccination(petId, vaccinationId, voidReason) {
    return request(
      "POST",
      `/admin/pets/${encodeURIComponent(petId)}/vaccinations/${encodeURIComponent(vaccinationId)}/void`,
      { void_reason: voidReason },
      getAdminToken(),
    );
  }

  async function getAdminVaccinationOptions(clinicalOnly = false) {
    return request("GET", "/admin/vaccination-options" + (clinicalOnly ? "?clinical_only=1" : ""), null, getAdminToken());
  }

  function normalizePhoneLikeIdentifier(value) {
    const digits = String(value || "").replace(/\D/g, "");

    if (!digits) return null;
    if (/^0[89]\d{9}$/.test(digits)) return digits;
    if (/^63[89]\d{9}$/.test(digits)) return `0${digits.slice(2)}`;
    if (/^[89]\d{9}$/.test(digits)) return `0${digits}`;

    return null;
  }

  // ── Public interface ──────────────────────────────────────────────────────

  return {
    // Resolved endpoint (shared by standalone browser services).
    getBaseUrl,
    waitForSessionVerification,
    readAdminCache,
    loadAdminData,
    readCustomerCache,
    loadCustomerData,
    invalidateCustomerCache,
    updateCustomerName,
    adminRequest,
    // Token access (used by other scripts that need to attach the token)
    getCustomerToken,
    setCustomerToken,
    clearCustomerToken,
    getAdminToken,
    setAdminToken,
    clearAdminToken,
    // Role access (used by admin dashboard for staff role-based UI hiding)
    getUserRole,
    getStaffIdentity,
    isClinicWorkspace: () => getCachedStaffIdentity()?.user?.staff_permissions?.clinic_workspace === true,
    getCachedStaffIdentity,
    setUserRole,
    clearUserRole,
    clearAuthState: clearAuthStorage,
    hasAuthenticatedSession,
    isAuthenticationError,
    invalidateSession,
    redirectToSignIn,
    signInPath,
    getSessionInactivityPolicy,
    recordSessionActivity,
    evaluateSessionInactivity,
    isSessionLocked: () => sessionLocked,
    getAdminDataRevision: (group = "grooming") => {
      ensureAdminCache();
      return `${adminCacheEpoch}:${adminCacheRevisions.get(group) || 0}`;
    },
    startSessionInactivityManager,
    enforceAdminPageAccess,
    enforceProtectedPageAccess,
    isAdminOnlyPage,
    // Auth
    register,
    signIn,
    requestPasswordReset,
    verifyPasswordResetCode,
    resetPassword,
    verifyStaffPasswordSetupToken,
    completeStaffPasswordSetup,
    requestNewStaffPasswordSetupLink,
    logout,
    getMe,
    sendPhoneVerification,
    getPhoneVerificationState,
    cancelPhoneVerification,
    verifyPhoneVerification,
    resendPhoneVerification,
    // Timeslots
    getTimeslots,
    getClinicTimeslots,
    // Pets
    getUserPets,
    getPet,
    getPetMedicalRecords,
    getPetVaccinations,
    getCustomerClinicHistory,
    addPet,
    updatePet,
    adminUpdatePet,
    adminAddCustomerPet,
    archivePet,
    unarchivePet,
    // Booking
    storeBooking,
    getPreRegistrationAccess,
    getBookingHistory,
    getBooking,
    getClinicRegistration,
    getGroomingCapacity,
    cancelBooking,
    rescheduleBooking,
    submitClinicPreRegistration,
    // Admin bookings
    getAdminBookings,
    getAdminDashboardSummary,
    getAdminDashboardActivity,
    getAdminActivityLog,
    getAdminActivityDetails,
    getAdminActivityOptions,
    getAdminGroomingActivity,
    adminCheckIn,
    adminUpdateInternalStaffNote,
    adminUpdateGroomingVisitNotes,
    adminRecordSedationConsent,
    adminRevertCheckIn,
    adminStartGrooming,
    adminUndoPetStart,
    adminCancelPet,
    adminStartPetGrooming,
    adminMarkDone,
    adminMarkPetDone,
    adminCancelBooking,
    // Admin customers
    searchAdminDashboard,
    getCustomers,
    getCustomerDetails,
    createUnregisteredCustomer,
    getUnregisteredCustomerDetails,
    archiveUnregisteredCustomer,
    restoreUnregisteredCustomer,
    searchWalkInCustomers,
    validateWalkInNewOwner,
    deactivateCustomer,
    reactivateCustomer,
    archiveCustomer,
    unarchiveCustomer,
    // Admin archive
    adminArchiveBooking,
    getGroomingSignedConsent,
    getArchivedBookings,
    getArchivedClinicAppointments,
    // Admin notifications
    getNotifications,
    markNotificationRead,
    markAllNotificationsRead,
    // Clinic closures
    getClinicStatus,
    adminStopToday,
    adminReopenToday,
    adminUpdateGroomersOnDuty,
    getPetGroomers,
    adminSavePetGroomer,
    getAvailabilitySettings,
    adminUpdateAvailability,
    adminUpdateCapacity,
    getAdminSecurityAccounts,
    getSettingsSecurityAccount,
    validateStaffProfile,
    updateStaffProfile,
    requestStaffPasswordChange,
    requestAdminCredentialChange,
    requestStaffAccount,
    updateStaffAccountStatus,
    updateStaffAccountRole,
    resendStaffSetupEmail,
    correctStaffSetupEmail,
    startStaffEmailRecovery,
    confirmStaffEmailRecovery,
    resendStaffRecoveryCode,
    resendStaffEmailRecovery,
    cancelStaffEmailRecovery,
    verifyStaffEmailRecovery,
    completeStaffEmailRecovery,
    requestStaffEmailChange,
    confirmStaffEmailChange,
    resendStaffEmailChange,
    cancelStaffEmailChange,
    cancelStaffSetup,
    confirmSecurityCredentialChange,
    resendSecurityCredentialChangeCode,
    confirmStaffPasswordChange,
    resendStaffPasswordChangeCode,
    getBlockedDates,
    addBlockedDate,
    removeBlockedDate,
    // Customer notifications
    getCustomerNotifications,
    markCustomerNotificationRead,
    markAllCustomerNotificationsRead,
    // Payments
    getQrPaymentProof,
    getQrPaymentConfig,
    getQrPaymentSettings,
    saveQrPaymentSettings,
    getQrPaymentImage,
    processPayment,
    createClinicOnlinePayment, clinicOnlinePaymentStatus, cancelClinicOnlinePayment,
    createGroomingOnlinePayment, groomingOnlinePaymentStatus, cancelGroomingOnlinePayment,
    payNow,
    releaseBooking,
    markPickedUp,
    getTransactions,
    getGroomingPerformanceReport,
    getClinicPerformanceReport,
    // Walk-in
    getGroomingCatalogue,
    updateGroomingPricing,
    submitWalkIn,
    submitClinicWalkIn,
    getClinicRecords,
    getActiveClinicCases,
    getClinicCase,
    getCurrentPetClinicCase,
    getClinicCaseServices,
    clinicCaseServiceOptions,
    clinicCaseServicePricing,
    clinicCreateCaseService,
    clinicDeleteCaseService,
    getClinicCaseCharges,
    getClinicConfinedCases,
    getClinicConfinement,
    clinicConfinementOptions,
    clinicAdmitConfinement,
    getClinicConfinementObservations,
    clinicRecordConfinementObservation,
    clinicConfinementDischargePreview,
    clinicDischargeConfinement,
    createClinicCase,
    startClinicCase,
    finishClinicCase,
    // Clinic queue
    clinicCancel,
    clinicCheckIn,
    clinicRecordPayment,
    clinicMedicineOptions,
    clinicSaveRecord,
    clinicUploadAttachment,
    clinicDownloadAttachment,
    clinicDeleteAttachment,
    // Staff pet profile and vaccination records
    getAdminPetProfile,
    getAdminPetVaccinations,
    getAdminPetVaccination,
    createAdminPetVaccination,
    updateAdminPetVaccination,
    deleteAdminPetVaccination,
    publishAdminPetVaccination,
    voidAdminPetVaccination,
    getAdminVaccinationOptions,
    // Email verification
    verifyEmail,
    requestRegistrationReview,
    registrationSession,
    updatePendingRegistration,
    adminGetPetProfile,
    adminGetPetCaseDetails,
    resendVerification,
    getEmailDeliveryToken,
    emailDeliveryStatus,
    resendLoginCode,
    confirmLoginCode,
    hasPendingLoginConfirmation,
    getPendingLoginConfirmationEmail,
    clearPendingLoginConfirmation,
  };
})();
