import fs from 'node:fs';
const policy = JSON.parse(fs.readFileSync(new URL('../resources/data/philippine-mobile-policy.json', import.meta.url), 'utf8'));
const target = new URL('./utils/philippine-mobile.js', import.meta.url);
const source = fs.readFileSync(target, 'utf8');
const output = source.replace(/\/\/ BEGIN GENERATED MOBILE POLICY[\s\S]*?\/\/ END GENERATED MOBILE POLICY/, `// BEGIN GENERATED MOBILE POLICY\n// Source: resources/data/philippine-mobile-policy.json; run npm run build:mobile.\nconst MOBILE_PREFIXES = ${JSON.stringify(policy.prefixes)};\n// END GENERATED MOBILE POLICY`);
if (source !== output) fs.writeFileSync(target, output);
