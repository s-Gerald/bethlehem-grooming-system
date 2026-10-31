# Public Landing Renovation Implementation Plan

**Goal:** Renovate the existing public page with live grooming packages, accessible service disclosures, location, About, and FAQ.

**Architecture:** Keep the current static HTML and Laravel routes. Add a narrow method to the existing pricing controller using its price-option normalizer and resolver. Keep public page styles and interactions separate from authenticated applications.

**Tech Stack:** Laravel 12, vanilla HTML/CSS/JavaScript, existing Phosphor SVG sprite, PHPUnit and Node regression tests.

**Spec:** User attachment `Pasted text.txt`, renovation brief supplied on 2026-11-01.

## Constraints and review focus

- Preserve existing shared sign-in and registration workflows, protected APIs, branding, and Hostinger paths.
- Use persisted prices only; exclude inactive, unrelated, unpriced, and unsupported-size offers.
- Use one public request per load/retry and no-store freshness; errors must leave static content usable.
- Preserve fixed/range/starting-at meaning and use the lowest valid amount for card starting prices.
- Use accessible single-open disclosures and mobile menu dismissal; no navigation or form testing in the localhost browser.
- Keep the exact requested section order and supplied public copy. No additional pages, dependencies, or generated imagery.

## Task 1: Public catalogue

Files: modify `routes/api.php`, `app/Http/Controllers/GroomingPricingController.php`, `tests/Feature/GroomingPricingCatalogueTest.php`.

- [x] Add failing tests for unauthenticated whitelisted packages, minimal payload, price modes, unsupported sizes, admin update freshness, deactivation, and protected endpoints.
- [x] Run the focused PHPUnit cases and confirm missing-route failures.
- [x] Implement `publicPackages(GroomingServicePriceResolver $resolver)` returning `{data: [{id, name, petType, descriptionItems, priceOptions}]}`; reuse `priceOptions`, limiting it to persisted supported size prices.
- [x] Verify all existing pricing catalogue and managed catalogue tests.

## Task 2: Landing page

Files: replace markup in `index.html`; create `css/landing.css`, `scripts/landing.js`, and `tests/public-landing-regression.mjs`; remove obsolete landing-only rules from `css/custom.css`; add only the missing Facebook symbol to `assets/icons/phosphor.svg`.

- [x] Add failing behavior tests using Node's existing VM/fake-DOM regression approach for price rendering, unavailable/empty states, retry deduplication, disclosure state, and mobile dismissal.
- [x] Implement the exact page hierarchy and supplied copy with existing logo/hero assets, four anchor links, and shared sign-in entry links.
- [x] Implement dynamic cards/tables from one catalogue response, plus native button disclosures with `aria-expanded` and hidden panels.
- [x] Implement lazy interactive map centered on supplied coordinates with an always-available external directions link.
- [x] Verify focused frontend regressions and JavaScript syntax.

## Task 3: Validation

- [x] Inspect desktop, tablet, and 320/375/414px mobile layouts in the ChatGPT browser using read-only inspection and responsive screenshots.
- [x] Check focus styling, sticky offsets, table scrolling, section hierarchy, network/console state, and no document overflow.
- [x] Run focused authentication regression and required asset build/checks; inspect final diff.
- [x] Report verified behavior and remaining manual/deployment checks.

Execution follows the user's authorization to implement directly; no worktree, commit, publication, or extra approval stage is required.

Validation: 79 focused PHP tests (947 assertions), eight Node regression scripts, production build, JavaScript syntax, and diff checks pass. Browser inspection covered 320, 375, 414, 768, and 1280px layouts; mobile table grid containment was corrected. The user approved FAQ 2 copy matching the existing cross-service pet session restriction. Hostinger deployment and manual customer/authentication flows remain for the user.
