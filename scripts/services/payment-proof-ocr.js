// Receipt text is untrusted. These suggestions never acknowledge receipt of funds.
export function parsePaymentReceipt(text) {
  const lines = String(text || '').slice(0, 20000).normalize('NFKC').replace(/[\u00a0\u2007\u202f]/g, ' ').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  const amounts = new Set();
  let recipient = '';
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
    const recipientLabel = /^(?:paid\s+to|sent\s+to|recipient|merchant|receiver)(?:\s+name)?\s*:?\s*/i;
    if (recipientLabel.test(line)) recipient = (line.replace(recipientLabel, '') || lines[index + 1] || '').slice(0, 150);
  }
  const apps = [...new Set(lines.flatMap(line => {
    const match = line.match(/^(?:paying app|paid using|sent using|payment app)\s*:\s*(GCash|Maya)\s*$/i);
    return match ? [match[1].toLowerCase() === 'gcash' ? 'GCash' : 'Maya'] : [];
  }))];
  const referenceCandidates = parseReferences(lines);
  // A transfer rail named in the receipt takes precedence over the app branding.
  const contexts = lines.filter(line => !/ref(?:erence)?|\bID\b/i.test(line));
  const rails = ['qrph', 'instapay'].filter(provider => contexts.some(line => line.toLowerCase().replace(/\s/g, '').includes(provider)));
  const wallets = ['gcash', 'maya'].filter(provider => contexts.some(line => new RegExp(`^${provider}(?:\\s+(?:receipt|payment|transfer))?$`, 'i').test(line)));
  const providers = rails.length ? rails : wallets;
  const preferred = providers.length === 1 ? referenceCandidates.filter(candidate => candidate.provider === providers[0]) : [];
  const choices = preferred.length ? preferred : referenceCandidates;
  const values = [...new Set(choices.map(candidate => candidate.value))];
  return { payingApp: apps.length === 1 ? apps[0] : '', amount: amounts.size === 1 ? [...amounts][0] : '',
    externalReference: values.length === 1 ? values[0] : '', referenceCandidates,
    transactionAt: parseReceiptDates(lines.join(' ')), recipient };
}

function parseReferences(lines) {
  const candidates = [];
  const labelPattern = /\b(?:(GCash|Maya|QR\s*PH|InstaPay|ECPay|billing|biller|customer|account|invoice|order|processor|merchant)\s+)?(?:ref(?:erence)?\.?\s*(?:no\.?|n0\.?|number|#)?|transaction\s*(?:reference(?:\s*(?:no\.?|number))?|id|no\.?|number)|trace\s*(?:no\.?|number|id))\s*[:#]?\s*/gi;
  const normalizeValue = value => {
    const raw = value.trim().replace(/^[:#|]\s*/, '');
    const normalized = raw.replace(/[ -]/g, '').toUpperCase();
    return /^[A-Z0-9 -]+$/i.test(raw) && /^[A-Z0-9]{6,80}$/.test(normalized) && /\d/.test(normalized)
      && !/^20\d{2}-\d{2}-\d{2}/.test(raw) ? normalized : '';
  };
  for (let index = 0; index < lines.length; index++) {
    const line = lines[index];
    const labels = [...line.matchAll(labelPattern)];
    for (const [column, match] of labels.entries()) {
      const provider = (match[1] || '').toLowerCase().replace(/\s/g, '');
      const tail = line.slice(match.index + match[0].length, labels[column + 1]?.index).trim();
      // Length instructions identify a biller's reference, not the wallet transaction.
      if (/ecpay|billing|biller|customer|account|invoice|order|processor|merchant/.test(provider) || /character|\(\s*8\s*[-–]/i.test(tail)) continue;
      const prefix = line.slice(0, match.index);
      if (labels.length === 1 && /billing|biller|customer|account|invoice|order|processor/i.test(prefix)) continue;
      let value = normalizeValue(tail);
      if (!tail) {
        const next = lines[index + 1] || '';
        if ([...next.matchAll(labelPattern)].length) continue;
        const columns = next.split(/\t+| {2,}|\s+\|\s+/);
        if (labels.length > 1 && columns.length !== labels.length) continue;
        value = normalizeValue(labels.length > 1 ? columns[column] || '' : next);
      }
      if (value) {
        const candidate = {label: match[0].trim().replace(/[:#]\s*$/, ''), value, provider};
        const existing = candidates.find(item => item.value === value);
        if (!existing) candidates.push(candidate);
        else if (!existing.provider && provider) Object.assign(existing, candidate);
      }
    }
  }
  return candidates;
}

function parseReceiptDates(text) {
  const patterns = [
    /\b20\d{2}-\d{2}-\d{2}[T ,]+\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?(?:\s*(?:Z|UTC|GMT|PHT|[+-]\d{2}:?\d{2}))?/gi,
    /\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+20\d{2}[ ,]+(?:at\s+)?\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?(?:\s*(?:UTC|GMT|PHT|[+-]\d{2}:?\d{2}))?/gi,
    /\b\d{1,2}[\/-]\d{1,2}[\/-]20\d{2}[ ,]+\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?(?:\s*(?:UTC|GMT|PHT|[+-]\d{2}:?\d{2}))?/gi,
  ];
  const dates = new Set(patterns.flatMap(pattern => [...text.matchAll(pattern)].map(match => parseReceiptDate(match[0]))).filter(Boolean));
  return dates.size === 1 ? [...dates][0] : '';
}

function parseReceiptDate(text) {
  let year, month, day, hour, minute, period;
  let match = text.match(/\b(20\d{2})-(\d{2})-(\d{2})[T ,]+(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?/i);
  if (match) [, year, month, day, hour, minute, period] = match;
  else {
    match = text.match(/\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+(\d{1,2}),?\s+(20\d{2})[ ,]+(?:at\s+)?(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?/i);
    if (match) {
      [, month, day, year, hour, minute, period] = match;
      month = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].indexOf(month.slice(0,3).toLowerCase()) + 1;
    } else {
      match = text.match(/\b(\d{1,2})[\/-](\d{1,2})[\/-](20\d{2})[ ,]+(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?/i);
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
  const local = `${year}-${String(month).padStart(2,'0')}-${String(day).padStart(2,'0')}T${String(hour).padStart(2,'0')}:${String(minute).padStart(2,'0')}`;
  const zone = text.trim().match(/(Z|UTC|GMT|PHT|[+-]\d{2}:?\d{2})$/i)?.[1];
  if (!zone || /^PHT$/i.test(zone)) return local;
  const timestamp = Date.parse(local + (/^(Z|UTC|GMT)$/i.test(zone) ? 'Z' : zone));
  return Number.isFinite(timestamp) ? new Date(timestamp + 8 * 3600000).toISOString().slice(0, 16) : '';
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
      await worker.setParameters({preserve_interword_spaces: '1'});
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
