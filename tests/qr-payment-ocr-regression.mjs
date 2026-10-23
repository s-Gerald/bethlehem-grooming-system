import assert from 'node:assert/strict';
import path from 'node:path';
import os from 'node:os';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
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
assert.equal(parsePaymentReceipt('Date: 02/03/2026 10:00').transactionAt, '', 'Ambiguous numeric dates remain unknown');
assert.equal(parsePaymentReceipt('2026-02-30 12:00').transactionAt, '', 'Invalid dates cannot roll into another month');
// Sanitized receipt text: payment IDs must not be confused with biller/processor IDs.
for (const [text, expected] of [
    ['GCash\nBills Payment Successful\nReference Number (8–20 character)\nBILL998877\nGCash Reference No.\n1234 5678 9012\nECPay Reference No. ECP998877', '123456789012'],
    ['Maya\nMaya Reference No.: MAYA-765432\nCustomer Reference No.: CUST998877', 'MAYA765432'],
    ['InstaPay Transfer\nGCash Reference No. GCASH998877\nInstaPay Reference No. BANK-765432', 'BANK765432'],
    ['QRPH Payment\nQRPH Reference: QRPH-765432', 'QRPH765432'],
    ['Bank Transfer\nTransaction Reference\nBANK-765432', 'BANK765432'],
    ['GCash Reference No.    ECPay Reference No.\n123456789012           ECP998877', '123456789012'],
    ['GCash Reference No.    ECPay Reference No.\n123456789012 ECP998877', ''],
    ['GCash Reference No. | 123456789012', '123456789012'],
    ['Billing Reference No. BILL998877\nCustomer ID CUST998877\nAccount Number 1234567890', ''],
    ['Reference Number (8-20 character): BILL998877', ''],
    ['Reference Number\nPayment successful', ''],
    ['Reference Number\nTransaction ID BANK765432', 'BANK765432'],
]) assert.equal(parsePaymentReceipt(text).externalReference, expected, text);
const ambiguous = parsePaymentReceipt('Transaction ID: BANK111111\nRef. No.: BANK222222');
assert.equal(ambiguous.externalReference, '', 'Ambiguous references need staff review');
assert.deepEqual(ambiguous.referenceCandidates.map(candidate => candidate.value), ['BANK111111', 'BANK222222']);
assert.equal(parsePaymentReceipt('Ref. No.: BANK111111\nTransaction ID: BANK111111').externalReference, 'BANK111111');
assert.equal(parsePaymentReceipt('GCash\nReference No. GCASH111111\nGCash Reference No. GCASH111111\nTransaction ID BANK222222').externalReference, 'GCASH111111', 'Repeated generic labels must not hide a clear provider label');
assert.equal(parsePaymentReceipt('2026-10-22 12:00\n2026-10-23 13:00').transactionAt, '', 'Conflicting receipt timestamps remain unknown');
assert.equal(parsePaymentReceipt('2026-10-23').transactionAt, '', 'Date-only receipts cannot invent a time');
assert.equal(parsePaymentReceipt('Oct 23, 2026 10:30:45 PM').transactionAt, '2026-10-23T22:30', 'Seconds must not hide a PM marker');
assert.equal(parsePaymentReceipt('2026-10-22T17:30:00Z').transactionAt, '2026-10-23T01:30', 'Explicit receipt zones convert to Philippine time');
await assert.rejects(recognizePaymentProof({type: 'text/html', size: 20}), /PNG|JPEG|WebP/);
await assert.rejects(recognizePaymentProof({type: 'image/png', size: 6000000}), /5 MB/);

