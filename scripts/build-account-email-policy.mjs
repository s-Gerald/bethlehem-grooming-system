import fs from 'node:fs';
const policy = JSON.parse(fs.readFileSync(new URL('../resources/data/account-email-policy.json', import.meta.url), 'utf8'));
policy.domains = policy.domains.map(domain => domain.toLowerCase());
policy.exception_domains = policy.exception_domains.map(domain => domain.toLowerCase());
const target = new URL('./utils/account-email.js', import.meta.url);
const source = fs.readFileSync(target, 'utf8');
const output = source.replace(/\/\/ BEGIN GENERATED EMAIL POLICY[\s\S]*?\/\/ END GENERATED EMAIL POLICY/, `// BEGIN GENERATED EMAIL POLICY\n// Source: resources/data/account-email-policy.json; run npm run build:email.\nconst EMAIL_ENTRY_POLICY = ${JSON.stringify(policy)};\n// END GENERATED EMAIL POLICY`);
if (source !== output) fs.writeFileSync(target, output);
