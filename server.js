/**
 * Локальный сервер (Node.js): раздача страницы и API векторной копии.
 * Запуск: npm install && npm start  →  http://localhost:8080
 */
const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const { IncomingForm } = require('formidable');

const PORT = 8080;
const ROOT = __dirname;

let config = {};
try {
  const cfgPath = path.join(ROOT, 'config.json');
  if (fs.existsSync(cfgPath)) {
    config = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));
  }
} catch (e) {
  console.warn('config.json не найден или неверен:', e.message);
}

function send(res, code, body, contentType = 'application/json') {
  res.writeHead(code, { 'Content-Type': contentType + '; charset=utf-8' });
  res.end(typeof body === 'string' ? body : JSON.stringify(body));
}

function vectorizeApi(req, res) {
  const form = new IncomingForm({ maxFileSize: 20 * 1024 * 1024 });
  form.parse(req, (err, fields, files) => {
    if (err) {
      send(res, 200, { error: 'Ошибка загрузки: ' + err.message });
      return;
    }
    const file = files.image && (files.image[0] || files.image);
    if (!file || !file.filepath) {
      send(res, 200, { error: 'Файл не выбран или не загружен.' });
      return;
    }
    const username = config.vectorizer_username || '';
    const password = config.vectorizer_password || '';
    if (!username || !password) {
      send(res, 200, { error: 'Укажите vectorizer_username и vectorizer_password в config.json.' });
      return;
    }
    const content = fs.readFileSync(file.filepath);
    const filename = (file.originalFilename || 'image.jpg').replace(/[^a-zA-Z0-9._-]/g, '_');
    const mime = file.mimetype || 'image/jpeg';
    const boundary = '----' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    const preamble = Buffer.from(
      '--' + boundary + '\r\n' +
      'Content-Disposition: form-data; name="image"; filename="' + filename + '"\r\n' +
      'Content-Type: ' + mime + '\r\n\r\n',
      'utf8'
    );
    const end = Buffer.from('\r\n--' + boundary + '--\r\n', 'utf8');
    const bodyBuf = Buffer.concat([preamble, content, end]);

    const options = {
      hostname: 'vectorizer.ai',
      path: '/api/v1/vectorize',
      method: 'POST',
      headers: {
        'Authorization': 'Basic ' + Buffer.from(username + ':' + password).toString('base64'),
        'User-Agent': 'FloorPlanLocal/1.0 Node',
        'Content-Type': 'multipart/form-data; boundary=' + boundary,
        'Content-Length': bodyBuf.length
      }
    };
    const proxy = https.request(options, (r) => {
      let data = '';
      r.setEncoding('utf8');
      r.on('data', (chunk) => { data += chunk; });
      r.on('end', () => {
        if (r.statusCode !== 200) {
          send(res, 200, { error: 'Vectorizer.AI вернул код ' + r.statusCode + '. ' + (data.slice(0, 200) || '') });
          return;
        }
        let svg = (data || '').trim();
        if (svg.indexOf('<svg') === -1 && svg.indexOf('<?xml') === -1) {
          send(res, 200, { error: 'Ответ не похож на SVG.' });
          return;
        }
        svg = svg.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/\s+on\w+\s*=\s*["\'][^"\']*["\']/gi, '').replace(/\s+on\w+\s*=\s*[^\s>]+/gi, '').trim();
        send(res, 200, { svg });
      });
    });
    proxy.on('error', (e) => send(res, 200, { error: 'Ошибка подключения: ' + e.message }));
    proxy.setTimeout(120000);
    proxy.write(bodyBuf);
    proxy.end();
  });
}

const mimeTypes = {
  '.html': 'text/html',
  '.js': 'application/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.ico': 'image/x-icon'
};

const server = http.createServer((req, res) => {
  const url = req.url.split('?')[0];
  if ((url === '/api/vectorize' || url === '/api/vectorize.php') && req.method === 'POST') {
    vectorizeApi(req, res);
    return;
  }
  let filePath = path.join(ROOT, url === '/' ? 'index.html' : url);
  if (!filePath.startsWith(ROOT)) {
    send(res, 403, 'Forbidden', 'text/plain');
    return;
  }
  if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
    send(res, 404, 'Not Found', 'text/plain');
    return;
  }
  const ext = path.extname(filePath);
  const ct = mimeTypes[ext] || 'application/octet-stream';
  res.writeHead(200, { 'Content-Type': ct });
  fs.createReadStream(filePath).pipe(res);
});

server.listen(PORT, () => {
  console.log('Сервер: http://localhost:' + PORT);
  console.log('Откройте в браузере и загрузите план.');
});
