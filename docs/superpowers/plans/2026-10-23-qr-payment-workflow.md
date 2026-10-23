# QR Payment Workflow Implementation Plan

**Goal:** Refine Grooming QR payments, optional private proof storage, provider configuration and transaction presentation.

**Architecture:** Extend existing verification, settlement, OCR and settings code. Upload validation and private storage happen before settlement locks; attachment metadata commits atomically with the QR record. Failed settlements remove uploads; scheduled cleanup removes only old unreferenced files. Existing records and migrations stay intact.

**Tech Stack:** Laravel 12, vanilla JavaScript/Alpine, existing OCR/PDF.js, PHPUnit and Node regressions.

**Spec:** User attachment `79466c47-2b73-461a-b1b2-d9ab516604f9/Pasted text.txt`.

## Constraints

- No commits, deployment, private environment changes, actual transfers or manual browser workflow tests.
- Cash, legacy gateways, stock integrity, early/final settlement and uniqueness remain supported.
- No Clinic/POS QR collection, extra payment choices, polling or eager proof downloads.
- Preserve existing Bethlehem styling, QR cropping, configuration loading and version protections.

## Review Focus

- OCR removal/replacement must invalidate in-flight results and preserve independent manual edits.
- Missing references must fail even for administrators; historical exceptions remain readable.
- Attachment rejection or settlement failure must leave no new financial record or retained upload.
- Authorized proof access must not expose paths or permit arbitrary file IDs/paths.
- Expected totals must never count as evidence of received funds.

## Implementation

1. Update existing `tests/qr-payment-ocr-regression.mjs` and `tests/Feature/GroomingPaymentPriceSafetyTest.php` for optional amount entry, mismatch rejection, mandatory reference and removal races. Run focused tests and observe expected failures.
2. Modify `scripts/components/qr-payment.js`, `scripts/components/admin-dashboard.js`, `pages/admin/appointments.html`, `scripts/api.js` and existing OCR service. Keep extraction optional, invalidate stale jobs, track manual edits, send optional receipt with the existing settlement request.
3. Add forward QR metadata migration, `QrPaymentProofService` and protected proof controller. Extend existing `PaymentController`, `QrPaymentVerificationService`, `QrPayment`, routes and scheduled cleanup. Validate files outside locks, persist metadata inside settlement and clean failed/orphan uploads. Add real storage/auth/rollback tests to existing payment tests.
4. Add one provider catalog in configuration, expose it through existing Settings response and reuse it for backend validation. Modify existing settings JS/HTML and settings tests; preserve legacy saved strings and provide explicit Other fallback.
5. Modify existing success modal and transaction detail HTML/JS. Show external reference prominently, format Philippine timestamps, keep audit times in expandable details and fetch proof only on request. Extend existing transaction/receipt regressions.
6. Run focused tests, isolated MySQL coverage, `php artisan test --compact`, `npm test`, `npm run build`, syntax/diff checks. Document storage retention, cleanup, migration and PDF validator deployment requirements in `docs/qr-payments.md`.

No existing production files are planned for deletion. Remove obsolete exception state, payloads, validation branches and assertions in place.
