// Loaded only after an administrator selects an image. No receipt images use this editor.
export async function inspectQrCanvas(canvas) {
  const {default: decode} = await import('../vendor/qr-image/jsqr.js');
  const scale = Math.min(1, 1600 / Math.max(canvas.width, canvas.height));
  const sample = document.createElement('canvas');
  sample.width = Math.max(1, Math.round(canvas.width * scale));
  sample.height = Math.max(1, Math.round(canvas.height * scale));
  try {
    const context = sample.getContext('2d', {willReadFrequently: true});
    context.fillStyle = '#fff';
    context.fillRect(0, 0, sample.width, sample.height);
    context.drawImage(canvas, 0, 0, sample.width, sample.height);
    const pixels = context.getImageData(0, 0, sample.width, sample.height);
    const result = decode(pixels.data, sample.width, sample.height);
    if (result) {
      for (const point of Object.values(result.location)) { point.x /= scale; point.y /= scale; }
    }
    return result;
  } finally { sample.width = sample.height = 1; }
}

export function suggestQrCrop(result, width, height) {
  if (!result) return null;
  const points = ['topLeftCorner', 'topRightCorner', 'bottomLeftCorner', 'bottomRightCorner'].map(key => result.location[key]);
  const left = Math.min(...points.map(p => p.x)), right = Math.max(...points.map(p => p.x));
  const top = Math.min(...points.map(p => p.y)), bottom = Math.max(...points.map(p => p.y));
  const codeSize = Math.max(right - left, bottom - top);
  const quiet = codeSize / (17 + 4 * result.version) * 5;
  const size = Math.min(width, height, codeSize + quiet * 2);
  return {x: Math.max(0, Math.min(width - size, (left + right - size) / 2)),
    y: Math.max(0, Math.min(height - size, (top + bottom - size) / 2)), width: size, height: size};
}

export async function prepareQrCanvas(canvas, originalData) {
  if (!canvas || Math.min(canvas.width, canvas.height) < 128) throw new Error('Use a crop at least 128 pixels wide and high.');
  const result = await inspectQrCanvas(canvas);
  if (!result) throw new Error('No readable QR in this crop. Include the entire code or choose a clearer image.');
  if (originalData && result.data !== originalData) throw new Error('This crop contains a different QR. Include the original receiving code.');
  // Retain existing margins and add any missing quiet zone.
  const points = ['topLeftCorner', 'topRightCorner', 'bottomLeftCorner', 'bottomRightCorner'].map(key => result.location[key]);
  const left = Math.min(...points.map(p => p.x)), right = Math.max(...points.map(p => p.x));
  const top = Math.min(...points.map(p => p.y)), bottom = Math.max(...points.map(p => p.y));
  if (left < 0 || top < 0 || right > canvas.width || bottom > canvas.height) throw new Error('Include the entire QR code in the crop.');
  const quiet = Math.max(right - left, bottom - top) / (17 + 4 * result.version) * 4 + 1;
  const border = Math.max(0, Math.ceil(quiet - Math.min(left, top, canvas.width - right, canvas.height - bottom)));
  const scale = Math.min(1, 2048 / Math.max(canvas.width + 2 * border, canvas.height + 2 * border));
  const output = document.createElement('canvas');
  output.width = Math.round((canvas.width + 2 * border) * scale);
  output.height = Math.round((canvas.height + 2 * border) * scale);
  const context = output.getContext('2d');
  context.fillStyle = '#fff'; context.fillRect(0, 0, output.width, output.height);
  context.imageSmoothingEnabled = false;
  context.drawImage(canvas, border * scale, border * scale, canvas.width * scale, canvas.height * scale);
  if ((await inspectQrCanvas(output))?.data !== result.data) { output.width = output.height = 1; throw new Error('The resized QR is unreadable. Choose a clearer image.'); }
  return output;
}

let stylesheet;
function loadStyles() {
  if (!stylesheet) stylesheet = new Promise((resolve, reject) => {
    const link = document.createElement('link'); link.rel = 'stylesheet';
    link.href = new URL('../vendor/qr-image/cropper.min.css', import.meta.url).href;
    link.onload = resolve; link.onerror = () => { link.remove(); stylesheet = null; reject(new Error('Could not load the image editor. Retry.')); };
    document.head.append(link);
  });
  return stylesheet;
}

