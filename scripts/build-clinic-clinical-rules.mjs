import fs from 'node:fs';

const catalog = JSON.parse(fs.readFileSync(new URL('../resources/data/clinic-clinical-rules.json', import.meta.url), 'utf8'));
const target = new URL('./components/admin-clinic.js', import.meta.url);
const start = '// BEGIN GENERATED CLINIC CLINICAL RULES';
const end = '// END GENERATED CLINIC CLINICAL RULES';
const source = fs.readFileSync(target, 'utf8').replace(new RegExp(`^${start}[\\s\\S]*?${end}\\r?\\n`), '');
const output = `${start}\n// Source: resources/data/clinic-clinical-rules.json; run npm run build:clinical.\nconst CLINIC_CLINICAL_RULES = ${JSON.stringify(catalog)};\n${end}\n${source}`;
if (fs.readFileSync(target, 'utf8') !== output) fs.writeFileSync(target, output);
