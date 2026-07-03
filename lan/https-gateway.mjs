import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';

const args = new Map();
for (let index = 2; index < process.argv.length; index += 2) {
  args.set(process.argv[index], process.argv[index + 1]);
}

const listenHost = args.get('--host') || '0.0.0.0';
const listenPort = Number(args.get('--port') || '443');
const target = new URL(args.get('--target') || 'http://127.0.0.1:8090');
const pfxPath = args.get('--pfx');
const passPath = args.get('--passfile');

if (!pfxPath || !passPath) {
  throw new Error('Missing --pfx or --passfile.');
}

const server = https.createServer({
  pfx: fs.readFileSync(pfxPath),
  passphrase: fs.readFileSync(passPath, 'utf8').trim(),
}, (request, response) => {
  if (request.url === '/u-mail-health') {
    response.writeHead(200, { 'content-type': 'text/plain; charset=utf-8' });
    response.end('U-Mail host reached');
    return;
  }

  const headers = {
    ...request.headers,
    host: request.headers.host || 'u-mail.test',
    'x-forwarded-host': request.headers.host || 'u-mail.test',
    'x-forwarded-proto': 'https',
    'x-forwarded-port': String(listenPort),
    'x-forwarded-for': request.socket.remoteAddress || '',
  };

  const proxy = http.request({
    protocol: target.protocol,
    hostname: target.hostname,
    port: target.port,
    method: request.method,
    path: request.url,
    headers,
  }, upstream => {
    response.writeHead(upstream.statusCode || 502, upstream.headers);
    upstream.pipe(response);
  });

  proxy.on('error', () => {
    if (!response.headersSent) {
      response.writeHead(502, { 'content-type': 'text/plain; charset=utf-8' });
    }
    response.end('U-Mail is starting. Try again in a moment.');
  });

  request.pipe(proxy);
});

server.listen(listenPort, listenHost, () => {
  console.log(`U-Mail HTTPS gateway listening on ${listenHost}:${listenPort}, proxying to ${target.href}`);
});
