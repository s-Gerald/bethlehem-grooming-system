import fs from 'node:fs';
const policy = JSON.parse(fs.readFileSync(new URL('../resources/data/human-name-policy.json', import.meta.url), 'utf8'));
const target = new URL('./utils/human-name.js', import.meta.url);
const source = fs.readFileSync(target, 'utf8');
const output = source.replace(/\/\/ BEGIN GENERATED NAME POLICY[\s\S]*?\/\/ END GENERATED NAME POLICY/, `// BEGIN GENERATED NAME POLICY\n// Source: resources/data/human-name-policy.json; run npm run build:names.\nconst HUMAN_NAME_POLICY = ${JSON.stringify(policy)};\n// END GENERATED NAME POLICY`);
if (source !== output) fs.writeFileSync(target, output);
