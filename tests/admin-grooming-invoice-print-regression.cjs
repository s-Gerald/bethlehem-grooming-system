const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

const source = fs.readFileSync(path.join(__dirname, "../scripts/components/admin-dashboard.js"), "utf8");
const context = { window: {}, console, Date, Intl };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, "../scripts/components/payment-invoice.js"), "utf8"), context);
vm.runInNewContext(source, context);
const ui = context.adminDashboard();

assert.equal(ui.formatInvoicePeso(4620), "₱4,620.00");
assert.equal(ui.formatInvoicePeso("1050.5"), "₱1,050.50");
assert.equal(ui.formatInvoicePeso(0), "₱0.00");
assert.equal(ui.formatPeso(4620), "₱4620.00", "Shared screen formatting stays unchanged");
assert.equal(ui.formatInvoiceDate("Oct 3, 2026 12:39 AM"), "Oct 3, 2026");
assert.equal(ui.formatInvoiceDate("unavailable recorded timestamp"), "unavailable recorded timestamp");
assert.equal(ui.formatInvoiceDate(""), "", "Do not invent a timestamp");

const product = { itemName: "Royal Canin Fit 32", quantity: 2, subtotal: 2320 };
const originalProduct = JSON.stringify(product);
assert.equal(ui.formatInvoiceProductUnitPrice(product), "₱1,160.00");
assert.equal(ui.formatInvoiceProductUnitPrice({ quantity: 3, subtotal: 100 }), "₱33.33");
for (const quantity of [0, -1, "invalid", Infinity, undefined]) {
  assert.equal(ui.formatInvoiceProductUnitPrice({ quantity, subtotal: 2320 }), "—");
}
assert.equal(JSON.stringify(product), originalProduct, "Unit price formatting must not mutate product records");

const html = fs.readFileSync(path.join(__dirname, "../pages/admin/appointments.html"), "utf8");
assert.ok(html.includes('x-html="window.PaymentInvoice.template"'), "Grooming uses the shared invoice template");
const invoice = context.window.PaymentInvoice.template;
assert.ok(invoice.includes('Grooming reference'));
assert.doesNotMatch(invoice, /Booking reference|Appointment/i);
const settledPayment = { finalPrice: 850, amountPaid: 1000, change: 150 };
for (const [label, expected] of [
  ["Invoice total", "₱850.00"],
  ["Amount paid", "₱850.00"],
  ["Balance due", "₱0.00"],
  ["Cash received", "₱1,000.00"],
  ["Change", "₱150.00"],
]) {
  const expression = invoice.match(new RegExp(`<dt>${label}</dt><dd x-text="([^"]+)"`))[1];
  assert.equal(
    new Function("receiptModal", "formatInvoicePeso", `return ${expression}`)(settledPayment, ui.formatInvoicePeso),
    expected,
    `${label} must use its recorded financial meaning`,
  );
}

const printingClasses = new Set();
const listeners = new Map();
let printed = 0;
let removed = 0;
let fallback;
let appended;
const clone = {
  setAttribute: (name) => assert.equal(name, "x-ignore", "The print snapshot must not reinitialize Alpine bindings"),
  classList: { add: (value) => printingClasses.add(value) },
  remove: () => removed++,
};
context.document = {
  getElementById(id) {
    assert.equal(id, "paymentInvoice");
    return { cloneNode(deep) { assert.equal(deep, true); return clone; } };
  },
  body: {
    appendChild: (value) => { appended = value; },
    classList: { add: (value) => printingClasses.add(value), remove: (value) => printingClasses.delete(value) },
  },
};
Object.assign(context.window, {
  addEventListener: (name, callback) => listeners.set(name, callback),
  removeEventListener: (name) => listeners.delete(name),
  print() {
    assert.equal(appended, clone, "Print the recorded invoice clone");
    assert.ok(printingClasses.has("payment-invoice-printing"));
    assert.ok(printingClasses.has("payment-invoice-print-clone"));
    printed++;
  },
  setTimeout: (callback) => { fallback = callback; },
});
ui.printReceipt();
listeners.get("afterprint")();
fallback();
assert.equal(printed, 1);
assert.equal(removed, 1, "Cleanup stays safe when both afterprint and the fallback run");
assert.ok(!printingClasses.has("payment-invoice-printing"));
assert.ok(!listeners.has("afterprint"));

ui.printReceipt();
fallback();
assert.equal(removed, 2, "Fallback cleans up when afterprint is unavailable");
assert.ok(!printingClasses.has("payment-invoice-printing"));
console.log("Admin grooming invoice print regression tests passed.");
