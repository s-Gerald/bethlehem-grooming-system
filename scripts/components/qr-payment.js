// LEGACY QR PAYMENT: TEMPORARILY DISABLED. Manual QR/OCR is preserved here.
// Active workflows must not import this module. PayMongo Online Payment (QR Ph) is separate.
export function createQrPayment() {
  return {
    settings: null, paymentSource: '', loading: false, error: '', imageUrl: '', imageLoading: false,
    amount: '', externalReference: '', transactionAt: '', recipient: '', referenceCandidates: [],
    referenceError: '', _invalidReference: '',
    verified: false,
    ocrBusy: false, ocrProgress: 0, ocrMessage: '',
    disposed: false,
    receiptName: '', receiptPreview: '', scanOpen: false,
    receiptAmountExtracted: false, expectedAmount: null,
    receiptFile: null, payingApp: '', _derived: {}, _edited: {}, _ocrController: null, _scanVersion: 0,
    get recipientWarning() {
      const normalize = value => String(value || '').normalize('NFKD').toLowerCase().replace(/[^a-z0-9]/g, '');
      const recipient = normalize(this.recipient), merchant = normalize(this.settings?.merchant_name);
      return recipient && merchant && !recipient.includes(merchant) && !merchant.includes(recipient)
        ? 'Receipt recipient differs from the receiving account. Check the account credit carefully.' : '';
    },
    get normalizedReference() {
      return this.externalReference.replace(/[ -]/g, '').toUpperCase();
    },
    setReferenceError(message) {
      this.referenceError = message;
      this._invalidReference = this.normalizedReference;
    },
    clearReferenceErrorIfChanged() {
      if (this.normalizedReference !== this._invalidReference) {
        this.referenceError = this._invalidReference = '';
      }
    },
    setExpectedAmount(total) {
      const cents = Math.round(total * 100);
      if (this.expectedAmount !== cents) this.verified = false;
      this.expectedAmount = cents;
    },
    reviewField(key) {
      delete this._derived[key];
      this._edited[key] = true;
      if (key === 'externalReference') this.clearReferenceErrorIfChanged();
      this.verified = false;
    },
    selectReference(candidate) {
      if (!this.referenceCandidates.some(item => item.value === candidate.value)) return;
      this.externalReference = candidate.value;
      this.reviewField('externalReference');
    },
    applyExtraction(result) {
      if (result.amount) this.receiptAmountExtracted = true;
      this.referenceCandidates = result.externalReference ? [] : result.referenceCandidates || [];
      for (const key of ['amount', 'externalReference', 'transactionAt', 'recipient', 'payingApp']) {
        if (result[key] && !this._edited[key] && (!this[key] || this._derived[key] === this[key])) { this[key] = result[key]; this._derived[key] = result[key]; }
      }
      this.clearReferenceErrorIfChanged();
      this.verified = false;
    },
    removeReceipt() {
      this.cancelScan();
      if (this.receiptPreview) URL.revokeObjectURL(this.receiptPreview);
      for (const [key, value] of Object.entries(this._derived)) if (this[key] === value) this[key] = '';
      this.clearReferenceErrorIfChanged();
      this._derived = {};
      this.referenceCandidates = [];
      this.receiptAmountExtracted = false;
      this.receiptFile = null;
      this.receiptName = this.receiptPreview = this.ocrMessage = '';
      this.ocrProgress = 0;
      this.verified = false;
    },

    async load() {
      this.loading = true;
      try { this.settings = (await API.getQrPaymentConfig()).settings; }
      catch (error) { this.error = error.message || 'QR settings are unavailable.'; }
      finally { this.loading = false; }
    },
    async showImage() {
      if (!this.settings?.enabled || this.imageUrl || this.imageLoading) return;
      this.imageLoading = true;
      this.error = '';
      try {
        const { blob } = await API.getQrPaymentImage(this.settings.version);
        if (!this.disposed) this.imageUrl = URL.createObjectURL(blob);
      } catch (error) { if (!this.disposed) this.error = error.message || 'Could not load the official QR. Retry before collecting payment.'; }
      finally { this.imageLoading = false; }
    },
    async extract(file) {
      if (!file || this.disposed) return;
      this.scanOpen = true;
      this.verified = false;
      if (!['image/png', 'image/jpeg', 'image/webp', 'application/pdf'].includes(file.type) || file.size > 5 * 1024 * 1024) {
        this.ocrMessage = 'Select a PNG, JPEG, WebP or PDF receipt up to 5 MB.';
        return;
      }
      this.removeReceipt();
      this.receiptFile = file;
      this.receiptName = file.name || 'Clipboard receipt.png';
      if (file instanceof Blob && file.type.startsWith('image/')) this.receiptPreview = URL.createObjectURL(file);
      const controller = new AbortController();
      this._ocrController = controller;
      const version = ++this._scanVersion;
      const current = () => !this.disposed && version === this._scanVersion && !controller.signal.aborted;
      this.ocrBusy = true;
      try {
        const { recognizePaymentProof } = await import('../services/payment-proof-ocr.js?v=20261023-references');
        if (!current()) return;
        const result = await recognizePaymentProof(file, progress => { if (current()) this.ocrProgress = progress; }, controller.signal);
        if (!current()) return;
        this.applyExtraction(result);
        this.ocrMessage = !result.externalReference && this.referenceCandidates.length < 2
          ? 'No payment reference found. Enter it manually.' : '';
      } catch (error) { if (current()) this.ocrMessage = error.message || 'OCR failed. Enter the details manually.'; }
      finally { if (version === this._scanVersion) { this.ocrBusy = false; this._ocrController = null; } }
    },
    paste(event) {
      const image = [...(event.clipboardData?.items || [])].find(item => item.type.startsWith('image/'));
      if (image) { event.preventDefault(); void this.extract(image.getAsFile()); }
    },
    drop(event) {
      event.preventDefault();
      return this.extract(event.dataTransfer?.files?.[0]);
    },
    cancelScan() { this._ocrController?.abort(); this._scanVersion++; this.ocrBusy = false; },
    validationError(total) {
      if (!this.settings?.enabled) return 'QR payments are unavailable.';
      if (!(this.settings.accepted_methods || []).includes(this.paymentSource)) return 'Select an enabled payment method.';
      if (this.ocrBusy) return 'Wait for OCR to finish.';
      if (this.referenceError) return this.referenceError;
      if ((this.receiptAmountExtracted || this.amount !== '') && (!/^\d+(?:\.\d{1,2})?$/.test(String(this.amount)) || Math.round(Number(this.amount) * 100) !== Math.round(total * 100))) return 'Receipt amount differs from Total Due. Check the account credit and correct a scan error, or leave payment unresolved.';
      if (!/^[A-Z0-9 -]+$/i.test(this.externalReference) || !/^[A-Z0-9]{6,80}$/.test(this.normalizedReference)) return 'Enter the Payment Reference No. of 6–80 letters or numbers.';
      return this.verified ? '' : 'Verify receipt of funds in the clinic’s official receiving account.';
    },
    payload() {
      return {
        payment_source: this.paymentSource,
        qr_settings_version: this.settings?.version, qr_verified: this.verified,
        external_reference: this.externalReference,
        transaction_at: this.transactionAt || null,
        observed_amount: this.amount || null,
        paying_app: this.payingApp || null,
      };
    },
    dispose() {
      this.disposed = true;
      this.removeReceipt();
      if (this.imageUrl) URL.revokeObjectURL(this.imageUrl);
      this.imageUrl = '';
    },
  };
}
