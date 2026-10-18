function adminNotifications() {
  return {
    notifications: [],
    unreadCount: 0,
    statusFilter: "all",
    categoryFilter: "all",
    categoryMenuOpen: false,
    categoryOptions: [
      { value: "all", label: "All Types" },
      { value: "grooming", label: "Grooming" },
      { value: "clinic", label: "Clinic" },
      { value: "payments", label: "Payments" },
      { value: "cancellations", label: "Cancellations" },
    ],
    page: 1,
    hasMore: false,
    loading: true,
    loaded: false,
    _loadedFilter: '',
    loadingMore: false,
    error: "",
    _requestId: 0,

    async init() {
      await window.AppClock?.load?.();
      await this.loadNotifications(true);
    },

    async setStatus(status) {
      if (this.statusFilter === status || this.loading) return;
      this.categoryMenuOpen = false;
      this.statusFilter = status;
      await this.loadNotifications(true);
    },

    selectedCategoryLabel() {
      return this.categoryOptions.find(
        (option) => option.value === this.categoryFilter,
      )?.label || "All Types";
    },

    toggleCategoryMenu() {
      if (this.loading) return;
      this.categoryMenuOpen = !this.categoryMenuOpen;
    },

    openCategoryMenu(last = false) {
      if (this.loading) return;
      this.categoryMenuOpen = true;
      this.$nextTick(() => this.focusCategoryOption(last));
    },

    async selectCategory(value) {
      if (
        this.loading
        || !this.categoryOptions.some((option) => option.value === value)
      ) return;

      this.categoryMenuOpen = false;
      if (this.categoryFilter === value) return;

      this.categoryFilter = value;
      await this.loadNotifications(true);
    },

    focusCategoryOption(last = false) {
      const options = Array.from(
        this.$refs.categoryListbox?.querySelectorAll('[role="option"]') || [],
      );
      (last ? options.at(-1) : options[0])?.focus();
    },

    moveCategoryFocus(currentOption, offset) {
      const options = Array.from(
        this.$refs.categoryListbox?.querySelectorAll('[role="option"]') || [],
      );
      const currentIndex = options.indexOf(currentOption);
      if (currentIndex < 0 || options.length === 0) return;

      const nextIndex = (currentIndex + offset + options.length) % options.length;
      options[nextIndex]?.focus();
    },

    async loadNotifications(reset = false) {
      const requestId = ++this._requestId;
      const filter = JSON.stringify([this.statusFilter, this.categoryFilter]);
      this.loaded = this.loaded && filter === this._loadedFilter;

      if (reset) {
        this.page = 1;
        this.loading = true;
      } else {
        this.loadingMore = true;
      }

      this.error = "";

      try {
        const data = await API.getNotifications({
          mode: "full",
          status: this.statusFilter,
          category: this.categoryFilter,
          page: this.page,
          per_page: 10,
        });

        if (requestId !== this._requestId) return;

        const nextNotifications = Array.isArray(data.notifications)
          ? data.notifications
          : [];

        this.notifications = reset
          ? nextNotifications
          : [...this.notifications, ...nextNotifications];
        this.unreadCount = Number(data.unread_count || 0);
        this.hasMore = Boolean(data.pagination?.has_more);
        this.loaded = true;
        this._loadedFilter = filter;
      } catch (error) {
        if (requestId !== this._requestId) return;
        this.error = error?.message || "Unable to load notifications.";
        if (!reset) this.page = Math.max(1, this.page - 1);
      } finally {
        if (requestId === this._requestId) {
          this.loading = false;
          this.loadingMore = false;
        }
      }
    },

    async loadMore() {
      if (!this.hasMore || this.loading || this.loadingMore) return;
      this.page += 1;
      await this.loadNotifications(false);
    },

    async markAllRead() {
      if (this.loading) return;
      this.loading = true;
      this.error = "";

      try {
        await API.markAllNotificationsRead();
        this.unreadCount = 0;
        await this.loadNotifications(true);
      } catch (error) {
        this.error = error?.message || "Unable to mark notifications as read.";
        this.loading = false;
      }
    },

    async markOneRead(notification) {
      if (notification.is_read) return;

      try {
        await API.markNotificationRead(notification.notification_id);
        this.unreadCount = Math.max(0, this.unreadCount - 1);

        if (this.statusFilter === "unread") {
          await this.loadNotifications(true);
          return;
        }

        notification.is_read = true;
      } catch (error) {
        this.error = error?.message || "Unable to mark the notification as read.";
      }
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