globalThis.API = { getUserRole: () => 'staff', getQrPaymentConfig: async () => ({ settings: {enabled: true, version: 2, accepted_methods: ['GCash', 'Maya', 'Bank Transfer']} }) };
const qr = createQrPayment();
await qr.load();
assert.equal(qr.settings.version, 2);
assert.ok(qr.validationError(850), 'A payment reference and explicit verification are required');
Object.assign(qr, { paymentSource: 'Bank Transfer', externalReference: '1234-ABCD', verified: true });
assert.equal(qr.validationError(850), '');
assert.equal(qr.payload().transaction_at, null, 'Manual confirmation leaves the original transaction time unknown');
assert.equal(qr.payload().external_reference, '1234-ABCD', 'Preserve staff input for server normalization');
assert.equal(qr.payload().qr_verified, true);
qr.applyExtraction({payingApp: 'Maya'});
assert.equal(qr.paymentSource, 'Bank Transfer', 'OCR never replaces the staff selection');
assert.equal(qr.payload().payment_source, 'Bank Transfer');
assert.equal(qr.verified, false, 'OCR never confirms verification');
qr.verified = true;
assert.equal(Object.hasOwn(qr.payload(), 'verification_source'), false, 'The client does not select an audit source');
assert.equal(Object.hasOwn(qr, 'verificationSource'), false, 'No obsolete source-selection state remains');
qr.verified = false;
assert.match(qr.validationError(850), /Verify receipt/, 'Account verification remains mandatory for a complete manual entry');
qr.verified = true;
const paymentHtml = readFileSync(new URL('../pages/admin/appointments.html', import.meta.url), 'utf8')
    .match(/<template x-if="paymentModal.qr && paymentModal.paymentMethod === 'qrph'">([\s\S]*?)<\/section>/)[1];
