function adminReports() {
  let chartObserver;
  const money = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 });
  const whole = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 });
  const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(`${value}T00:00:00Z`))
    && new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value;
  return {
    activeSection: 'grooming', period: 'this_month', start: '', end: '', metric: 'collections',
    loading: true, error: '', validationError: '', report: null, requestId: 0,
    showAllServices: false, activePoint: null, chartWidth: 740,
    async init() { await this.loadReport(); this.refreshIcons(); },
    async changePeriod() {
      if (this.period === 'custom') {
        this.start ||= this.report?.period.start || '';
        this.end ||= this.report?.period.end || '';
      }
      await this.loadReport();
    },
    async loadReport() {
      const requestId = ++this.requestId;
      this.error = ''; this.validationError = ''; this.activePoint = null;
      this.showAllServices = false;
      this.report = null;
      if (this.period === 'custom' && (!validDate(this.start) || !validDate(this.end) || this.start > this.end)) {
        this.validationError = 'Enter a valid start and end date, with the end on or after the start.';
        this.loading = false;
        return;
      }
      this.loading = true;
      try {
        const filters = { period: this.period };
        if (this.period === 'custom') Object.assign(filters, { start: this.start, end: this.end });
        const data = await API.getGroomingPerformanceReport(filters);
        if (requestId !== this.requestId) return;
        if (data?.success !== true || !data.overview || !data.period || !Array.isArray(data.performance)
          || !Array.isArray(data.services) || !data.customers || !data.outcomes) throw new Error('Unable to load reports. Please try again.');
        this.report = data;
      } catch {
        if (requestId === this.requestId) this.error = 'Unable to load reports. Please try again.';
      } finally {
        if (requestId === this.requestId) { this.loading = false; this.refreshIcons(); }
      }
    },
    refreshIcons() { this.$nextTick(() => window.lucide?.createIcons()); },
    observeChart(element) {
      chartObserver?.disconnect();
      chartObserver = new ResizeObserver(entries => {
        const width = Math.round(entries[0].contentRect.width);
        if (width > 0 && width !== this.chartWidth) this.chartWidth = width;
      });
      chartObserver.observe(element);
    },
    inspectPoint(event) {
      const value = event.target.dataset.pointIndex;
      this.activePoint = value === undefined ? null : Number(value);
    },
    destroy() { chartObserver?.disconnect(); this.requestId++; },
    formatMoney(value) { return money.format(value); },
    formatCount(value) { return whole.format(value); },
    dateLabel(value, options = { month: 'short', day: 'numeric' }) {
      return new Intl.DateTimeFormat('en-PH', { ...options, timeZone: 'Asia/Manila' }).format(new Date(`${value}T00:00:00+08:00`));
    },
    get periodLabel() {
      if (!this.report) return '';
      const { start, end } = this.report.period;
      const format = value => this.dateLabel(value, { month: 'short', day: 'numeric', year: 'numeric' });
      return start === end ? format(start) : `${format(start)} – ${format(end)}`;
    },
    get kpis() {
      const overview = this.report?.overview;
      if (!overview) return [];
      return [
        { title: 'Grooming Collections', value: this.formatMoney(overview.collections), icon: 'wallet' },
        { title: 'Completed Visits', value: this.formatCount(overview.completedVisits), icon: 'paw-print' },
        { title: 'Average Sale', value: this.formatMoney(overview.averageSale), icon: 'receipt', hint: 'Collected grooming amount per paid booking' },
        { title: 'Returning Customers', value: this.formatCount(overview.returningCustomers), icon: 'repeat-2' },
      ];
    },
    get hasPerformance() { return !!this.report?.performance.some(point => point[this.metric] > 0); },
    get chartMax() {
      const max = Math.max(0, ...(this.report?.performance || []).map(point => Number(point[this.metric])));
      if (!max) return this.metric === 'visits' ? 4 : 100;
      const step = this.metric === 'visits' ? Math.max(1, Math.ceil(max / 4)) : Math.pow(10, Math.floor(Math.log10(max))) / 2;
      return Math.ceil(max / (step * 4)) * step * 4;
    },
    get chartPoints() {
      const rows = this.report?.performance || [];
      const max = this.chartMax;
      return rows.map((point, i) => ({ ...point, index: i, value: Number(point[this.metric]),
        x: rows.length === 1 ? (this.chartWidth + 50) / 2 : 70 + i * (this.chartWidth - 100) / (rows.length - 1),
        y: 210 - Number(point[this.metric]) / max * 180,
        label: point.date === point.end ? this.dateLabel(point.date) : `${this.dateLabel(point.date)} – ${this.dateLabel(point.end)}`,
      }));
    },
    get linePoints() { return this.chartPoints.map(point => `${point.x},${point.y}`).join(' '); },
    get barWidth() { return Math.min(36, (this.chartWidth - 100) * .75 / Math.max(1, this.chartPoints.length)); },
    get yTicks() {
      return [0, 1, 2, 3, 4].map(i => ({ y: 210 - i * 45, value: this.chartMax * i / 4 }));
    },
    axisLabel(value) {
      if (this.metric === 'visits') return this.formatCount(value);
      return `₱${new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 1 }).format(value)}`;
    },
    get xTicks() {
      const points = this.chartPoints;
      const fractions = this.chartWidth < 450 ? [0, .5, 1] : [0, .25, .5, .75, 1];
      const indexes = [...new Set(fractions.map(fraction => Math.round((points.length - 1) * fraction)))];
      return indexes.filter(i => i >= 0).map(i => points[i]).filter(Boolean);
    },
    get chartMarkup() {
      // Only formatted dates, numbers and fixed labels enter this SVG. Catalogue
      // names stay in x-text. SVG templates are not HTMLTemplateElements in Alpine.
      const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
      const grid = this.yTicks.map(tick => `<line x1="70" x2="${this.chartWidth - 30}" y1="${tick.y}" y2="${tick.y}" class="reports-gridline"/><text x="58" y="${tick.y + 4}" text-anchor="end">${escape(this.axisLabel(tick.value))}</text>`).join('');
      const line = this.metric === 'collections' ? `<polyline points="${this.linePoints}" class="reports-line"/>` : '';
      const points = this.chartPoints;
      const barWidth = this.barWidth;
      const marks = points.map(point => {
        const mark = this.metric === 'collections'
          ? `<circle cx="${point.x}" cy="${point.y}" r="${points.length === 1 ? 5 : 3}" class="reports-chart-dot"/>`
          : `<rect x="${point.x - barWidth / 2}" y="${point.y}" width="${barWidth}" height="${210 - point.y}" rx="3" class="reports-visit-bar"/>`;
        const label = escape(this.pointDescription(point));
        return `${mark}<circle cx="${point.x}" cy="${point.y}" r="13" class="reports-chart-target" data-point-index="${point.index}" tabindex="0" role="img" aria-label="${label}"><title>${label}</title></circle>`;
      }).join('');
      const labels = this.xTicks.map(point => `<text x="${point.x}" y="240" text-anchor="middle">${escape(this.dateLabel(point.date, this.report?.period.grouping === 'month' ? { month: 'short', year: '2-digit' } : { month: 'short', day: 'numeric' }))}</text>`).join('');
      return grid + line + marks + labels;
    },
    pointDescription(point) { return `${point.label}: ${this.metric === 'collections' ? this.formatMoney(point.value) : `${this.formatCount(point.value)} ${point.value === 1 ? 'visit' : 'visits'}`}`; },
    get visibleServices() { return this.showAllServices ? (this.report?.services || []) : (this.report?.services || []).slice(0, 6); },
    serviceWidth(count) { return `${count / Math.max(1, this.report?.services[0]?.count || 0) * 100}%`; },
    serviceKind(kind) { return ({ package: 'Package', ala_carte: 'À la carte', addon: 'Add-on' })[kind] || 'Service'; },
    get customerTotal() { return (this.report?.customers.new || 0) + (this.report?.customers.returning || 0); },
    get newCustomerWidth() { return `${(this.report?.customers.new || 0) / Math.max(1, this.customerTotal) * 100}%`; },
  };
}
