# Smart Product Dropdowns Implementation Plan

**Goal:** Provide the same compact accessible autocomplete in Grooming payments, POS and Clinic treatments, preserving existing payment, medical and phone policies.

**Architecture:** A shared classic-script presentation helper owns open/highlight/placement and keyboard interaction. Existing Alpine components retain queries, debouncing, category-specific eligibility and selection. A shared stylesheet provides fixed overlays with four 56px rows, kept inside modal boundaries. Existing endpoints accept an explicit suggestions flag; normal short searches remain protected. Retail suggestions rank completed sold stock movements; Clinic uses bounded available-category fallback to avoid extra clinical-history queries.

## Tasks and verification

1. Update original product-search, POS, vaccination reconciliation and API regression tests. Exercise empty focus, disabled and cross-category results, stale responses, keyboard selection and cart/draft preservation. Run these before production edits to confirm missing behavior.
2. Extend InventoryController search (retain retailSale for barcode/checkout), AdminClinicController medicineOptions and AdminVaccinationController options. Return category fields, bounded eligible suggestions, eligible-first typed results and concise availability metadata. Verify original PHP security, vaccination and pricing tests, including expired/mixed stock.
3. Add scripts/components/product-search-dropdown.js and css/components/product-search-dropdown.css. Wire existing admin-dashboard.js, admin-pos.js and admin-vaccinations.js and their three templates. Use input focus with aria-activedescendant, non-tabbable options, guarded selection, outside/Tab/Escape dismissal and scoped placement-listener cleanup. Preserve scanner Enter behavior and invalidate asynchronous work on dismissal, selection, source/type changes and teardown.
4. Update script/style versions and inventory lazy dependencies. Inspect existing UI in the ChatGPT browser without submitting data. Run relevant JS/PHP regressions, npm run build, phone regressions and diff checks. Report any unverified browser/manual acceptance.

## Review focus

- Inactive/expired/unpriced items remain discoverable but cannot be selected.
- Vaccine/Medicine responses and direct selection cannot cross categories.
- Same-query responses from a closed or previous modal cannot overwrite new state.
- Selection does not reopen an empty dropdown; scanner Enter remains functional.
- Overlay scrolling stays inside four rows and avoids modal Save controls.

No branch, commits, deployment, credentials, production data changes or migrations. Existing phone utilities and normalization are outside this change.