export async function openQrImageEditor(file, image, preview, signal) {
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 3 * 1024 * 1024) throw new Error('Select a PNG, JPEG or WebP image up to 3 MB.');
  const source = URL.createObjectURL(file);
  let cropper, original, disposed = false;
  const dispose = () => {
    if (disposed) return;
    disposed = true; cropper?.destroy(); cropper = null;
    if (original) original.width = original.height = 1;
    original = null;
    image.removeAttribute('src'); preview.replaceChildren();
    URL.revokeObjectURL(source); signal?.removeEventListener('abort', dispose);
  };
  signal?.addEventListener('abort', dispose, {once: true});
  try {
    image.src = source;
    const [{default: Cropper}] = await Promise.all([import('../vendor/qr-image/cropper.esm.js'), loadStyles(), image.decode()]);
    if (signal?.aborted) throw new Error('Image selection cancelled.');
    const {naturalWidth: width, naturalHeight: height} = image;
    if (Math.min(width, height) < 128 || width * height > 16000000) throw new Error('Use an image at least 128 × 128 pixels and no larger than 16 megapixels.');
    original = document.createElement('canvas'); original.width = width; original.height = height;
    original.getContext('2d').drawImage(image, 0, 0);
    const detected = await inspectQrCanvas(original);
    if (signal?.aborted) throw new Error('Image selection cancelled.');
    await new Promise((resolve, reject) => {
      const cancelled = () => reject(new Error('Image selection cancelled.'));
      signal?.addEventListener('abort', cancelled, {once: true});
      cropper = new Cropper(image, {aspectRatio: 1, viewMode: 1, dragMode: 'move', autoCropArea: 0.9,
        background: false, rotatable: false, scalable: false, toggleDragModeOnDblclick: false,
        checkOrientation: false,
        preview, ready() {
          signal?.removeEventListener('abort', cancelled);
          if (disposed || signal?.aborted) { reject(new Error('Image selection cancelled.')); return; }
          const bounds = suggestQrCrop(detected, width, height); if (bounds) cropper.setData(bounds); resolve();
        }});
    });
    return {
      detected: !!detected, dispose,
      zoom(delta) { if (!disposed) cropper.zoom(delta); },
      move(x, y) { if (!disposed) cropper.move(x, y); },
      reset() { if (!disposed) { cropper.reset(); const bounds = suggestQrCrop(detected, width, height); if (bounds) cropper.setData(bounds); } },
      async approve(useOriginal = false) {
        if (disposed) throw new Error('Select the image again.');
        if (!useOriginal && detected) {
          const data = cropper.getData();
          const corners = ['topLeftCorner', 'topRightCorner', 'bottomLeftCorner', 'bottomRightCorner'].map(key => detected.location[key]);
          if (corners.some(point => point.x < data.x || point.y < data.y || point.x > data.x + data.width || point.y > data.y + data.height)) throw new Error('Include the entire QR code in the crop.');
        }
        const selected = useOriginal ? original : cropper.getCroppedCanvas({maxWidth: 2048, maxHeight: 2048, fillColor: '#fff', imageSmoothingEnabled: false});
        let output;
        try {
          // Originals are retained byte-for-byte when already readable and server-compatible.
          if (useOriginal) {
            if (width > 4096 || height > 4096 || !await inspectQrCanvas(original)) throw new Error('Use a readable QR image up to 4096 × 4096 pixels, or apply a crop.');
            return file;
          }
          output = await prepareQrCanvas(selected, detected?.data);
          const blob = await new Promise(resolve => output.toBlob(resolve, 'image/png'));
          if (!blob || blob.size > 3 * 1024 * 1024) throw new Error('The crop exceeds 3 MB. Choose a smaller crop.');
          return new File([blob], 'qr-payment.png', {type: 'image/png'});
        } finally { if (!useOriginal && selected) selected.width = selected.height = 1; if (output) output.width = output.height = 1; }
      },
    };
  } catch (error) { dispose(); throw error; }
}
