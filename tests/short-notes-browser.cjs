const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const path = require('node:path');
const { readFile } = require('node:fs/promises');

(async () => {
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' });
  try {
    const page = await browser.newPage({ viewport: { width: 320, height: 640 } });
    const legacy = 'x'.repeat(600);
    await page.setContent(`<form data-check-notes>
      <label>Note <textarea data-short-note></textarea></label>
      <button>Next</button></form>
      <div style="display:flex;width:160px"><p class="note-text">${legacy}</p></div>`);
    await page.addStyleTag({ path: path.resolve(__dirname, '../css/short-notes.css') });
    await page.addScriptTag({ path: path.resolve(__dirname, '../scripts/utils/short-notes.js') });
    const field = page.getByRole('textbox', { name: 'Note' });
    const alert = page.getByRole('alert');
    await field.fill('x'.repeat(300));
    assert.equal(await alert.count(), 0);
    assert.doesNotMatch(await page.locator('body').innerText(), /\b\d+\s*\/\s*300\b|characters remaining/i);
    await field.press('End');
    await field.press('a');
    assert.equal((await field.inputValue()).length, 300);
    assert.match(await alert.textContent(), /Maximum 300 characters/);
    assert.equal(await field.getAttribute('aria-invalid'), 'true');
    await field.press('Backspace');
    assert.equal(await alert.count(), 0);

    const textBeforePaste = await field.inputValue();
    await field.evaluate(element => {
      const clipboardData = new DataTransfer();
      clipboardData.setData('text/plain', 'too long');
      const allowed = element.dispatchEvent(new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true }));
      if (allowed) throw new Error('Browser paste was not prevented');
    });
    assert.equal(await field.inputValue(), textBeforePaste);
    assert.match(await alert.textContent(), /Shorten the text/);

    await field.evaluate(element => {
      element.dispatchEvent(new InputEvent('beforeinput', { inputType: 'insertFromDrop', bubbles: true }));
      element.value += 'dropped text';
      element.dispatchEvent(new InputEvent('input', { inputType: 'insertFromDrop', bubbles: true }));
    });
    assert.equal(await field.inputValue(), textBeforePaste);
    assert.equal(await alert.count(), 1);

    await page.locator('form').evaluate(form => form.addEventListener('submit', event => {
      event.preventDefault();
      form.dataset.continued = 'true';
    }));
    await field.evaluate((element, text) => { element.value = text; }, legacy);
    await page.getByRole('button', { name: 'Next' }).click();
    assert.equal(await field.inputValue(), legacy);
    assert.match(await alert.textContent(), /Shorten it before saving/);
    assert.equal(await page.locator('form').getAttribute('data-continued'), null);
    await field.fill('🐾'.repeat(300));
    await page.getByRole('button', { name: 'Next' }).click();
    assert.equal(await page.locator('form').getAttribute('data-continued'), 'true');
    assert.equal(await alert.count(), 0);

    const wrapped = page.locator('.note-text');
    assert.equal(await wrapped.textContent(), legacy);
    assert.equal(await wrapped.evaluate(p => p.scrollWidth <= p.parentElement.clientWidth && p.offsetHeight > 20), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    // Exercise the actual clinic fields and summary renderer with a synthetic draft.
    await page.route('https://bethlehem.test/**', async route => {
      const { pathname } = new URL(route.request().url());
      if (pathname === '/') {
        await route.fulfill({ contentType: 'text/html', body: `
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <link rel="stylesheet" href="/css/output.css">
          <link rel="stylesheet" href="/css/short-notes.css">
          <script defer src="/scripts/utils/short-notes.js"></script>
          <form novalidate><div id="fields"></div><button>Continue</button></form>
          <div id="review"></div>
          <script type="module">
            import { mountClinicVisitReasonFields } from '/scripts/components/clinic-visit-reason-fields.js';
            import { getClinicVisitSummaryMarkup } from '/scripts/components/clinic-visit-summary-card.js';
            const fields = mountClinicVisitReasonFields(document.querySelector('#fields'), 'Mochi', ['Other']);
            document.querySelector('form').addEventListener('submit', event => {
              event.preventDefault();
              if (!fields.validate()) return;
              document.querySelector('#review').innerHTML = getClinicVisitSummaryMarkup({ reason: fields.details.value });
              event.target.hidden = true;
            });
          </script>` });
      } else if (pathname.startsWith('/scripts/') || pathname.startsWith('/css/')) {
        await route.fulfill({
          contentType: pathname.endsWith('.js') ? 'application/javascript' : 'text/css',
          body: await readFile(path.resolve(__dirname, '..', '.' + pathname)),
        });
      } else {
        await route.abort();
      }
    });
    await page.goto('https://bethlehem.test/');
    const details = page.getByRole('textbox', { name: 'Tell us more' });
    await details.evaluate((element, value) => { element.value = value; }, legacy);
    await page.getByRole('button', { name: 'Continue' }).click();
    assert.match(await alert.textContent(), /Shorten it before saving/);
    assert.equal(await page.locator('#review').innerText(), '');

    const note = '<em>' + 'W'.repeat(290) + '</em>\n';
    await details.fill(note);
    await page.getByRole('button', { name: 'Continue' }).click();
    const reviewNote = page.locator('#review .note-text').last();
    assert.equal(await reviewNote.textContent(), note);
    assert.equal(await reviewNote.locator('em').count(), 0);
    assert.equal(await reviewNote.evaluate(p => p.scrollWidth <= p.clientWidth && p.offsetHeight > 30), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    console.log('Short note browser checks passed.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
