// Full-page rendering stays local and is imported only for a selected PDF receipt.
export async function readPdfReceipt(file, recognize, signal) {
  if (file.size > 5 * 1024 * 1024) throw new Error('Select a PDF smaller than 5 MB.');
  if (signal?.aborted) throw new Error('OCR cancelled.');
  const pdfjs = await import('../vendor/pdf/pdf.js');
  if (signal?.aborted) throw new Error('OCR cancelled.');
  pdfjs.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdf/pdf.worker.js', import.meta.url).href;
  const task = pdfjs.getDocument({data: new Uint8Array(await file.arrayBuffer()),
    isEvalSupported: false, enableXfa: false, stopAtErrors: true, maxImageSize: 16000000,
    canvasMaxAreaInBytes: 16000000, useSystemFonts: false,
    standardFontDataUrl: new URL('../vendor/pdf/standard_fonts/', import.meta.url).href,
    wasmUrl: new URL('../vendor/pdf/wasm/', import.meta.url).href,
    cMapUrl: new URL('../vendor/pdf/cmaps/', import.meta.url).href, cMapPacked: true});
  let render, canvas;
  const cancel = () => { render?.cancel(); void task.destroy(); };
  signal?.addEventListener('abort', cancel, {once: true});
  try {
    if (signal?.aborted) throw new Error('OCR cancelled.');
    const pdf = await task.promise;
    if (pdf.numPages > 2) throw new Error('Use a PDF with no more than 2 pages.');
    const text = [];
    for (let number = 1; number <= pdf.numPages; number++) {
      if (signal?.aborted) throw new Error('OCR cancelled.');
      const page = await pdf.getPage(number);
      const base = page.getViewport({scale: 1});
      const scale = Math.min(2, Math.sqrt(4000000 / (base.width * base.height)));
      const viewport = page.getViewport({scale});
      canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.floor(viewport.width)); canvas.height = Math.max(1, Math.floor(viewport.height));
      render = page.render({canvasContext: canvas.getContext('2d'), viewport, annotationMode: pdfjs.AnnotationMode.DISABLE});
      await render.promise;
      if (signal?.aborted) throw new Error('OCR cancelled.');
      text.push(await recognize(canvas));
      canvas.width = canvas.height = 1; canvas = null;
      page.cleanup();
    }
    return text.join('\n');
  } catch (error) {
    if (signal?.aborted) throw new Error('OCR cancelled.');
    if (error.name === 'PasswordException') throw new Error('This PDF is password protected. Use a screenshot or enter details manually.');
    throw new Error(error.message?.includes('2 pages') ? error.message : 'This PDF could not be read. Use an image or enter details manually.', {cause: error});
  } finally {
    signal?.removeEventListener('abort', cancel);
    render?.cancel();
    if (canvas) canvas.width = canvas.height = 1;
    await task.destroy();
  }
}
