// Optional browser coverage of production History markup with isolated API fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const pageStyles = markup => [...markup.matchAll(/<link\b[^>]*rel="stylesheet"[^>]*>|<style>[\s\S]*?<\/style>/g)].map(match => match[0]).join('\n');
const admin = read('pages/admin/archive.html');
const adminStart = admin.indexOf('<main', admin.indexOf('<!-- Archive Body -->'));
const adminContent = admin.slice(adminStart, admin.indexOf('</main>', adminStart) + '</main>'.length);
const styles = pageStyles(admin);
const confirmationPages = {
  'grooming-online': 'pages/client/grooming-pre-registration.html',
  'grooming-walk-in': 'pages/admin/walk-in-booking-confirmed.html',
  'clinic-online': 'pages/client/clinic-visit-confirmed.html',
  'clinic-walk-in': 'pages/admin/walk-in-clinic-confirmed.html',
  'grooming-summary': 'pages/client/dashboard.html',
};
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
  special_notes: 'Customer requested a call', internalStaffNote: 'PRIVATE GROOMING NOTE',
  payment_summary: {...payment, final_booking_total: 1300, total_paid: 1650, grooming_services_total: 1300, products_total: 350,
    products: [{item_name: 'Medicated shampoo', quantity: 2, price_at_sale: 175, subtotal: 350}],
    pets: [
      {booking_pet_id: 11, final_pet_charge: 800, service_breakdown: [
        {label: 'Recorded grooming', service_kind: 'package', price_at_booking: 650},
        {label: 'Recorded nail trim', service_kind: 'ala_carte', price_at_booking: 150},
      ]},
      {booking_pet_id: 12, final_pet_charge: 500, service_breakdown: [
        {label: 'Recorded bath', service_kind: 'ala_carte', price_at_booking: 500},
      ]},
    ],
  },
  pets: [{booking_pet_id: 11, pet_name: 'First pet', grooming_preference_label: 'Summer cut', services: [{service_name: 'Recorded grooming'}]},
    {booking_pet_id: 12, pet_name: 'Clicked pet', services: [{service_name: 'Recorded bath'}]}]};
