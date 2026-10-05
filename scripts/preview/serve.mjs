#!/usr/bin/env node
/**
 * يقدّم مجلد المعاينة عبر HTTP ويفتحه في المتصفح.
 * Serve the preview folder over HTTP and open it.
 *
 * فتح افتحني.html مباشرة من القرص (file://) لا يعمل في كل المتصفحات: Safari
 * يمنع صفحةً محلية من تحميل صور محلية، وبعض المتصفحات تتعثّر في المسارات
 * العربية المُرمَّزة. عبر HTTP تختفي هذه الفئة من المشاكل كلها.
 *
 *   npm run preview:open
 */
import { createServer } from 'node:http';
import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { spawn } from 'node:child_process';
import { dirname, extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const DIR = join(ROOT, 'معاينة الصفحات');
const PORT = Number(process.env.PREVIEW_PORT ?? 4300);

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.png': 'image/png',
  '.svg': 'image/svg+xml',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
};

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url ?? '/', `http://127.0.0.1:${PORT}`);
    let rel = decodeURIComponent(url.pathname);
    if (rel === '/' || rel === '') rel = '/افتحني.html';

    // منع الخروج من مجلد المعاينة مهما كان المسار.
    const target = join(DIR, normalize(rel).replace(/^(\.\.[/\\])+/, ''));
    if (!target.startsWith(DIR)) {
      res.writeHead(403).end('forbidden');
      return;
    }

    const info = await stat(target);
    if (info.isDirectory()) {
      res.writeHead(404).end('not found');
      return;
    }

    res.writeHead(200, {
      'Content-Type': TYPES[extname(target).toLowerCase()] ?? 'application/octet-stream',
      'Content-Length': info.size,
      'Cache-Control': 'no-store',
    });
    createReadStream(target).pipe(res);
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' }).end('الملف غير موجود');
  }
});

server.on('error', (e) => {
  console.error(e.code === 'EADDRINUSE'
    ? `المنفذ ${PORT} مشغول. جرّب: PREVIEW_PORT=4301 npm run preview:open`
    : e.message);
  process.exit(1);
});

server.listen(PORT, '127.0.0.1', () => {
  const url = `http://127.0.0.1:${PORT}/`;
  console.log(`معاينة الصفحات تعمل على: ${url}`);
  console.log('اتركه مفتوحًا، وأوقفه بـ Ctrl+C');
  if (process.platform === 'darwin') spawn('open', [url], { stdio: 'ignore', detached: true }).unref();
});
