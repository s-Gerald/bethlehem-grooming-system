// Product-sale snapshots share Bethlehem's print styling, without customer fields.
window.ProductSaleInvoice = {
  logoUrl: new URL('../../assets/images/clinic/Bethlehem_Logo-256.png', document.currentScript.src).href,
  formatPeso: amount => window.PaymentInvoice.formatPeso(amount),
  formatDate: value => new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
  }).format(new Date(value)),
  formatPaidAt: value => new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  }).format(new Date(value)),
  print() { window.PaymentInvoice.print('productSaleInvoice'); },
  template: `<section id="productSaleInvoice">
    <header class="payment-invoice-header">
      <div class="payment-invoice-brand">
        <img :src="window.ProductSaleInvoice.logoUrl" alt="Bethlehem Animal Clinic logo" class="payment-invoice-logo" />
        <div><strong>BETHLEHEM</strong><span>ANIMAL CLINIC</span></div>
      </div>
      <div class="payment-invoice-heading"><h1>INVOICE</h1><span class="payment-invoice-status" x-text="receipt.is_test ? 'Paid · Test' : 'Paid'"></span></div>
    </header>
    <dl class="payment-invoice-meta">
      <div><dt>Transaction reference</dt><dd x-text="receipt.reference"></dd></div>
      <div><dt>Invoice date</dt><dd x-text="window.ProductSaleInvoice.formatDate(receipt.created_at)"></dd></div>
    </dl>
    <section class="payment-invoice-section payment-invoice-products">
      <h2>Products</h2>
      <table class="payment-invoice-table">
        <thead><tr><th scope="col">Description</th><th scope="col">Qty</th><th scope="col">Unit Price</th><th scope="col">Amount</th></tr></thead>
        <tbody>
          <template x-for="line in receipt.items" :key="line.id">
            <tr><td x-text="line.item_name"></td><td x-text="Number(line.quantity)"></td><td x-text="window.ProductSaleInvoice.formatPeso(line.price_at_sale)"></td><td x-text="window.ProductSaleInvoice.formatPeso(line.subtotal)"></td></tr>
          </template>
        </tbody>
      </table>
    </section>
    <dl class="payment-invoice-totals">
      <div><dt>Invoice Total</dt><dd x-text="window.ProductSaleInvoice.formatPeso(receipt.total_amount)"></dd></div>
      <div><dt>Amount Paid</dt><dd x-text="window.ProductSaleInvoice.formatPeso(receipt.total_amount)"></dd></div>
      <div><dt>Balance Due</dt><dd x-text="window.ProductSaleInvoice.formatPeso(0)"></dd></div>
    </dl>
    <section class="payment-invoice-section payment-invoice-details">
      <div class="payment-invoice-details-summary">
        <h2>Payment Details</h2>
        <dl>
          <div><dt>Payment Method</dt><dd x-text="receipt.payment_method_label || 'Cash'"></dd></div>
          <div x-show="(receipt.payment_method || 'cash') === 'cash'"><dt>Cash Received</dt><dd x-text="window.ProductSaleInvoice.formatPeso(receipt.amount_tendered)"></dd></div>
          <div x-show="(receipt.payment_method || 'cash') === 'cash'"><dt>Change</dt><dd x-text="window.ProductSaleInvoice.formatPeso(receipt.change_amount)"></dd></div>
          <div><dt>Processed By</dt><dd x-text="receipt.cashier_name || '—'"></dd></div>
          <div><dt>Paid At</dt><dd x-text="window.ProductSaleInvoice.formatPaidAt(receipt.created_at)"></dd></div>
        </dl>
      </div>
    </section>
  </section>`,
};
