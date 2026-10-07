/**
 * Builds the static design preview: each page body is wrapped in the shared
 * shell markup (mirroring the Blade partials) and written to /preview.
 *
 * Run:  node preview/src/build.mjs
 */
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { shellHeader, shellFooter } from './shell.mjs';
import { designSystem, dashboard, orders, orderDetail, pos, login, notFound } from './pages.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(here, '..');

const shellPages = [
    ['index.html', 'Design system', designSystem],
    ['dashboard.html', 'Dashboard', dashboard],
    ['orders.html', 'Sales orders', orders],
    ['order-detail.html', 'Order workspace', orderDetail],
    ['pos.html', 'POS terminal', pos],
];

for (const [file, title, renderer] of shellPages) {
    const html = `${shellHeader(title)}\n${renderer()}\n${shellFooter(file)}\n`;
    writeFileSync(resolve(outDir, file), html, 'utf8');
    console.log(`wrote preview/${file}`);
}

writeFileSync(resolve(outDir, 'login.html'), shellHeader('Sign in') + login(), 'utf8');
console.log('wrote preview/login.html');

writeFileSync(resolve(outDir, '404.html'), shellHeader('Page not found') + notFound(), 'utf8');
console.log('wrote preview/404.html');

mkdirSync(outDir, { recursive: true });
