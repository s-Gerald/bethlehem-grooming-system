# QR Ph Payments Implementation Plan

**Goal:** Add verified static QR Ph collection to Grooming final and early payments, preserving Cash and historical gateway records.

**Architecture:** Reuse GroomingPaymentSettlementService inside the existing booking transaction. QrPaymentSettings owns one versioned private QR image; QrPaymentVerificationService validates and records immutable verification metadata on a polymorphic QrPayment, linked to Payment. Browser OCR is optional and on demand; receipt screenshots are neither uploaded nor retained.

**Tech stack:** Laravel 12, existing Alpine/JavaScript, self-hosted Tesseract.js worker and English model.

**Spec:** User attachment `491d6744-755d-4daa-9157-96e08e179970/Pasted text.txt`.

## Constraints

- Grooming collection only; no Clinic/POS endpoints, gateway checkout, provider polling, commits, deployments, or private environment edits.
- Server calculates totals, verifies exact received amount, checks current configuration, and requires official-account acknowledgment.
- References normalize to uppercase alphanumeric; unique database constraints arbitrate races. No-reference exception requires administrator, reason, official transaction timestamp and additional acknowledgment.
- Preserve existing booking/inventory locks, service validation, Cash printing, pagination, and exclusion of historical gateway test collections.
- QR images are authenticated private files (PNG/JPEG/WebP, 3 MB, bounded dimensions). Administrators attest uploaded code is the official account QR; image validation cannot establish ownership or bank settlement.
- No receipt screenshot retention; OCR never verifies funds. Explicitly document deployment persistence for private QR storage.
- Browser inspection is visual/read-only; user owns manual navigation and data-entry testing.

## Review focus

- Rotated/disabled QR configuration rejects stale confirmation without settlement.
- Normalized duplicate references and repeat visits roll back stock, price and notification writes.
- Failed OCR permits manual entry and never sets acknowledgment.
- A payment error remains retryable after field edits; stale asynchronous work cannot update a closed modal.
- QR success/history cannot generate invoices; Cash still can.

## Tasks

1. **Configuration and verification backend**
   - Add migration `2026_10_23_000001_create_qr_payments.php`, models QrPaymentSettings/QrPayment, controllers QrPaymentSettingController, service QrPaymentVerificationService, and authorized routes.
   - Extend existing GroomingPaymentPriceSafetyTest and TransactionHistorySearchTest; add QrPaymentSettingsTest covering permissions, file validation, versioning and image access.
   - Watch focused QR tests fail before implementation, then pass.
2. **Shared settlement and history**
   - Extend PaymentController, Payment relation and settlement inputs; retain shared settlement and store verified metadata atomically. Extend transaction serialization with a batch-loaded QR relation.
   - Verify final/early payment, exact total, duplicate reference, missing reference exception, invalid verification, repeat submission, rollback, product stock, financial totals and legacy records.
3. **Settings, payment UI and OCR**
   - Extend scripts/api.js, admin-settings.js, admin-dashboard.js, admin-transactions.js and the three corresponding HTML pages.
   - Add payment-proof-ocr.js (engine lifecycle and receipt parsing), qr-payment.js (modal state/validation), and on-demand settings component; package pinned OCR resources locally via build script.
   - Extend existing JS payment/history/invoice regression scripts and add focused parser/engine failure tests. Verify OCR against controlled receipt image using the actual engine without localhost interaction.
4. **Validation and handoff**
   - Run focused tests, `php artisan test --compact`, `npm test`, `npm run build`.
   - Inspect diff and report files, migrations, configuration, tests and practical limits. Do not apply migrations to the user's active database implicitly; provide the command.

The user's AGENTS.md makes writing-plans advisory; implement in this session without an approval gate, worktree, or commit.

## Completion

- [x] Central configuration, private image access and verification backend.
- [x] Shared settlement, history metadata, unique-reference and stock integrity checks.
- [x] Settings/payment/success/history UI and functional on-demand local OCR.
- [x] Full Laravel suite: 1,473 passed, 8 existing integration skips.
- [x] JavaScript suite: 74/74 scripts passed; production build passed.
- [x] Two-process confirmation race and actual OCR engine fixture passed.
- [x] File inventory, activation steps and limitations documented in `docs/qr-payments.md`.
