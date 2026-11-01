# Fixed registration actions

Implemented on `gerald-frontend`, starting at `810fdd5` (matched the `bethlehem-grooming-system` remote branch when checked).

## Implementation

One shared stylesheet fixes the existing action rows to the viewport bottom. Buttons remain in their original forms with their existing identifiers, labels, handlers, disabled states and submission semantics. The bar follows each main's existing 72rem or 64rem content width and responsive gutters. Narrow layouts can wrap the actions when their labels need more room.

Main content reserves bottom clearance, including mobile safe-area space. Root scroll padding and field/message scroll margins protect focus and validation scrolling. The viewport metadata requests content resizing for supporting mobile keyboards. The owner warning sits above the bar and scrolls internally when its matches exceed the available height. Print hides the actions.

Hidden grooming sections continue to hide their own bars. Walk-in replacement mains carry the same classes. No new JavaScript handlers, observers, timers, state, API calls or database queries were added. Confirmation markup and header home links retain their existing behavior.

## Changed production files

- `css/components/registration-actions.css`
- `pages/client/grooming-pre-registration.html`
- `pages/client/booking-pet-details.html`
- `pages/client/clinic-visit-reason.html`
- `pages/client/clinic-visit-summary.html`
- `pages/admin/walk-in-booking.html`
- `pages/admin/walk-in-pet-details.html`
- `pages/admin/walk-in-grooming-services.html`
- `pages/admin/walk-in-booking-review.html`
- `pages/admin/walk-in-consent.html`
- `scripts/components/walk-in-services-step.js`
- `scripts/components/walk-in-review-step.js`
- `scripts/components/walk-in-consent-step.js`
- `scripts/components/walk-in-clinic-complaint-step.js`
- `scripts/components/walk-in-clinic-consent-step.js`
- `scripts/components/walk-in-pet-step.js` (import versions only)

## Verification

- Eight existing frontend regression scripts passed: grooming flow, grooming consent, walk-in entry context, walk-in pet step, walk-in review, clinic visit reason, registration service day and preferred arrival window. These exercise actual navigation/validation/submission functions with isolated data and mocks.
- Focused PHP 8.4 PHPUnit: **19 tests, 183 assertions passed** across GroomingSinglePageInterfaceTest, GroomingReviewAndConsentInterfaceTest, AdminWalkInEntryPointInterfaceTest and CustomerPreRegistrationAccessInterfaceTest. The initial sandbox bootstrap-read failure was resolved by running the same checks outside that sandbox, using the repository's SQLite in-memory and non-sending configuration.
- **102 automated layout checks passed**: 17 actual page/template action rows at 320, 375, 414, 768 and 1280px, plus each at 320px with 200% root text sizing. Checked fixed positioning while scrolling, control overlap/clipping, original submit form association and last-field/message clearance. Normal-size cases also checked whole-document horizontal overflow. The enlarged-text check covers the action bar; some existing page headings can overflow under that extreme combination.
- A separate online grooming confirmation case exposed zero action bars. Inactive grooming sections stayed hidden. The owner warning's continuation control remained reachable with expanded synthetic matches in a 320×360 viewport.
- Layout fixtures used script-free copies of the actual HTML and output from the existing walk-in template functions, rendered in a temporary same-origin iframe within the existing tab. They made no application submissions. Temporary frames were removed afterward.
- A parser comparison against HEAD confirmed all nine HTML pages preserve identical control attributes, counts, order and form ownership (excluding changed layout classes).
- Production `npm run build` passed, including generated policies, OCR assets, Tailwind and Vite (56 modules). The existing Browserslist data advisory remains nonblocking. The new stylesheet is served directly by the existing static asset route rather than bundled by Vite.
- `git diff --check` passed. No existing tests were deleted, skipped or relaxed.
- Live inspection used only the existing admin owner tab. Desktop and narrow-screen scrolling showed the fixed bar and clear final owner field. No browser console errors were captured. Temporary viewport overrides were reset.

Local review artifacts: `storage/app/registration-actions-desktop.jpg`, `storage/app/registration-actions-mobile.jpg`, `storage/app/registration-actions-layout-check.js`, and `storage/app/registration-actions-layout-results.json`. The saved layout expression can be replayed through the browser's CDP Runtime.evaluate with awaitPromise/returnByValue enabled; it resolves to the layout results and cleans up its temporary frame.

Live navigation, real data entry, OTP/password flows and actual submissions were left to the user as requested. Native device keyboard/safe-area behavior and actual browser zoom were not manually exercised; narrow reflow and enlarged-text checks provide the automated layout coverage.

## Hostinger release

Deploy the changed raw HTML, six JavaScript modules and the new shared stylesheet together. HTML references CSS version `20261101-v2`; changed walk-in module imports and entry points use `registration-actions=20261101-v1`. These versions bypass the existing static asset cache. The normal release process should include its generated production assets. No migrations, new dependencies, environment changes or server configuration changes are required. No deployment was performed.
