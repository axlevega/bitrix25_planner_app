/**
 * Проверка .htaccess на сервере через FTP: скачивает корневой и backend/public/.htaccess.
 * Запуск: node scripts/check-htaccess-ftp.js
 * Требует в .env: FTP_HOST, FTP_USER, FTP_PASSWORD (и при необходимости FTP_REMOTE_DIR, FTP_REMOTE_BACKEND).
 */
const fs = require('fs');
const path = require('path');
const { Client } = require('basic-ftp');

require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const ROOT = path.resolve(__dirname, '..');

async function main() {
  const host = process.env.FTP_HOST;
  const user = process.env.FTP_USER;
  const password = process.env.FTP_PASSWORD;
  if (!host || !user || !password) {
    console.error('Set FTP_HOST, FTP_USER, FTP_PASSWORD in .env');
    process.exit(1);
  }

  const remoteDir = (process.env.FTP_REMOTE_DIR || '/').replace(/\/+$/, '') || '/';
  const remoteBackend = process.env.FTP_REMOTE_BACKEND || 'backend';

  const client = new Client(30_000);
  client.ftp.verbose = false;

  const outDir = path.join(ROOT, 'scripts', 'fetched-htaccess');
  if (!fs.existsSync(outDir)) {
    fs.mkdirSync(outDir, { recursive: true });
  }

  const rootOut = path.join(outDir, 'root.htaccess');
  const backendOut = path.join(outDir, 'backend-public.htaccess');

  try {
    await client.access({
      host,
      user,
      password,
      port: parseInt(process.env.FTP_PORT || '21', 10),
      secure: process.env.FTP_SECURE === 'true' ? 'implicit' : false,
    });

    // Корневой .htaccess
    try {
      await client.downloadTo(rootOut, remoteDir + '/.htaccess');
      const content = fs.readFileSync(rootOut, 'utf8');
      console.log('OK: downloaded root .htaccess, length', content.length);
    } catch (e) {
      console.log('Root .htaccess:', e.message || e.code || e);
    }

    // backend/public/.htaccess
    const backendPublicRemote = remoteBackend + '/public/.htaccess';
    const backendPublicPath = remoteDir + '/' + backendPublicRemote;
    try {
      await client.downloadTo(backendOut, backendPublicPath);
      const content = fs.readFileSync(backendOut, 'utf8');
      console.log('OK: downloaded backend/public/.htaccess, length', content.length);
    } catch (e) {
      console.log('backend/public/.htaccess:', e.message || e.code || e);
    }

    console.log('\nSaved to', outDir);
  } finally {
    client.close();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
