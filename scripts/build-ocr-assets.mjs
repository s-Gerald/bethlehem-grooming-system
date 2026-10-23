import { copyFile, mkdir, readdir } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve('scripts/vendor/ocr');
await mkdir(path.join(root, 'core'), {recursive: true});
await mkdir(path.join(root, 'lang'), {recursive: true});
for (const name of ['tesseract.esm.min.js', 'worker.min.js', 'worker.min.js.LICENSE.txt']) {
  await copyFile(path.join('node_modules/tesseract.js/dist', name), path.join(root, name));
}
for (const name of await readdir('node_modules/tesseract.js-core')) {
  if (name.endsWith('.wasm.js') || name === 'LICENSE') await copyFile(path.join('node_modules/tesseract.js-core', name), path.join(root, 'core', name));
}
await copyFile('node_modules/@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz', path.join(root, 'lang/eng.traineddata.gz'));
await copyFile('node_modules/tesseract.js/LICENSE.md', path.join(root, 'LICENSE.md'));
console.log('Packaged on-demand OCR engine, worker and English model.');
