// Data stays in API's session-owned admin cache. This service only coordinates consumers.
var AdminNotificationService = (() => {
  const subscribers = new Set();
  const mutations = new Map();
  let count = 0, owner = null, revision = 0, readSequence = 0, interval = null, idle = null, invalidation = null;
  let mutationQueue = Promise.resolve(), sessionRevision = 0;
  const emit = event => Promise.allSettled([...subscribers].map(listener => listener({ ...event, unreadCount: count })));
  const available = () => document.visibilityState !== 'hidden' && !API.isSessionLocked?.() && API.hasAuthenticatedSession('admin');

  function reset() {
    revision++;
    sessionRevision++;
    owner = API.getAdminToken();
    count = 0;
    mutations.clear();
    clearTimeout(invalidation);
    void emit({kind: 'reset'});
  }

  async function load(filters) {
    if (owner !== API.getAdminToken()) reset();
    const token = owner, current = revision, sequence = ++readSequence;
    const data = await API.getNotifications(filters);
    if (current !== revision || token !== API.getAdminToken() || API.isSessionLocked?.()) {
      throw new Error('Notifications changed while loading. Please retry.');
    }
    if (sequence === readSequence) {
      count = Number(data.unread_count || 0);
      void emit({kind: 'count'});
    }
    // Consumers can update read state without mutating retained API responses.
    return {...data, notifications: data.notifications?.map(notification => ({...notification})),
      ...(data.selected_notification ? {selected_notification: {...data.selected_notification}} : {})};
  }

  async function refreshCount() {
    if (!available()) return;
    try { await load({mode: 'count'}); }
    catch { /* Keep the last verified badge; the preview offers a visible retry. */ }
  }

  function invalidate(event) {
    if (!event.detail?.groups?.includes('notifications')) return;
    revision++;
    clearTimeout(invalidation);
    invalidation = setTimeout(async () => {
      if (!available()) { void emit({kind: 'stale'}); return; }
      // Active views fetch first; their response can supply the count with no second request.
      await emit({kind: 'invalidated'});
      await refreshCount();
    }, 0);
  }

  async function mutate(key, action) {
    if (mutations.has(key)) return mutations.get(key);
    const token = API.getAdminToken(), session = sessionRevision;
    const pending = mutationQueue.then(() => {
      if (token !== API.getAdminToken() || session !== sessionRevision || API.isSessionLocked?.()) throw new Error('Session changed. Please retry.');
      return action();
    }).then(async data => {
      if (token !== API.getAdminToken() || API.isSessionLocked?.()) return;
      clearTimeout(invalidation);
      count = Number(data.unread_count || 0);
      await emit({kind: 'changed', id: key === 'all' ? null : key, all: key === 'all'});
    }).finally(() => { if (mutations.get(key) === pending) mutations.delete(key); });
    mutationQueue = pending.catch(() => {});
    mutations.set(key, pending);
    return pending;
  }

  const resume = () => {
    if (!available()) return;
    void emit({kind: 'resume'}).then(refreshCount);
  };
  function subscribe(listener, {loadCount = true} = {}) {
    if (owner !== API.getAdminToken()) reset();
    subscribers.add(listener);
    listener({kind: 'count', unreadCount: count});
    if (subscribers.size === 1) {
      window.addEventListener('admin-data:invalidated', invalidate);
      window.addEventListener('admin-session:locked', reset);
      window.addEventListener('admin-session:unlocked', resume);
      window.addEventListener('admin-navigation:invalidated', reset);
      document.addEventListener('visibilitychange', resume);
      interval = setInterval(refreshCount, 30000);
      // Page workspaces start their primary requests before this secondary count.
      if (loadCount) idle = setTimeout(refreshCount, 0);
    }
    return () => {
      subscribers.delete(listener);
      if (subscribers.size) return;
      clearInterval(interval); clearTimeout(idle); clearTimeout(invalidation);
      window.removeEventListener('admin-data:invalidated', invalidate);
      window.removeEventListener('admin-session:locked', reset);
      window.removeEventListener('admin-session:unlocked', resume);
      window.removeEventListener('admin-navigation:invalidated', reset);
      document.removeEventListener('visibilitychange', resume);
    };
  }

  return {
    get unreadCount() { return owner === API.getAdminToken() ? count : 0; },
    load, subscribe,
    markRead: id => mutate(id, () => API.markNotificationRead(id)),
    markAllRead: () => mutate('all', () => API.markAllNotificationsRead()),
  };
})();
