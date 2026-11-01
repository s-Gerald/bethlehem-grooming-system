const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { once } = require('node:events');

// Optional Apache integration check, using dummy files in an isolated deployment.
// BETHLEHEM_TEST_HTTPD may point to another Apache installation (including Linux).
const httpd = process.env.BETHLEHEM_TEST_HTTPD || 'C:/xampp/apache/bin/httpd.exe';
if (!fs.existsSync(httpd)) throw new Error('Set BETHLEHEM_TEST_HTTPD to an Apache executable.');
const serverRoot = path.resolve(path.dirname(httpd), '..').replaceAll('\\', '/');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'bethlehem-file-access-'));
const repository = path.resolve(__dirname, '..');

function write(root, name, content = 'private-fixture') {
  const target = path.join(root, name);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, content);
}

async function checkDeployment(layout) {
  const root = path.join(temporary, layout);
  const probe = net.createServer();
  probe.listen(0, '127.0.0.1');
  await once(probe, 'listening');
  const port = probe.address().port;
  await new Promise(resolve => probe.close(resolve));
  const safeFiles = ['index.html', 'assets/logo.svg', 'assets/catalogue.json', 'css/output.css', 'scripts/api.js',
    'pages/client/sign-in.html', 'pages/client/signup.html', 'pages/client/dashboard.html', 'pages/admin/clinic.html'];
  const credentialFiles = ['auth.json', 'Auth.JSON', 'auth.json~', 'Homestead.json', 'Homestead.yaml',
    'Homestead.yaml~', 'assets/auth.json', 'assets/Homestead.yaml', 'public/auth.json'];
  const privateFiles = [...credentialFiles, ...(layout === 'repository' ? [
    '.env', '.env.backup', '.git/config', 'composer.json', 'composer.lock', 'package.json', 'artisan',
    'app/Models/User.php', 'bootstrap/cache/config.php', 'config/services.php', 'routes/api.php',
    'database/database.sqlite', 'storage/logs/laravel.log', 'storage/app/private/payment-proofs/proof.png',
    'storage/app/public/clinic/attachments/1/scan.png', 'resources/views/admin/availability.html',
    'tests/fixture.php', 'vendor/autoload.php', 'node_modules/fixture.js', 'docs/private.md',
    'public/storage/clinic/attachments/1/scan.png', 'public/storage/payment-proofs/proof.png',
    'backup.sql', 'database.sqlite',
  ] : ['.env', '.git/config', 'storage/clinic/attachments/1/scan.png', 'storage/payment-proofs/proof.png'])];
  for (const name of safeFiles) write(root, name, 'public-fixture');
  for (const name of privateFiles) write(root, name);
  write(root, '.well-known/acme-challenge/fixture', 'acme-fixture');
  const rules = fs.readFileSync(path.join(repository, layout === 'repository' ? '.htaccess' : 'public/.htaccess'));
  write(root, '.htaccess', rules);
  if (layout === 'repository') {
    write(root, 'public/.htaccess', fs.readFileSync(path.join(repository, 'public/.htaccess')));
    write(root, 'public/index.php', 'front-controller-fixture');
  } else {
    write(root, 'index.php', 'front-controller-fixture');
  }
  const normalized = root.replaceAll('\\', '/');
  const configuration = path.join(root, 'apache-test.conf');
  write(root, 'apache-test.conf', `
ServerRoot "${serverRoot}"
ServerName localhost
Listen 127.0.0.1:${port}
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule dir_module modules/mod_dir.so
PidFile "${normalized}/apache.pid"
ErrorLog "${normalized}/apache-errors.log"
DocumentRoot "${normalized}"
<Directory "${normalized}">
  Require all granted
  AllowOverride All
  Options FollowSymLinks
  DirectoryIndex index.html
</Directory>
`);
  const server = spawn(httpd, ['-X', '-f', configuration], { windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  let diagnostics = '';
  server.stderr.on('data', chunk => { diagnostics += chunk; });
  const base = `http://127.0.0.1:${port}`;
  try {
    let ready = false;
    for (let attempt = 0; attempt < 80; attempt++) {
      if (server.exitCode !== null) break;
      try { ready = (await fetch(`${base}/index.html`)).status === 200; } catch {}
      if (ready) break;
      await new Promise(resolve => setTimeout(resolve, 50));
    }
    assert.ok(ready, `Apache failed to start: ${diagnostics}\n${fs.existsSync(path.join(root, 'apache-errors.log')) ? fs.readFileSync(path.join(root, 'apache-errors.log'), 'utf8') : ''}`);
    for (const name of privateFiles) {
      const response = await fetch(`${base}/${name}`);
      assert.equal(response.status, 403, `${layout}: /${name} must be denied before static serving`);
      assert.ok(!(await response.text()).includes('private-fixture'), name);
    }
    for (const name of [...safeFiles, '.well-known/acme-challenge/fixture']) {
      const response = await fetch(`${base}/${name}`);
      assert.equal(response.status, 200, `${layout}: /${name} remains public`);
      assert.equal(await response.text(), name.startsWith('.well-known/') ? 'acme-fixture' : 'public-fixture',
        `${layout}: /${name} must serve the requested public file`);
    }
    for (const name of ['api/me', 'pages/admin/availability.html', 'pages/admin/activity-log.html']) {
      if (name.startsWith('pages/')) fs.rmSync(path.join(root, name), { force: true });
      const response = await fetch(`${base}/${name}`);
      assert.equal(response.status, 200, name);
      assert.equal(await response.text(), 'front-controller-fixture', `${name} must reach Laravel`);
    }
    console.log(`${layout}: ${privateFiles.length} private paths denied; public assets, ACME and Laravel routing preserved`);
  } finally {
    if (server.exitCode === null) { server.kill(); await once(server, 'exit'); }
  }
}

(async () => {
  try {
    await checkDeployment('repository');
    await checkDeployment('public');
  } finally {
    assert.equal(path.dirname(fs.realpathSync(temporary)), fs.realpathSync(os.tmpdir()));
    assert.ok(path.basename(temporary).startsWith('bethlehem-file-access-'));
    fs.rmSync(temporary, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
