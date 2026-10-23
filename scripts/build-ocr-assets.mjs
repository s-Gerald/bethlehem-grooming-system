import { copyFile, cp, mkdir, readdir } from 'node:fs/promises';
import { build } from 'esbuild';
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
const qr = path.resolve('scripts/vendor/qr-image');
await mkdir(qr, {recursive: true});
for (const name of ['cropper.esm.js', 'cropper.min.css']) await copyFile(`node_modules/cropperjs/dist/${name}`, path.join(qr, name));
await copyFile('node_modules/cropperjs/LICENSE', path.join(qr, 'cropper.LICENSE'));
await copyFile('node_modules/jsqr/LICENSE', path.join(qr, 'jsqr.LICENSE'));
await build({stdin: {contents: "export {default} from 'jsqr';", resolveDir: process.cwd()}, bundle: true, minify: true, format: 'esm', outfile: path.join(qr, 'jsqr.js')});
const pdf = path.resolve('scripts/vendor/pdf');
await mkdir(pdf, {recursive: true});
// .js extensions work with the application's existing static asset MIME handling.
await copyFile('node_modules/pdfjs-dist/legacy/build/pdf.mjs', path.join(pdf, 'pdf.js'));
await copyFile('node_modules/pdfjs-dist/legacy/build/pdf.worker.mjs', path.join(pdf, 'pdf.worker.js'));
for (const name of ['wasm', 'standard_fonts', 'cmaps']) await cp(`node_modules/pdfjs-dist/${name}`, path.join(pdf, name), {recursive: true});
await copyFile('node_modules/pdfjs-dist/LICENSE', path.join(pdf, 'LICENSE'));
console.log('Packaged lazy OCR, QR crop/detection and PDF receipt assets.');
