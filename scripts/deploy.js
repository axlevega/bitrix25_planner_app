/**
 * Деплой на сервер по FTP: сборка фронта и загрузка backend + frontend/dist.
 * Требует в .env: FTP_HOST, FTP_USER, FTP_PASSWORD (FTP_REMOTE_DIR, FTP_REMOTE_BACKEND, FTP_REMOTE_FRONTEND — опционально).
 * Файл .env и backend/.env, backend/.env.local не загружаются (данные БД остаются на сервере).
 */

const fs = require('fs');
const path = require('path');
const { Readable } = require('stream');
const { execSync } = require('child_process');
const { Client } = require('basic-ftp');

require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const ROOT = path.resolve(__dirname, '..');

const BACKEND_EXCLUDE = new Set(['vendor', 'node_modules', '.env', '.env.local', '.git', '.gitignore', '*.log']);
const FRONTEND_DIST_EXCLUDE = new Set(['.git']);

function shouldExclude(name, excludeSet) {
  if (excludeSet.has(name)) return true;
  // Не исключаем .htaccess — он нужен в backend/public для CORS и роутинга
  if (name.startsWith('.') && name !== '.htaccess') return true;
  return false;
}

async function uploadDir(client, localDir, remoteDir, excludeSet) {
  const entries = fs.readdirSync(localDir, { withFileTypes: true });
  await client.ensureDir(remoteDir);
  for (const ent of entries) {
    if (shouldExclude(ent.name, excludeSet)) continue;
    const localPath = path.join(localDir, ent.name);
    const remotePath = remoteDir + '/' + ent.name;
    if (ent.isDirectory()) {
      await uploadDir(client, localPath, remotePath, excludeSet);
    } else {
      const stream = fs.createReadStream(localPath);
      await client.uploadFrom(stream, remotePath);
    }
  }
}

async function deploy() {
  const host = process.env.FTP_HOST;
  const user = process.env.FTP_USER;
  const password = process.env.FTP_PASSWORD;
  if (!host || !user || !password) {
    console.error('Set FTP_HOST, FTP_USER, FTP_PASSWORD in .env');
    process.exit(1);
  }

  const remoteDir = (process.env.FTP_REMOTE_DIR || '/').replace(/\/+$/, '') || '/';
  const remoteBackend = process.env.FTP_REMOTE_BACKEND || 'backend';
  const remoteFrontend = process.env.FTP_REMOTE_FRONTEND || 'public';

  const frontendDir = path.join(ROOT, 'frontend');
  const frontendNodeModules = path.join(frontendDir, 'node_modules');

  if (!fs.existsSync(frontendNodeModules)) {
    console.log('Installing frontend dependencies...');
    execSync('npm install', { cwd: frontendDir, stdio: 'inherit' });
  }

  console.log('Building frontend...');
  execSync('npm run build', { cwd: frontendDir, stdio: 'inherit' });

  const distPath = path.join(ROOT, 'frontend', 'dist');
  if (!fs.existsSync(distPath)) {
    console.error('frontend/dist not found after build');
    process.exit(1);
  }

  const client = new Client(60_000);
  client.ftp.verbose = false;

  try {
    const config = {
      host,
      user,
      password,
      port: parseInt(process.env.FTP_PORT || '21', 10),
      secure: process.env.FTP_SECURE === 'true' ? 'implicit' : false,
    };
    console.log('Connecting to FTP...');
    await client.access(config);

    const backendRemote = remoteDir + '/' + remoteBackend;
    const frontendRemote = remoteDir + '/' + remoteFrontend;

    console.log('Uploading backend (excluding vendor, .env)...');
    await uploadDir(client, path.join(ROOT, 'backend'), backendRemote, BACKEND_EXCLUDE);

    console.log('Uploading frontend (dist)...');
    await uploadDir(client, distPath, frontendRemote, FRONTEND_DIST_EXCLUDE);

    const htaccessPath = path.join(ROOT, 'deploy', 'root.htaccess');
    if (fs.existsSync(htaccessPath)) {
      let htaccess = fs.readFileSync(htaccessPath, 'utf8');
      htaccess = htaccess
        .replace(/BACKEND_PLACEHOLDER/g, remoteBackend)
        .replace(/FRONTEND_PLACEHOLDER/g, remoteFrontend);
      const stream = Readable.from([htaccess]);
      await client.uploadFrom(stream, remoteDir + '/.htaccess');
      console.log('Uploaded root .htaccess');
    }

    console.log('Deploy done.');
  } catch (err) {
    console.error('Deploy failed:', err.message);
    process.exit(1);
  } finally {
    client.close();
  }
}

deploy();
