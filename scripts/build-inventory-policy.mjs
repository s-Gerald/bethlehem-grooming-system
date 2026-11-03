import fs from 'node:fs';
const policy = JSON.parse(fs.readFileSync(new URL('../resources/data/inventory-policy.json', import.meta.url), 'utf8'));
const target = new URL('./components/product-form.js', import.meta.url);
const source = fs.readFileSync(target, 'utf8');
const output = source.replace(/\/\/ BEGIN GENERATED INVENTORY POLICY[\s\S]*?\/\/ END GENERATED INVENTORY POLICY/, `// BEGIN GENERATED INVENTORY POLICY\n  // Source: resources/data/inventory-policy.json; run npm run build:inventory.\n  const inventoryPolicy = ${JSON.stringify(policy)};\n  // END GENERATED INVENTORY POLICY`);
if (source !== output) fs.writeFileSync(target, output);
