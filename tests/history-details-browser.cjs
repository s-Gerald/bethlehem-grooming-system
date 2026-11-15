// Optional browser coverage of production History markup with isolated API fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const admin = read('pages/admin/archive.html');
const adminStart = admin.indexOf('<main', admin.indexOf('<!-- Archive Body -->'));
const adminContent = admin.slice(adminStart, admin.indexOf('</main>', adminStart) + '</main>'.length);
const styles = [...admin.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*>|<style>[\s\S]*?<\/style>/g)].map(match => match[0]).join('\n');
const session = {
  id: 91, ownerName: 'Recorded Owner', bookingReference: 'WI-91', status: 'released',
  appointmentDate: '2024-02-09', historyDate: '2024-02-10', historyAtIso: '2024-02-10T10:00:00+08:00',
  specialNotes: 'Owner requested a call', internalStaffNote: 'Use the side entrance',
  payment: {id: 2, finalPrice: 1000}, paid: true,
  pets: [{bookingPetId: 11, petName: 'First pet', confirmedSize: 'medium', bodyCondition: 'ideal', bodyConditionVerified: true,
    medicalConditions: 'Sensitive paws', petGroomerName: 'Groomer Ana', groomingStateLabel: 'Finished',
    groomingPreferenceLabel: 'Summer cut', groomingStartedAt: '10:15 AM', specialInstructions: 'Recorded care instructions.\n'.repeat(40)},
    {bookingPetId: 12, petName: 'Clicked pet', specialInstructions: 'Brush gently'}],
  services: [{id: 1, bookingPetId: 11, name: 'Full groom', serviceKind: 'package', paidPrice: 600},
    {id: 2, bookingPetId: 12, name: 'Nail trim', serviceKind: 'add_on', paidPrice: 200}],
  productAddons: [{itemName: 'Paw balm', quantity: 2, priceAtSale: 100, subtotal: 200}],
};
const payment = {status: 'paid', payment_method_label: 'Cash', paid_at: '2026-11-10T16:38:00Z'};
const grooming = {booking_id: 91, booking_reference: 'GR-91', status: 'released', paid: true, number_of_pets: 2,
  booking_date: '2026-11-11', history_date: '2026-11-11',
  payment_summary: {...payment, final_booking_total: 1000},
  pets: [{booking_pet_id: 11, pet_name: 'First pet', services: [{service_name: 'Recorded grooming'}]},
    {booking_pet_id: 12, pet_name: 'Clicked pet', services: [{service_name: 'Recorded bath'}]}]};
const clinic = {id: 91, appointment_reference: 'CL-91', appointment_date: '2026-11-10', status: 'completed',
  pet: {pet_id: 12, pet_name: 'Clicked pet'}, treatment_given: 'Recorded care',
  payment: {...payment, total_paid: 3100, payment_method_label: 'Online Payment ' + 'LongLabel'.repeat(12)}};

