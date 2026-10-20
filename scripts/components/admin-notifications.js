function getAdminNotificationIcon(name) {
  return ({ 'calendar-days': 'calendar-dots', 'circle-check': 'check-circle', 'credit-card': 'credit-card',
    'circle-x': 'x-circle', history: 'clock-counter-clockwise', stethoscope: 'stethoscope' })[name] || 'bell';
}

// Small custom elements share static markup; Alpine remains the only UI controller.
if (typeof customElements !== 'undefined') {
  customElements.define('admin-notification-row', class extends HTMLElement {
    connectedCallback() {
      if (this.childElementCount) return;
      this.innerHTML = `
        <button type="button" @click="markOneRead(notification)" class="admin-notification-row"
          :class="notification.is_read ? '' : 'is-unread'"
          :aria-label="(notification.is_read ? '' : 'Mark as read: ') + notification.message_parts.map(part => part.text).join('')">
          <span class="admin-notification-icon" aria-hidden="true"><svg class="ph-icon h-5 w-5" viewBox="0 0 256 256" fill="currentColor"><use :href="'../../assets/icons/phosphor.svg?v=admin-icons-20261018#' + getAdminNotificationIcon(notification.icon)"></use></svg></span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm leading-6 text-slate-600"><template x-for="(part, index) in notification.message_parts" :key="index"><span :class="part.emphasized ? 'font-semibold' : ''" x-text="part.text"></span></template></span>
            <span x-show="notification.context_parts?.length" class="mt-1 block text-xs text-slate-400"><template x-for="(part, index) in notification.context_parts" :key="index"><span :class="part.emphasized ? 'font-semibold' : ''" x-text="part.text"></span></template></span>
            <span class="mt-1 block text-xs text-slate-400" x-text="formatNotificationTime(notification.created_at)"></span>
          </span>
          <span x-show="!notification.is_read" class="admin-notification-dot" aria-hidden="true"></span>
        </button>`;
    }
  });
  customElements.define('admin-notification-bell', class extends HTMLElement {
    connectedCallback() {
      if (this.childElementCount) return;
      const origin = this.getAttribute('origin');
      const from = ['dashboard', 'grooming', 'clinic'].includes(origin) ? origin : '';
      this.innerHTML = `
        <div x-data="adminNotifications('dropdown')" class="admin-notification-entry"
          @click.outside="if (!$refs.preview?.contains($event.target)) close()" @focusout="if (!$refs.preview?.contains($event.relatedTarget) && !$el.contains($event.relatedTarget)) close()"
          @resize.window="if (open) positionPreview()"
          @keydown.escape.stop.prevent="close(true)">
          <button x-ref="bell" type="button" @click="toggle()" class="admin-notification-bell"
            aria-label="Notifications" :aria-label="unreadCount ? 'Notifications, ' + unreadCount + ' unread' : 'Notifications'"
            :aria-expanded="String(open)" aria-controls="admin-notification-preview">
            <svg class="ph-icon h-5 w-5" aria-hidden="true" viewBox="0 0 256 256" fill="currentColor"><use href="../../assets/icons/phosphor.svg?v=admin-icons-20261018#bell"></use></svg>
            <span x-cloak x-show="unreadCount > 0" x-text="unreadCount" class="admin-notification-badge" aria-hidden="true"></span>
          </button>
          <template x-teleport="body"><section x-ref="preview" x-cloak x-show="open" :style="previewStyle" id="admin-notification-preview" aria-label="Notifications" tabindex="-1" class="admin-notification-preview"
            @click.outside="if (!$refs.bell.contains($event.target)) close()"
            @focusout="if (!$el.contains($event.relatedTarget) && $event.relatedTarget !== $refs.bell) close()"
            @keydown.escape.stop.prevent="close(true)">
            <div class="admin-notification-preview-heading"><h3>Notifications</h3><button type="button" @click="markAllRead()" :disabled="busy || unreadCount === 0">Mark all as read</button></div>
            <div class="admin-notification-tabs" role="group" aria-label="Read status">
              <button type="button" @click="setStatus('all')" :aria-pressed="statusFilter === 'all'">All</button>
              <button type="button" @click="setStatus('unread')" :aria-pressed="statusFilter === 'unread'">Unread</button>
            </div>
            <div class="admin-notification-preview-list" :aria-busy="loading">
              <admin-loader x-cloak x-show="loading && !loaded"></admin-loader>
              <p x-cloak x-show="error" class="admin-load-error" role="alert"><span x-text="error"></span><button type="button" @click="loadNotifications(true)">Retry</button></p>
              <p x-cloak x-show="loaded && !notifications.length" class="admin-notification-empty" x-text="statusFilter === 'unread' ? 'No unread notifications.' : 'No notifications yet.'"></p>
              <div x-cloak x-show="loaded"><template x-for="group in notificationGroups()" :key="group.dateKey"><section>
                <h4 class="admin-notification-date" x-text="group.label"></h4>
                <template x-for="notification in group.notifications" :key="notification.notification_id"><admin-notification-row></admin-notification-row></template>
              </section></template></div>
            </div>
            <div class="admin-notification-preview-footer"><a href="./notifications.html${from ? '?from=' + from : ''}">See all notifications</a></div>
          </section></template>
        </div>`;
    }
  });
}

