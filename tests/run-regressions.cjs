const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const files = fs.readdirSync(__dirname).filter(file =>
  /-regression\.(cjs|mjs)$/.test(file) || file === 'admin-grooming-incoming-card.cjs',
).sort();
const env = { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'sqlite', DB_DATABASE: ':memory:', DB_URL: '',
  CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  IPROGSMS_API_TOKEN: '', SMS_VERIFICATION_AVAILABLE: 'false', SMS_VERIFICATION_ENFORCED: 'false', GROOMING_COMPLETION_SMS_ENABLED: 'false' };
let failures = 0;
for (const file of files) {
  console.log(`\n${file}`);
  const result = spawnSync(process.execPath, ['--experimental-vm-modules', path.join(__dirname, file)], {
    cwd: path.resolve(__dirname, '..'), env, stdio: 'inherit',
  });
  if (result.error) console.error(result.error);
  if (result.status !== 0) failures++;
}
console.log(`\n${files.length - failures}/${files.length} regression scripts passed.`);
process.exitCode = failures ? 1 : 0;
