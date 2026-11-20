# Clinic Manual treatment deployment

This change is based on `bethlehem-grooming-system` / `gerald-frontend` at `af126e43`. It does not change dependency versions or global payment limits.

## Deploy or redeploy

Use the application's existing Hostinger web/CLI PHP configuration and deployment process. Take the normal database backup, then prevent writes while deploying the PHP files, static HTML/JavaScript and migration together:

```sh
php artisan down
php artisan migrate --force
php artisan optimize:clear
php artisan up
```

Run the existing production asset build (`npm ci` / `npm run build`) in the established build environment and deploy its output as usual. The Clinic and Clients pages version the changed treatment scripts so a redeploy fetches the new presentation and form behavior. No additional environment variables, services or queues are required.

Migration `2026_11_20_000001_add_explicit_treatment_source_and_manual_price` adds `record_source VARCHAR(20)` and nullable `manual_price DECIMAL(8,2)` to `vaccination_records`. Existing inventory-linked rows become `inventory`; all other existing rows remain `historical`. No prices, charges, clinical facts or stock movements are backfilled. The conservative database default remains `historical`; the current-entry API explicitly saves `manual` or `inventory`.

Do not run the migration's `down()` on a production database containing new Manual treatments: dropping the source and price columns loses their financial/clinical traceability. Prefer a forward correction; restore the complete predeployment backup if reverting both application and database is necessary.

## Verify the deployed workflow

1. Open an active consultation. Manual treatment is the default; the new-entry dropdown contains only Manual treatment and Bethlehem inventory.
2. Add Manual medicine with dose `0.8 mL`, Price `350.50`, and Product Expiration Date on or after Administration Date. Both Manual Vaccine and Medicine require expiration on Add Treatment, retain it in history, and show the red required asterisk. Billing must immediately contain one linked ₱350.50 charge. Edit the price and then remove an unfinalized treatment; each operation updates Billing and the case total.
3. Add an inventory medicine and vaccine. Their expiration must come from a usable Inventory/FEFO batch; undated stock requires Inventory stock/batch review. Treatment must not expose an editable inventory expiration field. Each has its authoritative single-treatment charge immediately; stock remains unchanged until finalization. A changed vaccine price, medicine price or case weight must require treatment review before finalization.
4. Save an incomplete Manual draft without Price or Product Expiration Date. It has no charge and blocks finalization. Existing active drafts missing expiration also require correction; published Historical / external records remain readable without it. Complete the clinical details, Price and expiration, then send the case to payment; verify stock use only for inventory treatments and no duplicate charges. This expiration validation uses the existing nullable date column and requires no additional migration.
5. Confirm cash/online payment, invoice and completed patient/case history use the stored `clinic_charges`. Manual history includes source, price and clinical details; legacy external records still say Historical / external and remain unbilled.

## Local verification

Use the PHP 8.4 test CLI documented in README.md. Focused Clinic suites cover treatment validation/save/update/delete, finalization, migration preservation, staff/client history, inventory pricing, services, confinement and cash/PayMongo payments. Node Clinic regressions cover the actual scoped Billing event handler, totals, Manual money input and legacy presentation.

The standalone local-only schema smoke test is:

```sh
php tests/clinic-treatment-migration-mysql.php
```

It requires `pdo_mysql` and local XAMPP MariaDB at `127.0.0.1:3306` using the local root test account with an empty password. It does not load application configuration or credentials. It creates a random token-owned schema, exercises the actual Laravel migration, verifies source backfill/unchanged clinical facts/decimal precision/rollback, and removes only its own schema. It is not a production command.

Verified locally on MariaDB 10.4.32. Automated Clinic PHP suites, Clinic Node regressions and the production build pass. Visual QA uses an isolated static form fixture at 320, 375, 414 and 768px; payment-provider requests in automated tests are faked rather than live charges.
