import assert from 'node:assert/strict';
import path from 'node:path';
import os from 'node:os';
import { createWorker } from 'tesseract.js';

const { parsePaymentReceipt, recognizePaymentProof } = await import('../scripts/services/payment-proof-ocr.js');
const { createQrPayment } = await import('../scripts/components/qr-payment.js');

for (const [text, amount, reference, transactionAt, recipient] of [
    ['GCash\nPayment Successful\nAmount Paid\nPHP 850.00\nPaid to Bethlehem Animal Clinic\nRef. No. 1234 5678 9012\nOct 23, 2026 10:30 AM', '850.00', '123456789012', '2026-10-23T10:30', 'Bethlehem Animal Clinic'],
    ['Maya\nSent to: Bethlehem Clinic\nTotal Amount: ₱1,250.50\nTransaction ID: MAYA-1023-ABCD\n2026-10-23 14:05', '1250.50', 'MAYA1023ABCD', '2026-10-23T14:05', 'Bethlehem Clinic'],
    ['Bank transfer\nRecipient\nBETHLEHEM\nAmount\nPHP 750.00\nFee PHP 15.00\nReference Number\nBANK 1234-AB\n23/10/2026 09:15', '750.00', 'BANK1234AB', '2026-10-23T09:15', 'BETHLEHEM'],
]) {
    const result = parsePaymentReceipt(text);
    assert.equal(result.amount, amount);
    assert.equal(result.externalReference, reference);
    assert.equal(result.transactionAt, transactionAt);
    assert.equal(result.recipient, recipient);
    assert.equal(result.verified, undefined, 'OCR never declares funds verified');
}
for (const text of ['Successful', 'Paid', 'Balance PHP 850.00\nFee PHP 15.00', 'Total amount PHP 850.00\nTotal amount PHP 950.00']) {
    assert.equal(parsePaymentReceipt(text).amount, '', 'No fabricated amount from statuses, fees, balances or ambiguous amounts');
}
assert.equal(parsePaymentReceipt('Date: 02/03/2026 10:00').transactionAt, '', 'Ambiguous numeric dates need manual entry');
assert.equal(parsePaymentReceipt('2026-02-30 12:00').transactionAt, '', 'Invalid dates cannot roll into another month');
await assert.rejects(recognizePaymentProof({type: 'text/html', size: 20}), /PNG|JPEG|WebP/);
await assert.rejects(recognizePaymentProof({type: 'image/png', size: 6000000}), /5 MB/);

globalThis.API = { getUserRole: () => 'staff', getQrPaymentConfig: async () => ({ settings: {enabled: true, version: 2} }) };
const qr = createQrPayment();
await qr.load();
assert.equal(qr.settings.version, 2);
assert.ok(qr.validationError(850), 'Amount and explicit verification are required');
Object.assign(qr, { amount: '850.00', externalReference: '1234-ABCD', transactionAt: '2026-10-23T10:30', verified: true });
assert.equal(qr.validationError(850), '');
assert.equal(qr.payload().external_reference, '1234-ABCD', 'Preserve staff input for server normalization');
assert.equal(qr.payload().qr_verified, true);
assert.ok(qr.validationError(850.01));
assert.equal(qr.verified, true, 'Validation alone does not silently edit acknowledgment');
qr.amount = '850.001';
assert.ok(qr.validationError(850));
qr.amount = '850.00';
qr.externalReference = '';
assert.ok(qr.validationError(850), 'Staff cannot bypass a missing reference');
const disabled = createQrPayment();
API.getQrPaymentConfig = async () => { throw new Error('Config unavailable'); };
await disabled.load();
assert.equal(disabled.settings, null);
assert.match(disabled.error, /unavailable/);
assert.ok(disabled.validationError(850));
const manual = createQrPayment();
manual.amount = '850.00';
await manual.extract({type: 'text/html', size: 10});
assert.equal(manual.amount, '850.00', 'OCR failure preserves manual entries');
assert.equal(manual.ocrBusy, false, 'OCR failure leaves the form usable');
assert.match(manual.ocrMessage, /PNG|JPEG|WebP/);
assert.equal(manual.verified, false);
globalThis.createImageBitmap = async () => { throw new Error('corrupt image'); };
await assert.rejects(recognizePaymentProof({type: 'image/png', size: 20}), /could not be read/);
delete globalThis.createImageBitmap;
const cancellation = new AbortController();
cancellation.abort();
await assert.rejects(recognizePaymentProof({type: 'image/png', size: 20}, undefined, cancellation.signal), /cancel/i);
const closed = createQrPayment();
closed.dispose();
await closed.extract({type: 'image/png', size: 20});
assert.equal(closed.ocrMessage, '', 'Closing the modal must prevent later OCR work');

// Exercise the actual installed OCR engine and local model against synthetic data.
const worker = await createWorker('eng', 1, {
    langPath: path.resolve('node_modules/@tesseract.js-data/eng/4.0.0_best_int'),
    cachePath: os.tmpdir(), errorHandler: () => {},
});
try {
    const { data } = await worker.recognize(path.resolve('tests/Fixtures/qr-payment-receipt.png'));
    const result = parsePaymentReceipt(data.text);
    assert.equal(result.amount, '850.00');
    assert.equal(result.externalReference, 'BANK123456');
    assert.equal(result.transactionAt, '2026-10-23T10:30');
    assert.match(result.recipient, /Bethlehem Animal Clinic/);
} finally { await worker.terminate(); }

console.log('Actual local OCR engine, receipt parsing, errors and verification UI regression checks passed.');
