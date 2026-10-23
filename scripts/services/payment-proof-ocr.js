// Receipt text is untrusted. These suggestions never acknowledge receipt of funds.
export function parsePaymentReceipt(text) {
  const lines = String(text || '').slice(0, 20000).replace(/\u00a0/g, ' ').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  const amounts = new Set();
  let externalReference = '', recipient = '';
  const money = /(?:PHP|₱|P)?\s*(\d{1,3}(?:,\d{3})+\.\d{2}|\d+\.\d{2})(?!\d)/i;
  for (let index = 0; index < lines.length; index++) {
    const line = lines[index];
    if (/^(?:(?:total|payment)\s+amount|amount(?:\s+(?:paid|sent|received))?|total(?:\s+paid)?)\b/i.test(line)) {
      const match = line.match(money) || lines[index + 1]?.match(money);
      if (match) amounts.add(Number(match[1].replaceAll(',', '')).toFixed(2));
    } else if (/^(?:PHP|₱)\s*\d[\d,.]*$/i.test(line)) {
      const previous = lines[index - 1] || '';
      if (!/fee|balance|charge/i.test(previous)) {
        const match = line.match(money);
        if (match) amounts.add(Number(match[1].replaceAll(',', '')).toFixed(2));
      }
    }
    const referenceLabel = /^(?:ref(?:erence)?\.?\s*(?:no\.?|number)?|transaction\s*(?:id|number|reference))\s*[:#]?\s*/i;
    if (referenceLabel.test(line)) {
      const value = line.replace(referenceLabel, '') || lines[index + 1] || '';
      if (/^[A-Z0-9][A-Z0-9 -]{5,99}$/i.test(value)) externalReference = value.replace(/[ -]/g, '').toUpperCase();
    }
    const recipientLabel = /^(?:paid\s+to|sent\s+to|recipient|merchant|receiver)(?:\s+name)?\s*:?\s*/i;
    if (recipientLabel.test(line)) recipient = (line.replace(recipientLabel, '') || lines[index + 1] || '').slice(0, 150);
  }
  return { amount: amounts.size === 1 ? [...amounts][0] : '', externalReference, transactionAt: parseReceiptDate(lines.join(' ')), recipient };
}

function parseReceiptDate(text) {
  let year, month, day, hour, minute, period;
  let match = text.match(/\b(20\d{2})-(\d{2})-(\d{2})[T ,]+(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?/i);
  if (match) [, year, month, day, hour, minute, period] = match;
  else {
    match = text.match(/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(20\d{2})[ ,]+(?:at\s+)?(\d{1,2}):(\d{2})\s*(AM|PM)?/i);
    if (match) {
      [, month, day, year, hour, minute, period] = match;
      month = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].indexOf(month.slice(0,3).toLowerCase()) + 1;
    } else {
      match = text.match(/\b(\d{1,2})[\/-](\d{1,2})[\/-](20\d{2})[ ,]+(\d{1,2}):(\d{2})\s*(AM|PM)?/i);
      if (!match || (Number(match[1]) <= 12 && Number(match[2]) <= 12)) return '';
      [, day, month, year, hour, minute, period] = match;
      if (Number(month) > 12) [day, month] = [month, day];
    }
  }
  [year, month, day, hour, minute] = [year, month, day, hour, minute].map(Number);
  if (period) {
    if (hour < 1 || hour > 12) return '';
    hour = hour % 12 + (period.toLowerCase() === 'pm' ? 12 : 0);
  }
  if (month < 1 || month > 12 || day < 1 || day > new Date(year, month, 0).getDate() || hour > 23 || minute > 59) return '';
  return `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}T${String(hour).padStart(2,'0')}:${String(minute).padStart(2,'0')}`;
}

export async function recognizePaymentProof(file, onProgress = () => {}, signal) {
  if (signal?.aborted) throw new Error('OCR cancelled.');
  const pdf = file?.type === 'application/pdf';
  if (!pdf && !['image/png', 'image/jpeg', 'image/webp'].includes(file?.type)) throw new Error('Select a PNG, JPEG, WebP or PDF receipt.');
  if (file.size > 5 * 1024 * 1024) throw new Error('Select a receipt smaller than 5 MB.');
  if (!pdf) {
    if (typeof createImageBitmap !== 'function') throw new Error('Image reading is unavailable in this browser. Enter the details manually.');
    const bitmap = await createImageBitmap(file).catch(() => { throw new Error('This image could not be read. Select another image or enter the details manually.'); });
    if (bitmap.width * bitmap.height > 16000000) { bitmap.close(); throw new Error('Use a screenshot no larger than 16 megapixels.'); }
    bitmap.close();
  }
  if (signal?.aborted) throw new Error('OCR cancelled.');
  let worker, expired = false, timer, cancel;
  const processing = new AbortController();
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => { expired = true; processing.abort(); reject(new Error('OCR timed out. Retry with a clearer image or enter the details manually.')); }, 90000);
    cancel = () => { expired = true; processing.abort(); reject(new Error('OCR cancelled.')); };
    signal?.addEventListener('abort', cancel, {once: true});
  });
  try {
    const result = await Promise.race([timeout, (async () => {
      const { default: Tesseract } = await import('../vendor/ocr/tesseract.esm.min.js');
      if (expired) return;
      worker = await Tesseract.createWorker('eng', 1, {
        workerPath: new URL('../vendor/ocr/worker.min.js', import.meta.url).href,
        corePath: new URL('../vendor/ocr/core/', import.meta.url).href,
        langPath: new URL('../vendor/ocr/lang/', import.meta.url).href,
        workerBlobURL: false,
        logger: message => { if (!expired) onProgress(Math.round((message.progress || 0) * 100)); },
        errorHandler: () => {},
      });
      if (expired) { await worker.terminate(); return; }
      const recognize = async image => { const {data} = await worker.recognize(image); return data.text; };
      const text = pdf ? await (await import('./payment-receipt-pdf.js')).readPdfReceipt(file, recognize, processing.signal) : await recognize(file);
      return parsePaymentReceipt(text);
    })()]);
    return result;
  } catch (error) {
    throw new Error(error?.message || 'OCR could not run. Retry or enter the payment details manually.');
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', cancel);
    if (worker) await worker.terminate();
  }
}
