export function createQrPayment() {
  return {
    settings: null, loading: false, error: '', imageUrl: '', imageLoading: false,
    amount: '', externalReference: '', transactionAt: '', recipient: '',
    verified: false,
    missingReference: false, exceptionReason: '', exceptionVerified: false,
    isAdmin: API.getUserRole() === 'admin', ocrBusy: false, ocrProgress: 0, ocrMessage: '',
    disposed: false,
    receiptName: '', receiptPreview: '', scanOpen: false,
    _ocrController: null,
    get recipientWarning() {
      const normalize = value => String(value || '').normalize('NFKD').toLowerCase().replace(/[^a-z0-9]/g, '');
      const recipient = normalize(this.recipient), merchant = normalize(this.settings?.merchant_name);
      return recipient && merchant && !recipient.includes(merchant) && !merchant.includes(recipient)
        ? 'Receipt recipient differs from the receiving account. Check the account credit carefully.' : '';
    },
    applyExtraction(result) {
      for (const key of ['amount', 'externalReference', 'transactionAt']) if (result[key]) this[key] = result[key];
      this.recipient = result.recipient || '';
      this.verified = false; this.exceptionVerified = false;
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
      if (!file || this.ocrBusy || this.disposed) return;
      this.scanOpen = true;
      this.ocrBusy = true;
      this.verified = false;
      this.exceptionVerified = false;
      this._ocrController = new AbortController();
      this.ocrMessage = '';
      this.ocrProgress = 0;
      try {
        if (this.receiptPreview) URL.revokeObjectURL(this.receiptPreview);
        this.receiptPreview = '';
        this.receiptName = file.name || 'Clipboard receipt';
        if (file instanceof Blob && ['image/png', 'image/jpeg', 'image/webp'].includes(file.type) && file.size <= 5 * 1024 * 1024) this.receiptPreview = URL.createObjectURL(file);
        const { recognizePaymentProof } = await import('../services/payment-proof-ocr.js?v=20261023-ux');
        const result = await recognizePaymentProof(file, progress => { if (!this.disposed) this.ocrProgress = progress; }, this._ocrController.signal);
        if (this.disposed) return;
        this.applyExtraction(result);
        this.ocrMessage = Object.values(result).some(Boolean) ? 'Details scanned. Review the fields and verify the account credit.' : 'No payment details found. Enter them manually.';
      } catch (error) { if (!this.disposed) this.ocrMessage = error.message || 'OCR failed. Enter the details manually.'; }
      finally { this.ocrBusy = false; this._ocrController = null; }
    },
    paste(event) {
      const image = [...(event.clipboardData?.items || [])].find(item => item.type.startsWith('image/'));
      if (image) { event.preventDefault(); void this.extract(image.getAsFile()); }
    },
    drop(event) {
      event.preventDefault();
      return this.extract(event.dataTransfer?.files?.[0]);
    },
    cancelScan() { this._ocrController?.abort(); },
    validationError(total) {
      if (!this.settings?.enabled) return 'QR payments are unavailable.';
      if (this.ocrBusy) return 'Wait for OCR to finish.';
      if (!/^\d+(?:\.\d{1,2})?$/.test(String(this.amount)) || Math.round(Number(this.amount) * 100) !== Math.round(total * 100)) return 'Amount Received must exactly match the amount due.';
      if (!this.transactionAt) return 'Enter the transaction date and time.';
      if (this.missingReference) {
        if (!this.isAdmin || this.exceptionReason.trim().length < 20 || !this.exceptionVerified) return 'An administrator must document and verify the missing reference.';
      } else if (!/^[A-Z0-9 -]+$/i.test(this.externalReference) || !/^[A-Z0-9]{6,80}$/i.test(this.externalReference.replace(/[ -]/g, ''))) return 'Enter the official external transaction reference.';
      return this.verified ? '' : 'Verify receipt of funds in the clinic’s official receiving account.';
    },
    payload() {
      return {
        qr_settings_version: this.settings?.version, qr_verified: this.verified,
        external_reference: this.missingReference ? null : this.externalReference,
        transaction_at: this.transactionAt,
        reference_unavailable_reason: this.missingReference ? this.exceptionReason.trim() : null,
        exception_verified: this.missingReference && this.exceptionVerified,
      };
    },
    dispose() {
      this.disposed = true;
      this._ocrController?.abort();
      if (this.imageUrl) URL.revokeObjectURL(this.imageUrl);
      if (this.receiptPreview) URL.revokeObjectURL(this.receiptPreview);
      this.receiptPreview = '';
      this.imageUrl = '';
    },
  };
}