function adminNotifications(mode = 'full') {
  return {
    mode,
    notifications: [], unreadCount: 0,
    statusFilter: 'all', serviceFilter: 'all', activityFilter: 'all',
    activityOptions: [],
    page: 1, hasMore: false, loading: false, loaded: false,
    _loadedFilter: '', loadingMore: false, error: '', _requestId: 0,
    open: false, busy: false, _destroyed: false, previewStyle: '',

    init() {
      this._unsubscribe = AdminNotificationService.subscribe(event => this.onNotificationChange(event), {loadCount: this.mode !== 'full'});
      if (this.mode === 'full') {
        void window.AppClock?.load?.();
        return this.loadNotifications(true);
      }
    },

    destroy() {
      this._destroyed = true;
      this._requestId++;
      this._unsubscribe?.();
    },

    onNotificationChange(event) {
      const countChanged = this.unreadCount !== event.unreadCount;
      this.unreadCount = event.unreadCount;
      if (event.kind === 'reset') {
        this._requestId++;
        this.notifications = [];
        this.loaded = this.loading = this.loadingMore = this.open = false;
        return;
      }
      if (event.kind === 'changed') {
        this.notifications = this.notifications.map(notification => event.all || notification.notification_id === event.id
          ? {...notification, is_read: true} : notification);
        if (this.statusFilter === 'unread' || (event.all && this.mode === 'full')) return this.loadNotifications(true);
      }
      if (['invalidated', 'stale'].includes(event.kind)) this.loaded = false;
      if (['invalidated', 'resume'].includes(event.kind) && (this.open || this.mode === 'full')) return this.loadNotifications(true);
      if (event.kind === 'count' && countChanged && (this.open || this.mode === 'full') && this.loaded && !this.loading) return this.loadNotifications(true);
    },

    async toggle() {
      this.open = !this.open;
      if (this.open) {
        this.positionPreview();
        this.$nextTick(() => this.$refs.preview?.focus());
        await this.loadNotifications(true);
      }
    },

    positionPreview() {
      const rect = this.$refs.bell.getBoundingClientRect();
      const top = Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - 240));
      const right = Math.max(12, Math.min(window.innerWidth - rect.right, window.innerWidth - Math.min(352, window.innerWidth - 24) - 12));
      this.previewStyle = `top:${top}px;right:${right}px;max-height:${window.innerHeight - top - 12}px`;
    },

    close(returnFocus = false) {
      this.open = false;
      if (returnFocus) this.$refs.bell?.focus();
    },

    async setStatus(value) {
      if (this.statusFilter === value) return;
      this.statusFilter = value;
      await this.loadNotifications(true);
    },
    async setService(value) {
      if (this.serviceFilter === value) return;
      this.serviceFilter = value;
      await this.loadNotifications(true);
    },
    async setActivity(value) {
      if (this.activityFilter === value) return;
      this.activityFilter = value;
      await this.loadNotifications(true);
    },

    async loadNotifications(reset = false) {
      if (this._destroyed) return;
      const requestId = ++this._requestId;
      const filter = JSON.stringify([this.statusFilter, this.serviceFilter, this.activityFilter]);
      this.loaded = this.loaded && filter === this._loadedFilter;
      if (reset) { this.page = 1; this.loading = true; this.loadingMore = false; }
      else this.loadingMore = true;
      this.error = '';
      try {
        const data = await AdminNotificationService.load({
          mode: this.mode, status: this.statusFilter,
          service: this.serviceFilter, activity: this.activityFilter,
          page: this.page, per_page: 10,
        });
        if (requestId !== this._requestId || this._destroyed) return;
        const rows = Array.isArray(data.notifications) ? data.notifications : [];
        this.notifications = reset ? rows : [...this.notifications, ...rows];
        this.unreadCount = Number(data.unread_count || 0);
        this.activityOptions = data.activity_options || this.activityOptions;
        this.hasMore = Boolean(data.pagination?.has_more);
        this.loaded = true;
        this._loadedFilter = filter;
      } catch (error) {
        if (requestId !== this._requestId || this._destroyed) return;
        this.error = error?.message || 'Unable to load notifications.';
        if (!reset) this.page = Math.max(1, this.page - 1);
      } finally {
        if (requestId === this._requestId) this.loading = this.loadingMore = false;
      }
    },

    async loadMore() {
      if (!this.hasMore || this.loading || this.loadingMore) return;
      this.page += 1;
      await this.loadNotifications(false);
    },

    async markAllRead() {
      if (this.busy) return;
      this.busy = true; this.error = '';
      try { await AdminNotificationService.markAllRead(); }
      catch (error) { this.error = error?.message || 'Unable to mark notifications as read.'; }
      finally { this.busy = false; }
    },

    async markOneRead(notification) {
      if (notification.is_read) return;
      this.error = '';
      try {
        await AdminNotificationService.markRead(notification.notification_id);
      } catch (error) { this.error = error?.message || 'Unable to mark the notification as read.'; }
    },

    notificationGroups() {
      const currentDate = window.AppClock?.now?.() || new Date();
      const todayKey = window.AppClock?.todayKey?.()
        || this.manilaDateKey(currentDate);
      const yesterdayKey = window.AppClock?.dateKeyWithOffset?.(-1)
        || this.manilaDateKey(new Date(currentDate.getTime() - 86400000));
      const groupedByDate = new Map();

      for (const notification of this.notifications) {
        const dateKey = this.manilaDateKey(notification.created_at);
        if (!groupedByDate.has(dateKey)) groupedByDate.set(dateKey, []);
        groupedByDate.get(dateKey).push(notification);
      }

      return Array.from(groupedByDate, ([dateKey, notifications]) => ({
        dateKey,
        label: this.notificationGroupLabel(dateKey, todayKey, yesterdayKey),
        notifications,
      }));
    },

    notificationGroupLabel(dateKey, todayKey, yesterdayKey) {
      if (dateKey === todayKey) return "Today";
      if (dateKey === yesterdayKey) return "Yesterday";

      const [year, month, day] = dateKey.split("-").map(Number);
      if (!year || !month || !day) return "Date unavailable";

      const currentYear = Number(todayKey.slice(0, 4));
      return new Intl.DateTimeFormat("en-US", {
        month: "short",
        day: "numeric",
        ...(year !== currentYear ? { year: "numeric" } : {}),
        timeZone: "UTC",
      }).format(new Date(Date.UTC(year, month - 1, day)));
    },

    manilaDateKey(value) {
      const date = value instanceof Date ? value : new Date(value);
      if (Number.isNaN(date.getTime())) return "";

      const parts = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Manila",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      }).formatToParts(date);
      const values = Object.fromEntries(
        parts.filter((part) => part.type !== "literal")
          .map((part) => [part.type, part.value]),
      );

      return `${values.year}-${values.month}-${values.day}`;
    },

    formatNotificationTime(value) {
      if (!value) return "";
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return "";

      return date.toLocaleTimeString("en-PH", {
        timeZone: "Asia/Manila",
        hour: "numeric",
        minute: "2-digit",
      });
    },
  };
}