(async () => {
  const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge'});
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    let releaseAlpine, alpineHold;
    let alpineSource;
    await page.route('**/alpinejs@*/**', async route => {
      alpineSource ||= process.env.ALPINE_PATH ? fs.readFileSync(process.env.ALPINE_PATH)
        : route.fetch().then(response => response.body());
      const source = await alpineSource;
      if (alpineHold) await alpineHold;
      await route.fulfill({contentType: 'text/javascript', body: source});
    });
    await page.route('https://history.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/admin/history') {
        return route.fulfill({contentType: 'text/html', body: `<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head>
          <body>${adminContent}
          <script src="/scripts/components/admin-loading.js"></script>
          <script src="/scripts/components/recorded-product-pricing.js"></script><script src="/scripts/components/admin-archive.js"></script>
          <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script></body></html>`});
      }
      if (url.pathname === '/client/history') {
        return route.fulfill({contentType: 'text/html', body: read('pages/client/grooming-history.html')
          .replace(/<script\b[^>]*\bsrc="[^"]+"[^>]*><\/script>/g, '')
          .replace('data-session-gate="checking"', '')});
      }
      const file = path.resolve(root, '.' + url.pathname);
      if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return route.fulfill({status: 404, body: ''});
      return route.fulfill({path: file, contentType: file.endsWith('.js') ? 'application/javascript' : undefined});
    });
    await page.addInitScript(({session, grooming, clinic}) => {
      window.printCalls = 0;
      window.print = () => { window.printCalls++; };
      window.ungroupedClinicCalls = 0;
      window.historyCalls = 0; window.consentCalls = 0; window.customerCalls = 0;
      const scenario = new URL(location.href).searchParams.get('scenario');
      window.holdHistory = Boolean(scenario);
      const signature = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="80" height="24"%3E%3Cpath d="M 2 19 L 18 3 L 29 20 L 76 11" stroke="black" fill="none"/%3E%3C/svg%3E';
      const patients = [81, 82].map(id => ({
        id, clinic_visit_group_id: 1, appointment_reference: `CL-${id}`, appointment_date: '2024-02-10',
        status: 'completed', final_status_label: 'Completed', paid: true, total_amount: 1500, payment_status_label: 'Paid',
        pet: {id, name: id === 81 ? 'Clinic pet' : 'Clinic sibling'}, ownerName: 'Recorded Owner',
        vitals: {weight_kg: id === 81 ? '3.2' : '12.3'},
        record: {working_diagnosis: id === 81 ? 'First patient findings' : 'Sibling findings',
          primary_veterinarian_name: 'Dr. Bethlehem',
          prescriptions: [{medicine: `Prescription ${id}`, instructions: `Instructions ${id}`}],
          diagnostics: [{diagnostic: `Diagnostic ${id}`, result: `Result ${id}`}],
          attachments: [{id, file_name: `record-${id}.png`}, {id: id + 100, file_name: `service-${id}.pdf`, clinic_case_service_id: id}],
          follow_up_notes: `Follow up for ${id}`},
        treatment_count: 1, service_count: 1,
      }));
      const group = {id: 1, total_amount: 3000, paid_at: '2024-02-10T10:00:00+08:00'};
      const loadHistory = async type => {
        window.historyCalls++;
        if (window.holdHistory) await new Promise(resolve => { window.releaseHistory = resolve; });
        if (window.failHistory) throw new Error('History service is temporarily unavailable.');
        return {archived: window.emptyHistory ? [] : type === 'grooming' ? [session] : [{
          ...patients[0], session: {...group, patients: patients.map(({id, pet, appointment_reference}) => ({id, pet, appointment_reference}))},
        }]};
      };
      window.API = {
        getStaffIdentity: async () => ({user: scenario === 'clinic-only'
          ? {role: 'staff', staff_type: 'clinic', staff_permissions: {clinic_workspace: true}} : {role: 'admin'}}),
        getArchivedBookings: () => loadHistory('grooming'),
        getArchivedClinicAppointments: () => loadHistory('clinic'),
        loadAdminData: async (endpoint, load, render) => {
          if (scenario === 'cached') render({archived: [session]});
          render(await load());
        },
        getGroomingSignedConsent: () => {
          window.consentCalls++;
          if (scenario === 'startup') return new Promise(() => {});
          return Promise.resolve({consent: {grooming_consent: true, sedation_consent: false,
            signer_name: 'Grooming Signer', signed_at: '2024-02-10T10:00:00+08:00', signature_image: signature,
            consent_version: 'Grooming v3', agreement_snapshot: {grooming_intro: 'Historical grooming agreement',
              grooming_conditions: ['Recorded grooming clause']}}});
        },
        getBookingHistory: async () => ({history: [grooming]}),
        getArchivedClinicSessionDetails: async id => {
          (window.clinicSessionCalls ||= []).push(id);
          return {session: group, consent: {clinic_consent: true, signer_name: 'Clinic Signer',
            signed_at: '2024-02-10T10:00:00+08:00', signature_image: signature, consent_version: 'Clinic v4',
            agreement_snapshot: {title: 'Historical clinic agreement', clauses: [{heading: 'Recorded clause', text: 'Clinic consent text'}]}},
          patients: patients.map(record => ({visit: record, care: {
            treatments: [{id: record.id, state: 'published', treatment_name: `Treatment ${record.id}`, dose_amount: 1, dose_unit: 'mL'}],
            services: [{id: record.id, status: 'performed', service_snapshot: {name: `Service ${record.id}`},
              quantity: 2, applied_price: 175, notes: `Service notes ${record.id}`}],
            confinement: {notes: `Confinement notes ${record.id}`, discharge_note: `Discharge ${record.id}`},
          }}))};
        },
        getClinicCase: async () => { window.ungroupedClinicCalls++; throw new Error('Unexpected patient request'); },
        adminGetPetCaseDetails: async () => { window.ungroupedClinicCalls++; throw new Error('Unexpected care request'); },
        getCustomerClinicHistory: async () => {
          window.customerCalls++;
          return {visits: [{...clinic, patients: [clinic]}], current_page: 1, last_page: 1};
        },
        getCustomerClinicHistoryVisit: async () => {
          window.clinicDetailCalls = (window.clinicDetailCalls || 0) + 1;
          return {patients: [clinic], grouped: false};
        },
        loadCustomerData: async (endpoint, load, render) => { window.customerCalls++; render(await load()); },
        getMe: async () => ({user: {first_name: 'Owner', last_name: 'Test'}}),
      };
    }, {session, grooming, clinic});

    async function printRecord(dialog, title) {
      const printButton = dialog.getByRole('button', {name: 'Print Record', exact: true});
      await dialog.getByRole('button', {name: 'Close', exact: true}).waitFor();
      assert.equal(await page.evaluate(() => sessionStorage.length), 0);
      const callsBefore = await page.evaluate(() => window.printCalls);
      await dialog.locator('.history-print-button:not([aria-disabled="true"])').waitFor();
      await printButton.focus();
      await page.keyboard.press('Enter');
      await page.waitForFunction(count => window.printCalls === count + 1, callsBefore);
      const record = page.locator('[data-history-print]');
      assert.equal(await record.isVisible(), false, 'Print content is hidden during normal use');
      await page.emulateMedia({media: 'print'});
      await record.getByRole('heading', {name: title, exact: true}).waitFor();
      assert.equal(await page.locator('main').first().isVisible(), false);
      assert.equal(await dialog.isVisible(), false);
      assert.equal(await record.locator('a, button, summary, [role="alert"]').count(), 0);
      const printed = await record.innerText();
      assert.doesNotMatch(printed, /View Full Transaction|Search history|Invoice/i);
      assert.equal(await record.locator('img[alt="Customer signature"]').count(), 1);
      if (process.env.HISTORY_SCREENSHOT_DIR) {
        await page.pdf({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, title.toLowerCase().replaceAll(' ', '-') + '.pdf'),
          preferCSSPageSize: true});
      }
      await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
      await record.waitFor({state: 'detached'});
      await page.emulateMedia({media: 'screen'});
      assert.equal(await dialog.isVisible(), true);
      assert.equal(await printButton.evaluate(el => document.activeElement === el), true);
      assert.equal(await page.evaluate(() => document.body.style.overflow), 'hidden');
      return printed;
    }

    const startup = {
      error: page.locator('main > section[x-show="!loading && errorMessage"]'),
      empty: page.locator('main > section[x-show="historyLoaded && !errorMessage && filteredCount === 0"]'),
      records: page.locator('.history-groups'),
      clear: page.locator('button[x-show="hasActiveFilters"]'),
      month: page.locator('div[x-show="selectedYear && availableMonths.length > 1"]'),
      year: page.locator('div[x-show="availableYears.length > 0"]'),
      yearIndicator: page.locator('div[x-show="selectedYear"]'),
    };
    const loader = page.locator('main > admin-loader[page]');
    for (const target of ['?scenario=startup', 'reload', '?scenario=startup&type=grooming&booking_id=91&booking_pet_id=12']) {
      alpineHold = new Promise(resolve => { releaseAlpine = resolve; });
      if (target === 'reload') await page.reload({waitUntil: 'commit'});
      else await page.goto('https://history.test/admin/history' + target, {waitUntil: 'commit'});
      await page.getByRole('heading', {name: 'History', exact: true}).waitFor();
      await page.waitForFunction(() => Boolean(document.querySelector('link[href^="/css/custom.css"]').sheet));
      assert.equal(await page.evaluate(() => typeof window.Alpine), 'undefined');
      assert.equal(await page.getByRole('textbox', {name: 'Search history'}).isVisible(), true, 'Stable search shell stays visible');
      assert.equal(await page.locator('main').getAttribute('x-cloak'), null, 'The whole page must not be cloaked');
      for (const [name, surface] of Object.entries(startup)) {
        assert.equal(await surface.evaluate(el => getComputedStyle(el).display), 'none', `${name} must not paint before Alpine`);
        assert.equal(await surface.getAttribute('x-cloak'), '', `${name} uses the existing cloak mechanism`);
      }
      assert.equal(await page.getByRole('button', {name: 'Try again'}).count(), 0, 'False retry is absent from the accessibility tree');
      releaseAlpine(); alpineHold = null;
      await page.waitForFunction(() => window.historyCalls === 1);
      assert.equal(await loader.isVisible(), true, 'The intended loader appears while the request is pending');
      for (const name of ['error', 'empty', 'records', 'clear']) assert.equal(await startup[name].isVisible(), false);
      await page.evaluate(() => { window.holdHistory = false; window.releaseHistory(); });
      await startup.records.waitFor({state: 'visible'});
      assert.equal(await loader.isVisible(), false);
      assert.equal(await startup.error.isVisible(), false); assert.equal(await startup.empty.isVisible(), false);
      assert.equal(await page.evaluate(() => window.historyCalls), 1);
      if (target.includes('booking_id')) {
        await page.getByRole('dialog').filter({has: page.locator('#grooming-record-title')}).waitFor({state: 'visible'});
        assert.equal(await page.evaluate(() => window.consentCalls), 1, 'Pending consent never blocks deep-link opening');
      }
    }

    await page.goto('https://history.test/admin/history?scenario=retry');
    await page.waitForFunction(() => typeof window.releaseHistory === 'function');
    await page.evaluate(() => { window.failHistory = true; window.holdHistory = false; window.releaseHistory(); });
    await startup.error.waitFor({state: 'visible'});
    assert.match(await startup.error.innerText(), /History service is temporarily unavailable/);
    for (const surface of [loader, startup.empty, startup.records]) assert.equal(await surface.isVisible(), false);
    await page.evaluate(() => { window.failHistory = false; window.holdHistory = true; });
    await startup.error.getByRole('button', {name: 'Try again'}).click();
    await loader.waitFor({state: 'visible'});
    assert.equal(await startup.error.isVisible(), false);
    await page.evaluate(() => { window.holdHistory = false; window.releaseHistory(); });
    await startup.records.waitFor({state: 'visible'});
    assert.equal(await page.evaluate(() => window.historyCalls), 2, 'Retry uses exactly one additional request');

    await page.getByRole('button', {name: 'Clinic', exact: true}).click();
    await page.locator('.clinic-row').waitFor();
    assert.equal(await startup.error.isVisible(), false);
    assert.deepEqual(await page.evaluate(() => window.clinicSessionCalls || []), [], 'Listing stays lightweight');
    await page.locator('.clinic-row').getByRole('button', {name: /View Visit/}).click();
    const clinicDialog = page.getByRole('dialog', {name: 'Clinic Record'});
    await clinicDialog.waitFor({state: 'visible'});
    await clinicDialog.getByRole('button', {name: 'Clinic sibling · CL-82'}).click();
    await clinicDialog.getByRole('heading', {name: 'Clinic sibling', exact: true}).waitFor();
    assert.match(await clinicDialog.innerText(), /Sibling findings/);
    assert.doesNotMatch(await clinicDialog.innerText(), /First patient findings/);
    assert.match(await clinicDialog.innerText(), /Total paid.*3,000/);
    const clinicPrint = await printRecord(clinicDialog, 'CLINIC VISIT RECORD');
    const firstPatient = clinicPrint.slice(clinicPrint.indexOf('Clinic pet'), clinicPrint.indexOf('Clinic sibling'));
    const sibling = clinicPrint.slice(clinicPrint.indexOf('Clinic sibling'));
    for (const [id, patientRecord] of [[81, firstPatient], [82, sibling]]) {
      for (const field of [`CL-${id}`, `record-${id}.png`, `service-${id}.pdf`, `Prescription ${id}`,
        `Diagnostic ${id}`, `Result ${id}`, `Treatment ${id}`, `Service notes ${id}`, `Follow up for ${id}`,
        `Confinement notes ${id}`, `Discharge ${id}`, 'Dr. Bethlehem', 'Patient subtotal']) {
        assert.ok(patientRecord.includes(field), `Patient ${id} must include ${field}`);
      }
      assert.doesNotMatch(patientRecord, new RegExp(`Treatment ${id === 81 ? 82 : 81}`));
    }
    assert.match(firstPatient, /First patient findings[\s\S]*3\.2 kg/);
    assert.match(sibling, /Sibling findings[\s\S]*12\.3 kg/);
    assert.match(clinicPrint, /Clinic Signer[\s\S]*Clinic v4[\s\S]*Clinic consent text/);
    assert.match(clinicPrint, /Payment Summary[\s\S]*3,000/);
    assert.deepEqual(await page.evaluate(() => window.clinicSessionCalls), [81], 'One grouped request covers switching and printing');
    assert.equal(await page.evaluate(() => window.ungroupedClinicCalls), 0, 'Grouped visits never request details per patient');
    if (process.env.HISTORY_SCREENSHOT_DIR) {
      await page.evaluate(async () => Promise.all(document.getAnimations().filter(animation => animation.effect?.getTiming().iterations !== Infinity)
        .map(animation => animation.finished.catch(() => {}))));
      await page.screenshot({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, 'clinic-owner-session.png')});
    }
    await page.keyboard.press('Escape');
    await page.goto('https://history.test/admin/history?scenario=clinic-only');
    await page.waitForFunction(() => typeof window.releaseHistory === 'function');
    await page.evaluate(() => { window.emptyHistory = true; window.holdHistory = false; window.releaseHistory(); });
    await startup.empty.waitFor({state: 'visible'});
    assert.match(await startup.empty.innerText(), /clinic history/i);
    for (const surface of [loader, startup.error, startup.records, page.getByRole('group', {name: 'History type'})]) assert.equal(await surface.isVisible(), false);

    await page.goto('https://history.test/admin/history?scenario=cached');
    await startup.records.waitFor({state: 'visible'});
    await page.waitForFunction(() => window.historyCalls === 1);
    assert.equal(await loader.isVisible(), false, 'Valid cached records remain visible during revalidation');
    assert.equal(await startup.error.isVisible(), false); assert.equal(await startup.empty.isVisible(), false);
    await page.evaluate(() => { window.holdHistory = false; window.releaseHistory(); });

    await page.goto('https://history.test/admin/history?type=grooming&booking_id=91&booking_pet_id=12');
    const adminDialog = page.getByRole('dialog');
    await adminDialog.waitFor({state: 'visible'});
    const body = page.getByRole('region', {name: 'Grooming record details'});
    await page.waitForFunction(() => document.querySelector('[aria-label="Grooming record details"]').scrollTop > 0);
    assert.equal(await adminDialog.locator('[data-history-booking-pet]').count(), 2);
    const targetRect = await adminDialog.locator('[data-history-booking-pet="12"]').boundingBox();
    const bodyRect = await body.boundingBox();
    assert.ok(targetRect.y >= bodyRect.y - 2 && targetRect.y < bodyRect.y + bodyRect.height);
    assert.equal(await page.evaluate(() => window.scrollY), 0, 'Targeting does not move the page');
    assert.equal(await page.evaluate(() => window.historyCalls), 1);
    assert.equal(await page.evaluate(() => window.consentCalls), 1);
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Close grooming record');
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Print Record');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Close grooming record');
    const groomingPrint = await printRecord(adminDialog, 'GROOMING VISIT RECORD');
    assert.match(groomingPrint, /Recorded Owner[\s\S]*WI-91[\s\S]*Session Details/);
    assert.match(groomingPrint, /Grooming Signer[\s\S]*Grooming v3[\s\S]*Recorded grooming clause/);
    assert.match(groomingPrint, /First pet[\s\S]*Medium · Clinic verified[\s\S]*Recorded care instructions[\s\S]*Clicked pet/);
    const firstPetRecord = groomingPrint.slice(groomingPrint.indexOf('First pet'), groomingPrint.indexOf('Clicked pet'));
    const secondPetRecord = groomingPrint.slice(groomingPrint.indexOf('Clicked pet'), groomingPrint.indexOf('Product Add-ons'));
    assert.match(firstPetRecord, /Groomer Ana[\s\S]*Ideal · Clinic verified[\s\S]*Sensitive paws[\s\S]*10:15 AM[\s\S]*Full groom[\s\S]*Main service[\s\S]*Summer cut[\s\S]*600.00/);
    assert.doesNotMatch(firstPetRecord, /Nail trim/);
    assert.match(secondPetRecord, /Nail trim[\s\S]*À la carte[\s\S]*200.00[\s\S]*Brush gently/);
    assert.match(groomingPrint, /Paw balm[\s\S]*Owner requested a call[\s\S]*Use the side entrance[\s\S]*Payment Summary[\s\S]*Total paid\s+₱1000\.00/);
    await page.keyboard.press('Escape');
    await adminDialog.waitFor({state: 'hidden'});
    assert.equal(new URL(page.url()).search, '?type=grooming');
    assert.equal(await page.evaluate(() => document.activeElement.dataset.historyBooking), '91');
    await page.locator('[data-history-booking="91"]').click();
    await adminDialog.waitFor({state: 'visible'});
    assert.equal(await body.evaluate(el => el.scrollTop), 0, 'Ordinary row opening starts normally');
    await page.keyboard.press('Escape');
    await page.goto('https://history.test/admin/history?type=grooming&booking_id=999');
    await page.getByRole('status').filter({hasText: /no longer available/}).waitFor();
    assert.equal(await adminDialog.isVisible(), false);
    assert.equal(new URL(page.url()).search, '?type=grooming');

    await page.goto('https://history.test/client/history');
    await page.locator('[data-history-open]').nth(2).waitFor();
    assert.equal(await page.evaluate(() => window.customerCalls), 2);
    await page.locator('[data-history-type="grooming"]').first().click();
    const customerDialog = page.getByRole('dialog');
    await customerDialog.waitFor({state: 'visible'});
    assert.match(await customerDialog.innerText(), /Visit total paid[\s\S]*₱1,000.00[\s\S]*Cash[\s\S]*Nov 11, 2026 · 12:38 AM/);
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Close', 'Focus stays inside the dialog');
    await page.keyboard.press('Escape');
    await page.locator('[data-history-type="clinic"]').click();
    await customerDialog.getByRole('heading', {name: 'Clicked pet', exact: true}).waitFor();
    assert.match(await customerDialog.innerText(), /Total paid[\s\S]*₱3,100.00[\s\S]*Online Payment/);
    for (const width of [320, 375, 414, 768, 1280]) {
      await page.setViewportSize({width, height: 850});
      await page.evaluate(async () => {
        await Promise.all(document.getAnimations().filter(animation => animation.effect?.getTiming().iterations !== Infinity)
          .map(animation => animation.finished.catch(() => {})));
      });
      if (process.env.HISTORY_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, `clinic-payment-${width}.png`)});
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true,
        `Page fits ${width}px: ${JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('body *')]
          .filter(el => el.getBoundingClientRect().right > innerWidth + 1 && el.getClientRects().length)
          .slice(0, 8).map(el => ({tag: el.tagName, id: el.id, classes: el.className, width: el.getBoundingClientRect().width}))))}`);
      assert.equal(await customerDialog.evaluate(el => el.scrollWidth <= el.clientWidth), true, `Payment fits ${width}px`);
    }
    assert.equal(await page.evaluate(() => window.customerCalls), 2, 'Opening details makes no payment request');
    assert.equal(await page.evaluate(() => window.clinicDetailCalls), 1, 'Only the selected clinic visit loads clinical details');
    assert.deepEqual(errors, []);
    console.log('History browser checks passed: persisted session printing, print cleanup, deep links, focus, startup/retry and responsive customer payments.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