assert.doesNotMatch(paymentHtml, /Verified using|verificationSource|verification_source|Transaction History|Payment Notification/, 'The payment form has no source selector, hidden or visible');
assert.match(paymentHtml, /x-model="paymentModal.qr.verified"/, 'The receiving-account checkbox stays bound');
qr.amount = '850.00';
assert.ok(qr.validationError(850.01));
assert.equal(qr.verified, true, 'Validation alone does not silently edit acknowledgment');
qr.amount = '850.001';
assert.ok(qr.validationError(850));
qr.amount = '850.00';
qr.externalReference = '';
assert.ok(qr.validationError(850), 'Staff cannot bypass a missing reference');
qr.setExpectedAmount(850);
qr.verified = true;
qr.setExpectedAmount(900);
assert.equal(qr.verified, false, 'Changing Total Due invalidates the receiving-account acknowledgment');
const extractedAmount = createQrPayment();
extractedAmount.settings = {enabled: true, accepted_methods: ['GCash']};
extractedAmount.paymentSource = 'GCash';
extractedAmount.applyExtraction({amount: '849.00'});
Object.assign(extractedAmount, {amount: '', externalReference: 'VALID1234', verified: true});
assert.ok(extractedAmount.validationError(850), 'Clearing an extracted amount cannot bypass discrepancy handling');
assert.equal(parsePaymentReceipt('GCash\nSent to Maya').payingApp, '', 'Recipient or brand names never establish a paying app');
assert.equal(parsePaymentReceipt('Paid using: Maya').payingApp, 'Maya');
assert.equal(parsePaymentReceipt('Paying app: GCash\nPaid using: Maya').payingApp, '', 'Conflicting app details stay unspecified');
const removed = createQrPayment();
removed.externalReference = 'MANUAL1234';
removed.applyExtraction({amount: '849.00', transactionAt: '2026-10-23T10:30', recipient: 'Bethlehem'});
removed.externalReference = 'EDITED1234';
removed.reviewField('externalReference');
removed.removeReceipt();
assert.equal(removed.amount, '');
assert.equal(removed.transactionAt, '');
assert.equal(removed.externalReference, 'EDITED1234', 'Receipt removal preserves manually reviewed fields');
assert.equal(removed.receiptFile, null);
assert.equal(removed.verified, false);
assert.equal(removed.payload().reference_unavailable_reason, undefined);
assert.equal(removed.payload().exception_verified, undefined);
const replacement = createQrPayment();
replacement.applyExtraction({externalReference: 'OLD123456', transactionAt: '2026-10-22T10:00'});
replacement.verified = true;
replacement.removeReceipt();
replacement.applyExtraction({externalReference: 'NEW123456'});
assert.equal(replacement.externalReference, 'NEW123456', 'Replacing a verified receipt clears its unreviewed suggestions');
assert.equal(replacement.transactionAt, '', 'The previous receipt time cannot leak into a replacement');
const disabled = createQrPayment();
API.getQrPaymentConfig = async () => { throw new Error('Config unavailable'); };
await disabled.load();
assert.equal(disabled.settings, null);
assert.match(disabled.error, /unavailable/);
assert.ok(disabled.validationError(850));
const manual = createQrPayment();
manual.amount = '850.00';
manual.externalReference = 'MANUAL1234';
manual.applyExtraction({amount: '', externalReference: '', transactionAt: '', recipient: 'Other Account'});
assert.equal(manual.amount, '850.00', 'Partial OCR must not erase an existing amount');
assert.equal(manual.externalReference, 'MANUAL1234');
manual.applyExtraction({externalReference: 'OCR999999', referenceCandidates: ambiguous.referenceCandidates});
assert.equal(manual.externalReference, 'MANUAL1234', 'OCR suggestions preserve manual reference edits');
manual.selectReference(ambiguous.referenceCandidates[1]);
assert.equal(manual.externalReference, 'BANK222222', 'Staff can choose an ambiguous reference');
assert.equal(manual.verified, false, 'Choosing a reference cannot verify funds');
manual.settings = {merchant_name: 'Bethlehem Animal Clinic'};
assert.match(manual.recipientWarning, /recipient/i);
manual.recipient = 'BETHLEHEM ANIMAL CLINIC';
assert.equal(manual.recipientWarning, '');
let uploaded;
manual.extract = async file => { uploaded = file; };
const dropped = new Blob(['receipt'], {type: 'image/png'});
let prevented = false;
manual.drop({preventDefault() {prevented = true;}, dataTransfer: {files: [dropped]}});
assert.equal(uploaded, dropped); assert.equal(prevented, true);
manual.paste({preventDefault() {}, clipboardData: {items: [{type: 'image/png', getAsFile: () => dropped}]}});
assert.equal(uploaded, dropped);
manual._ocrController = new AbortController();
manual.cancelScan();
assert.equal(manual._ocrController.signal.aborted, true, 'Staff can stop a scan and return to manual entry');
delete manual.extract;
manual.extract = createQrPayment().extract;
await manual.extract({type: 'text/html', size: 10});
assert.equal(manual.amount, '850.00', 'OCR failure preserves manual entries');
assert.equal(manual.ocrBusy, false, 'OCR failure leaves the form usable');
assert.equal(manual.scanOpen, true, 'Receipt processing feedback is visible even after paste');
assert.match(manual.ocrMessage, /PNG|JPEG|WebP/);
assert.equal(manual.verified, false);
globalThis.createImageBitmap = async () => { throw new Error('corrupt image'); };
await assert.rejects(recognizePaymentProof({type: 'image/png', size: 20}), /could not be read/);
delete globalThis.createImageBitmap;
const preview = createQrPayment();
await preview.extract(dropped);
const temporaryPreview = preview.receiptPreview;
assert.match(temporaryPreview, /^blob:/);
assert.equal(preview.amount, '', 'Selecting a receipt must never copy Total Due into Amount Received');
preview.dispose();
assert.equal(preview.receiptPreview, '');
await assert.rejects(fetch(temporaryPreview), /fetch failed/, 'Closing the modal releases the local receipt URL');
const cancellation = new AbortController();
cancellation.abort();
await assert.rejects(recognizePaymentProof({type: 'image/png', size: 20}, undefined, cancellation.signal), /cancel/i);
const closed = createQrPayment();
closed.dispose();
await closed.extract({type: 'image/png', size: 20});
assert.equal(closed.ocrMessage, '', 'Closing the modal must prevent later OCR work');

