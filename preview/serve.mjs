/**
 * Zero-dependency static server for the design preview.
 *   node preview/serve.mjs [port]
 * Binds 0.0.0.0 so the workspace preview proxy can reach it.
 */
import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const port = Number(process.argv[2] ?? 4173);

const types = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
};

createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://localhost');
        let path = normalize(decodeURIComponent(url.pathname)).replace(/^(\.\.[/\\])+/, '');
        if (path === '/' || path === '') path = '/index.html';

        let file = resolve(join(root, path));

        if (!file.startsWith(resolve(root))) {
            res.writeHead(403).end('Forbidden');
            return;
        }

        const info = await stat(file).catch(() => null);
        if (info?.isDirectory()) file = join(file, 'index.html');

        const body = await readFile(file);
        res.writeHead(200, {
            'Content-Type': types[extname(file)] ?? 'application/octet-stream',
            'Cache-Control': 'no-store',
        });
        res.end(body);
    } catch {
        res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end('<h1>404 — preview page not found</h1><p><a href="/">Back to the design preview</a></p>');
    }
}).listen(port, '0.0.0.0', () => {
    console.log(`Design preview running on http://0.0.0.0:${port}`);
});