const clinic = {id: 91, appointment_reference: 'CL-91', appointment_date: '2026-11-10', status: 'completed',
  pet: {pet_id: 12, pet_name: 'Clicked pet'}, treatment_given: 'Recorded care',
  internal_staff_note: 'PRIVATE CLINIC NOTE',
  billing_lines: [
    {description: 'Consultation', quantity: 1, unit_price: 450, amount: 450},
    {description: 'Ultrasound', quantity: 1, unit_price: 2050, amount: 2050},
    {description: 'Medicine', quantity: 2, unit_price: 300, amount: 600},
  ],
  vitals: {weight_kg: 4.4, temperature_c: 38.9},
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
          <script src="/scripts/components/clinic-attachments.js"></script>
          <script src="/scripts/components/recorded-product-pricing.js"></script><script src="/scripts/components/admin-archive.js"></script>
          <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script></body></html>`});
      }
      if (url.pathname === '/client/history') {
        return route.fulfill({contentType: 'text/html', body: read('pages/client/grooming-history.html')
          .replace(/<script\b[^>]*\bsrc="[^"]+"[^>]*><\/script>/g, '')
          .replace('data-session-gate="checking"', '')});
      }
      const confirmationPage = confirmationPages[url.pathname.split('/').at(-1)];
      if (url.pathname.startsWith('/print-fixture/') && confirmationPage) {
        const markup = read(confirmationPage);
        const content = confirmationPage.endsWith('dashboard.html')
          ? markup.match(/<dialog id="groomingVisitSummary"[\s\S]*?<main id="groomingVisitPrint"[^>]*><\/main>/)[0]
          : '<main id="confirmationRoot" class="confirmation-page"></main>';
        return route.fulfill({contentType: 'text/html', body: `<!doctype html><html lang="en"><head>
          <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">${pageStyles(markup)}</head>
          <body><aside>Outside document</aside>${content}</body></html>`});
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
      const customerConsent = {signer_name: 'Recorded Owner', signed_at: '2024-02-10T10:00:00+08:00', signature_image: signature};
      const groupedPatients = [
        {...clinic, id: 101, patient_subtotal: 3100},
        {...clinic, id: 102, appointment_reference: 'CL-102', pet: {pet_id: 13, pet_name: 'Clinic sibling'}, patient_subtotal: 400,
          billing_lines: [{description: 'Follow-up care', quantity: 1, unit_price: 400, amount: 400}]},
        {id: 103, pet: {pet_name: 'Cancelled patient'}, status: 'cancelled',
          billing_lines: [{description: 'Excluded charge', quantity: 1, unit_price: 999, amount: 999}]},
      ];
      const patients = [81, 82].map(id => ({
        id, clinic_visit_group_id: 1, appointment_reference: `CL-${id}`, appointment_date: '2024-02-10',
        time_window: {window_label: id === 81 ? 'Morning arrival' : 'Afternoon arrival'},
        status: 'completed', final_status_label: 'Completed', paid: true, total_amount: 1500, payment_status_label: 'Paid',
        pet: {id, name: id === 81 ? 'Clinic pet' : 'Clinic sibling'}, ownerName: 'Recorded Owner',
        vitals: {weight_kg: id === 81 ? '3.2' : '12.3'},
        record: {working_diagnosis: id === 81 ? 'First patient findings' : 'Sibling findings',
          primary_veterinarian_name: 'Dr. Bethlehem',
          prescriptions: [{medicine: `Prescription ${id}`, instructions: `Instructions ${id}`}],
          diagnostics: [{diagnostic: `Diagnostic ${id}`, result: `Result ${id}`}],
          attachments: [{id, file_name: `record-${id}.png`},
            {id: id + 100, file_name: `service-${id}.pdf`, clinic_case_service_id: id},
            {id: id + 200, file_name: `imaging-${id}.png`, clinic_case_service_id: id, file_type: 'image/png'}],
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
        clinicDownloadAttachment: async (visitId, fileId) => {
          (window.attachmentRequests ||= []).push([visitId, fileId]);
          if (window.failAttachment) throw new Error('Could not fetch the attachment');
          const canvas = Object.assign(document.createElement('canvas'), {width: 120, height: 60});
          const image = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
          return {blob: new Blob([image], {type: 'application/octet-stream'})};
        },
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
          return {visits: [{...clinic, patients: [clinic]}, {...clinic, id: 101, patients: groupedPatients}], current_page: 1, last_page: 1};
        },
        getCustomerClinicHistoryVisit: async id => {
          window.clinicDetailCalls = (window.clinicDetailCalls || 0) + 1;
          const consent = {...customerConsent, clinic_consent: true};
          return Number(id) === 101
            ? {patients: groupedPatients, grouped: true, payment: {...clinic.payment, total_paid: 3500}, owner_name: 'Recorded Owner', consent}
            : {patients: [clinic], grouped: false, owner_name: 'Recorded Owner', consent};
        },
        getCustomerGroomingHistoryConsent: async () => {
          window.groomingConsentCalls = (window.groomingConsentCalls || 0) + 1;
          return {owner_name: 'Recorded Owner', consent: {...customerConsent, grooming_consent: true, sedation_consent: false}};
        },
        loadCustomerData: async (endpoint, load, render) => { window.customerCalls++; render(await load()); },
        getMe: async () => ({user: {first_name: 'Owner', last_name: 'Test'}}),
      };
    }, {session, grooming, clinic});

    async function assertReportLayout(report) {
      const layout = await report.evaluate(element => {
        const header = element.querySelector('.confirmation-brand-header');
        const field = element.querySelector('.confirmation-fields > div');
        const [left, right] = Array.from(field.children, child => child.getBoundingClientRect());
        return {
          page: getComputedStyle(element).page,
          header: getComputedStyle(header).display,
          rowAligned: right.left > left.left && Math.abs(right.top - left.top) < 5,
          overflow: element.scrollWidth > element.clientWidth + 1,
        };
      });
      assert.deepEqual(layout, {page: 'bethlehem-report', header: 'flex', rowAligned: true, overflow: false});
    }

    async function printRecord(dialog, title, variant = 'admin') {
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
      await assertReportLayout(record);
      assert.equal(await page.locator('main').first().isVisible(), false);
      assert.equal(await dialog.isVisible(), false);
      assert.equal(await record.locator('a, button, summary, [role="alert"]').count(), 0);
      const printed = await record.innerText();
      assert.doesNotMatch(printed, /View Full Transaction|Search history|Invoice/i);
      assert.doesNotMatch(printed, /Signed agreement|Grooming v3|Clinic v4|Historical .* agreement|Recorded .*clause|Clinic consent text/i);
      assert.match(printed, /Signed by[\s\S]*Signed at[\s\S]*Feb 10, 2024 · 10:00 AM/);
      assert.equal(await record.locator('img[alt="Customer signature"]').count(), 1);
      if (variant === 'admin' && title.startsWith('GROOMING')) {
        const fullGroom = record.getByRole('row').filter({hasText: 'Full groom'});
        assert.deepEqual((await fullGroom.getByRole('cell').allTextContents()).slice(1), ['Main service', '₱600.00']);
        assert.match(await fullGroom.innerText(), /Grooming preference.*Summer cut/);
        assert.equal(await record.getByRole('row').filter({hasText: 'Paw balm'}).count(), 1);
      } else if (variant === 'admin') {
        assert.equal(await record.getByRole('table', {name: 'Vitals', exact: true}).count(), 2);
      }
      if (process.env.HISTORY_SCREENSHOT_DIR) {
        await page.pdf({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, variant + '-' + title.toLowerCase().replaceAll(' ', '-') + '.pdf'),
          preferCSSPageSize: true});
      }
      await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
      await record.waitFor({state: 'detached'});
      await page.emulateMedia({media: 'screen'});
      assert.equal(await dialog.isVisible(), true);
      assert.equal(await printButton.evaluate(el => document.activeElement === el), true);
      assert.equal(await page.evaluate(() => getComputedStyle(document.body).overflow), 'hidden');
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
    for (const file of ['imaging-82.png', 'record-82.png']) {
      const trigger = clinicDialog.getByRole('button', {name: 'Open attachment ' + file, exact: true});
      await trigger.click();
      const preview = page.getByRole('dialog', {name: file, exact: true});
      await preview.getByRole('img', {name: file}).waitFor({timeout: 3000});
      await page.keyboard.press('Escape');
      await preview.waitFor({state: 'hidden'});
      await page.waitForFunction(name => document.activeElement.getAttribute('aria-label') === 'Open attachment ' + name, file);
      assert.equal(await clinicDialog.isVisible(), true);
    }
    await page.evaluate(() => { window.failAttachment = true; });
    await clinicDialog.getByRole('button', {name: 'Open attachment imaging-82.png', exact: true}).click();
    const errorPreview = page.getByRole('dialog', {name: 'imaging-82.png', exact: true});
    await errorPreview.getByText('Could not load this image.').waitFor();
    await page.keyboard.press('Escape');
    assert.equal(await clinicDialog.isVisible(), true);
    assert.deepEqual(await page.evaluate(() => window.attachmentRequests), [[82, 282], [82, 82], [82, 282]]);
    await clinicDialog.getByRole('button', {name: 'Clinic pet · CL-81'}).click();
    await clinicDialog.getByRole('heading', {name: 'Clinic pet', exact: true}).waitFor();
    await clinicDialog.getByRole('button', {name: 'Clinic sibling · CL-82'}).click();
    await clinicDialog.getByRole('heading', {name: 'Clinic sibling', exact: true}).waitFor();
    await clinicDialog.getByText('View signed agreement', {exact: true}).click();
    assert.match(await clinicDialog.innerText(), /Clinic v4[\s\S]*Clinic consent text/);
    const clinicPrint = await printRecord(clinicDialog, 'CLINIC VISIT RECORD');
    const firstPatient = clinicPrint.slice(clinicPrint.indexOf('Clinic pet'), clinicPrint.indexOf('Clinic sibling'));
    const sibling = clinicPrint.slice(clinicPrint.indexOf('Clinic sibling'));
    assert.equal((clinicPrint.match(/Feb 10, 2024/g) || []).length, 2, 'The visit date appears once in addition to the signature timestamp');
    assert.match(firstPatient, /Morning arrival/);
    assert.match(sibling, /Afternoon arrival/);
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
    assert.match(clinicPrint, /Follow up for 82[\s\S]*Consent & Signature[\s\S]*Clinic agreement\s+Agreed[\s\S]*Clinic Signer[\s\S]*Payment Summary[\s\S]*3,000/);
    assert.match(await clinicDialog.innerText(), /Clinic v4[\s\S]*Clinic consent text/);
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
    await adminDialog.getByText('View signed agreement', {exact: true}).click();
    assert.match(await adminDialog.innerText(), /Grooming v3[\s\S]*Recorded grooming clause/);
    const groomingPrint = await printRecord(adminDialog, 'GROOMING VISIT RECORD');
    assert.match(groomingPrint, /Visit Overview[\s\S]*Recorded Owner[\s\S]*WI-91/);
    assert.match(groomingPrint, /Use the side entrance[\s\S]*Grooming consent\s+Agreed[\s\S]*Sedation consent\s+Not authorized[\s\S]*Grooming Signer[\s\S]*Payment Summary/);
    assert.match(await adminDialog.innerText(), /Grooming v3[\s\S]*Recorded grooming clause/);
    assert.match(groomingPrint, /First pet[\s\S]*Medium · Clinic verified[\s\S]*Recorded care instructions[\s\S]*Clicked pet/);
    const firstPetRecord = groomingPrint.slice(groomingPrint.indexOf('First pet'), groomingPrint.indexOf('Clicked pet'));
    const secondPetRecord = groomingPrint.slice(groomingPrint.indexOf('Clicked pet'), groomingPrint.indexOf('Product Add-ons'));
    assert.match(firstPetRecord, /Groomer Ana[\s\S]*Ideal · Clinic verified[\s\S]*10:15 AM[\s\S]*Full groom[\s\S]*Summer cut[\s\S]*Main service[\s\S]*600\.00[\s\S]*Sensitive paws/);
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
    const groomingText = await customerDialog.innerText();
    assert.match(groomingText, /First pet[\s\S]*Recorded grooming[\s\S]*₱650\.00[\s\S]*Recorded nail trim[\s\S]*₱150\.00[\s\S]*Clicked pet[\s\S]*Recorded bath[\s\S]*₱500\.00/);
    assert.match(groomingText, /Medicated shampoo[\s\S]*2 × ₱175\.00[\s\S]*₱350\.00[\s\S]*Total paid[\s\S]*₱1,650\.00[\s\S]*Cash[\s\S]*Nov 11, 2026 · 12:38 AM/);
    for (const width of [320, 1280]) {
      await page.setViewportSize({width, height: 850});
      const modalContent = customerDialog.locator('#historyModalContent');
      const product = modalContent.getByRole('listitem').filter({hasText: 'Medicated shampoo'});
      await product.scrollIntoViewIfNeeded();
      assert.equal(await modalContent.evaluate(el => el.scrollWidth <= el.clientWidth), true);
      assert.equal(await product.evaluate(el => {
        const label = el.firstElementChild.getBoundingClientRect();
        const price = el.lastElementChild.getBoundingClientRect();
        return price.left >= label.right && Math.abs(price.top - label.top) < 5;
      }), true, `Grooming amounts align at ${width}px`);
      if (process.env.HISTORY_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, `grooming-payment-${width}.png`)});
    }
    await page.keyboard.press('Shift+Tab');
    assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Print Record', 'Focus stays inside the dialog');
    assert.equal(await page.evaluate(() => window.groomingConsentCalls || 0), 0, 'Only printing loads the grooming signature');
    const customerGroomingPrint = await printRecord(customerDialog, 'GROOMING VISIT RECORD', 'customer');
    assert.match(customerGroomingPrint, /Recorded grooming[\s\S]*Summer cut[\s\S]*₱650\.00[\s\S]*Recorded nail trim[\s\S]*₱150\.00[\s\S]*Recorded bath[\s\S]*₱500\.00[\s\S]*Medicated shampoo[\s\S]*₱350\.00/);
    assert.match(customerGroomingPrint, /Sedation consent[\s\S]*Not agreed[\s\S]*Grooming services[\s\S]*₱1,300\.00[\s\S]*Total paid[\s\S]*₱1,650\.00/);
    assert.doesNotMatch(groomingText + customerGroomingPrint, /PRIVATE GROOMING NOTE/);
    assert.equal(await page.evaluate(() => window.groomingConsentCalls), 1);
    await page.keyboard.press('Escape');
    await page.locator('[data-history-type="clinic"][data-history-key="91"]').click();
    await customerDialog.getByRole('heading', {name: 'Clicked pet', exact: true}).waitFor();
    assert.match(await customerDialog.innerText(), /Total paid[\s\S]*₱3,100.00[\s\S]*Online Payment/);
    const customerClinicPrint = await printRecord(customerDialog, 'CLINIC VISIT RECORD', 'customer');
    assert.match(customerClinicPrint, /Recorded care[\s\S]*Consultation[\s\S]*₱450\.00[\s\S]*Ultrasound[\s\S]*₱2,050\.00[\s\S]*Medicine[\s\S]*2 × ₱300\.00[\s\S]*₱600\.00[\s\S]*Total paid[\s\S]*₱3,100\.00/);
    assert.doesNotMatch(customerClinicPrint, /PRIVATE CLINIC NOTE/);
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
    await page.keyboard.press('Escape');
    await page.locator('[data-history-type="clinic"][data-history-key="101"]').click();
    await customerDialog.getByRole('heading', {name: 'Clinic sibling', exact: true}).waitFor();
    const groupedClinicText = await customerDialog.innerText();
    assert.match(groupedClinicText, /Patient subtotal[\s\S]*₱3,100\.00[\s\S]*Clinic sibling[\s\S]*Follow-up care[\s\S]*Patient subtotal[\s\S]*₱400\.00[\s\S]*Total paid[\s\S]*₱3,500\.00/);
    const groupedClinicPrint = await printRecord(customerDialog, 'CLINIC VISIT RECORD', 'customer-grouped');
    assert.match(groupedClinicPrint, /Patient subtotal[\s\S]*₱3,100\.00[\s\S]*Clinic sibling[\s\S]*Patient subtotal[\s\S]*₱400\.00[\s\S]*Total paid[\s\S]*₱3,500\.00/);
    assert.doesNotMatch(groupedClinicText + groupedClinicPrint, /Excluded charge|PRIVATE CLINIC NOTE/);
    assert.equal((groupedClinicText.match(/Total paid/g) || []).length, 1);
    assert.equal((groupedClinicPrint.match(/Total paid/g) || []).length, 1);
    for (const variant of Object.keys(confirmationPages)) {
      await page.goto(`https://history.test/print-fixture/${variant}`);
      const summary = variant === 'grooming-summary';
      await page.evaluate(async variant => {
        const walkIn = variant.endsWith('walk-in');
        const status = walkIn ? 'checked_in' : 'waiting_to_arrive';
        const pets = ['Coco', 'Milo'];
        const owner = {name: 'Recorded Owner', email: 'owner@example.test'};
        window.API.waitForSessionVerification = window.API.waitForCustomerSession = async () => true;
        if (walkIn) document.body.dataset.confirmationContext = 'walk-in';
        if (variant === 'grooming-summary') {
          window.API.getBooking = async () => ({booking: {
            booking_reference: 'GR-SUMMARY', status, booking_date: '2024-02-10', owner,
            booking_pets: pets.map(pet_name => ({pet: {pet_name}})),
          }});
          const {openGroomingVisitSummary} = await import('/scripts/components/grooming-visit-summary.js');
          await openGroomingVisitSummary(1, null, () => 'Waiting to Arrive');
        } else if (variant.startsWith('clinic')) {
          sessionStorage.setItem(walkIn ? 'walkInClinicConfirmation' : 'clinicVisitConfirmation', JSON.stringify({
            owner, service_day: '2024-02-10', patients: pets.map((name, index) => ({
              status, pet: {name}, queue_number: walkIn ? index + 1 : null, appointment_reference: `CL-FIXTURE-${index}`,
            })),
          }));
          await import('/scripts/components/clinic-visit-confirmed.js');
        } else {
          sessionStorage.setItem(walkIn ? 'walkInBookingConfirmation' : 'bookingConfirmation', JSON.stringify({
            owner, status, booking_reference: 'GR-CONFIRM', booking_date: '2024-02-10', queue_number: walkIn ? 3 : null,
            submitted_at: '2024-02-09T10:15:00+08:00', pets: pets.map(petName => ({petName})),
          }));
          const {initBookingConfirmedStep} = await import('/scripts/components/booking-confirmed-step.js');
          if (!walkIn) await initBookingConfirmedStep();
        }
      }, variant);
      const printButton = page.getByRole('button', {name: summary ? 'Print Summary' : 'Print Confirmation'});
      await printButton.waitFor();
      assert.equal(await page.evaluate(() => window.printCalls), 0);
      if (!summary) assert.equal(await page.getByRole('link', {name: /Go to Grooming|Go to Active Cases|Return to Dashboard/}).isVisible(), true);
      await printButton.click();
      assert.equal(await page.evaluate(() => window.printCalls), 1);
      if (summary) {
        assert.equal(await page.locator('#groomingVisitSummary').isVisible(), true);
        assert.equal(await page.locator('#groomingVisitPrint').isVisible(), false);
        // The native print call uses this class for the duration of the dialog.
        await page.evaluate(() => document.body.classList.add('grooming-summary-printing'));
      }
      await page.emulateMedia({media: 'print'});
      const report = page.locator('.print-report');
      await assertReportLayout(report);
      const printable = await report.innerText();
      assert.match(printable, /BETHLEHEM[\s\S]*Visit Overview[\s\S]*Recorded Owner[\s\S]*Coco[\s\S]*Milo/);
      assert.doesNotMatch(printable, /Outside document|Print Confirmation|Print Summary/);
      assert.equal(await page.locator('aside').isVisible(), false);
      if (process.env.HISTORY_SCREENSHOT_DIR) {
        await page.pdf({path: path.join(process.env.HISTORY_SCREENSHOT_DIR, `${variant}-report.pdf`), preferCSSPageSize: true});
      }
      await page.emulateMedia({media: 'screen'});
    }
    assert.deepEqual(errors, []);
    console.log('Browser checks passed for History, both online and walk-in confirmations, and the customer Grooming summary.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