// Control only the slow recognition boundary to exercise completion races in the real component.
const jobs = [];
const sandbox = vm.createContext({Blob, URL, AbortController, API: globalThis.API});
const recognizer = new vm.SyntheticModule(['recognizePaymentProof'], function () {
    this.setExport('recognizePaymentProof', (_file, progress, signal) => new Promise(resolve => jobs.push({resolve, progress, signal})));
}, {context: sandbox});
await recognizer.link(() => {}); await recognizer.evaluate();
const component = new vm.SourceTextModule(readFileSync(new URL('../scripts/components/qr-payment.js', import.meta.url), 'utf8'), {
    context: sandbox, importModuleDynamically: () => recognizer,
});
await component.link(() => {}); await component.evaluate();
const racing = component.namespace.createQrPayment();
const firstScan = racing.extract(new Blob(['first'], {type: 'image/png'}));
await new Promise(setImmediate);
assert.equal(jobs.length, 1);
racing.removeReceipt();
assert.equal(jobs[0].signal.aborted, true);
assert.equal(racing.ocrBusy, false);
jobs[0].progress(99);
jobs[0].resolve({amount: '900.00', externalReference: 'STALE1234'});
await firstScan;
assert.equal(racing.amount, ''); assert.equal(racing.externalReference, ''); assert.equal(racing.ocrProgress, 0);
const oldScan = racing.extract(new Blob(['old'], {type: 'image/png'}));
await new Promise(setImmediate);
const newScan = racing.extract(new Blob(['new'], {type: 'image/png'}));
await new Promise(setImmediate);
assert.equal(jobs[1].signal.aborted, true);
jobs[1].resolve({amount: '111.00'}); await oldScan;
assert.equal(racing.ocrBusy, true, 'An old scan cannot clear the new scan loading state');
jobs[2].resolve({amount: '850.00', externalReference: 'CURRENT1234'}); await newScan;
assert.equal(racing.amount, '850.00'); assert.equal(racing.externalReference, 'CURRENT1234');
assert.equal(racing.ocrBusy, false);
racing.externalReference = 'CORRECTED1234'; racing.reviewField('externalReference');
racing.removeReceipt();
assert.equal(racing.externalReference, 'CORRECTED1234'); assert.equal(racing.amount, '');
const editedScan = racing.extract(new Blob(['edited'], {type: 'image/png'}));
await new Promise(setImmediate);
racing.externalReference = 'TYPED123456'; racing.reviewField('externalReference');
jobs[3].resolve({externalReference: 'OCR123456'}); await editedScan;
assert.equal(racing.externalReference, 'TYPED123456', 'A scan completing after a manual edit cannot overwrite it');
const clearedScan = racing.extract(new Blob(['cleared'], {type: 'image/png'}));
await new Promise(setImmediate);
racing.externalReference = ''; racing.reviewField('externalReference');
jobs[4].resolve({externalReference: 'OCR654321'}); await clearedScan;
assert.equal(racing.externalReference, '', 'An explicitly cleared field stays cleared when OCR completes');
racing.dispose();

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
    const {createCanvas, loadImage} = await import('@napi-rs/canvas');
    await worker.setParameters({preserve_interword_spaces: '1'});
    for (const [provider, lines, expected] of [
        ['GCash', ['Reference Number (8-20 character)', 'BILL998877', 'GCash Reference No.', '123456789012', 'ECPay Reference No.', 'ECP998877'], '123456789012'],
        ['Maya', ['Customer Reference No. CUST998877', 'Maya Reference No.', 'MAYA765432'], 'MAYA765432'],
    ]) {
        const image = createCanvas(1000, 700), ctx = image.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, image.width, image.height);
        ctx.fillStyle = '#111'; ctx.font = '28px Arial';
        [provider, 'Payment Successful', 'Amount Paid PHP 850.00', ...lines].forEach((line, i) => ctx.fillText(line, 40, 45 + i * 62));
        const {data} = await worker.recognize(image.toBuffer('image/png'));
        assert.equal(parsePaymentReceipt(data.text).externalReference, expected, `${provider} multiple-reference image: ${data.text}`);
    }
    globalThis.document = {createElement: () => createCanvas(1, 1)};
    const {readPdfReceipt} = await import('../scripts/services/payment-receipt-pdf.js');
    const receipt = await loadImage(path.resolve('tests/Fixtures/qr-payment-receipt.png'));
    const bitmap = createCanvas(receipt.width, receipt.height);
    bitmap.getContext('2d').drawImage(receipt, 0, 0);
    const jpeg = bitmap.toBuffer('image/jpeg');
    function receiptPdf(pages) {
        const stream = `q ${receipt.width} 0 0 ${receipt.height} 0 0 cm /Receipt Do Q`;
        const objects = ['<< /Type /Catalog /Pages 2 0 R >>', `<< /Type /Pages /Kids [${Array.from({length: pages}, (_,i) => `${5+i} 0 R`).join(' ')}] /Count ${pages} >>`,
            Buffer.concat([Buffer.from(`<< /Type /XObject /Subtype /Image /Width ${receipt.width} /Height ${receipt.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`), jpeg, Buffer.from('\nendstream')]),
            `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`,
            ...Array.from({length: pages}, () => `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${receipt.width} ${receipt.height}] /Resources << /XObject << /Receipt 3 0 R >> >> /Contents 4 0 R >>`)];
        let pdf = Buffer.from('%PDF-1.4\n'), offsets = [0];
        for (const [i, object] of objects.entries()) { offsets.push(pdf.length); pdf = Buffer.concat([pdf, Buffer.from(`${i+1} 0 obj\n`), Buffer.from(object), Buffer.from('\nendobj\n')]); }
        const xref = pdf.length;
        const trailer = `xref\n0 ${objects.length+1}\n0000000000 65535 f \n` + offsets.slice(1).map(offset => `${String(offset).padStart(10,'0')} 00000 n \n`).join('')
            + `trailer\n<< /Size ${objects.length+1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
        pdf = Buffer.concat([pdf, Buffer.from(trailer)]);
        return new Blob([pdf], {type: 'application/pdf'});
    }
    let renderedPages = 0;
    const pdfText = await readPdfReceipt(receiptPdf(2), async canvas => {
        renderedPages++;
        assert.ok(canvas.width * canvas.height <= 4000000);
        const {data} = await worker.recognize(canvas.toBuffer('image/png'));
        return data.text;
    });
    assert.equal(renderedPages, 2);
    assert.equal(parsePaymentReceipt(pdfText).amount, '850.00', 'Full PDF pages run through the actual OCR engine');
    assert.equal(parsePaymentReceipt(pdfText).externalReference, 'BANK123456');
    assert.equal(parsePaymentReceipt(pdfText).transactionAt, '2026-10-23T10:30');
    await assert.rejects(readPdfReceipt(receiptPdf(3), () => {throw new Error('must not recognize');}), /2 pages/);
    const pdfCancellation = new AbortController();
    await assert.rejects(readPdfReceipt(receiptPdf(2), async () => { pdfCancellation.abort(); return ''; }, pdfCancellation.signal), /cancel/i);
    await assert.rejects(readPdfReceipt({size: 6 * 1024 * 1024}, () => ''), /5 MB/);
    await assert.rejects(readPdfReceipt(new Blob(['not a pdf'], {type: 'application/pdf'}), () => ''), /PDF/);
    delete globalThis.document;
} finally { await worker.terminate(); }

console.log('Actual local OCR engine, receipt parsing, errors and verification UI regression checks passed.');
