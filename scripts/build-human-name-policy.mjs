import fs from 'node:fs';
const policy = JSON.parse(fs.readFileSync(new URL('../resources/data/human-name-policy.json', import.meta.url), 'utf8'));
const target = new URL('./utils/human-name.js', import.meta.url);
const source = fs.readFileSync(target, 'utf8');
const output = source.replace(/\/\/ BEGIN GENERATED NAME POLICY[\s\S]*?\/\/ END GENERATED NAME POLICY/, `// BEGIN GENERATED NAME POLICY\n// Source: resources/data/human-name-policy.json; run npm run build:names.\nconst HUMAN_NAME_POLICY = ${JSON.stringify(policy)};\n// END GENERATED NAME POLICY`);
if (source !== output) fs.writeFileSync(target, output);

for (const directory of ['../pages/', '../resources/views/']) {
  const root = new URL(directory, import.meta.url);
  for (const entry of fs.readdirSync(root, { recursive: true })) {
    if (!entry.endsWith('.html')) continue;
    const file = new URL(entry.replaceAll('\\', '/'), root);
    const html = fs.readFileSync(file, 'utf8');
    const updated = html.replace(/<input\b[^>]*data-human-name="(first_name|last_name)"[^>]*>/g, (tag, type) =>
      tag.replace(/ maxlength="\d+"/, '').replace('<input', `<input maxlength="${policy[`${type}_max_length`]}"`));
    if (updated !== html) fs.writeFileSync(file, updated);
  }
}
