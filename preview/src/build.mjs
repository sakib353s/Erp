/**
 * Builds the static design preview: each page body is wrapped in the shared
 * shell markup (mirroring the Blade partials) and written to /preview.
 *
 * Run:  node preview/src/build.mjs
 */
import { writeFileSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { shellHeader, shellFooter } from './shell.mjs';
import { designSystem, dashboard, orders, orderDetail, customers, customerProfile, pos, packaging, labels, cashBank, bankRecon, cheques, expenses, recurringExpenses, pettyCash, pettyCashExpenses, pettyCashRequests, pettyCashReplenishment, cashCounts, cashCountSheet, bankCharges, expenseReport, cashReports, reportCentre, reportFamily,
    reportsCustom, reportsScheduled, settingsDesk, settingsBranch, maintenanceDesk,
    settingsLocalization, settingsTax,
    noticeBoard, taskBoard,
    login, notFound } from './pages.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const outDir = resolve(here, '..');

const shellPages = [
    ['index.html', 'Design system', designSystem],
    ['dashboard.html', 'Dashboard', dashboard],
    ['orders.html', 'Sales orders', orders],
    ['order-detail.html', 'Order workspace', orderDetail],
    ['customers.html', 'Customers (CRM)', customers],
    ['customer-profile.html', 'Customer 360', customerProfile],
    ['pos.html', 'POS terminal', pos],
    ['packaging.html', 'Packaging', packaging],
    ['labels.html', 'Label desk', labels],
    ['cash-bank.html', 'Cash & bank', cashBank],
    ['bank-recon.html', 'Bank reconciliation', bankRecon],
    ['cheques.html', 'Cheque register', cheques],
    ['expenses.html', 'Expense desk', expenses],
    ['expense-recurring.html', 'Recurring expenses', recurringExpenses],
    ['cash-counts.html', 'Cash counts', cashCounts],
    ['cash-count.html', 'Cash count sheet', cashCountSheet],
    ['bank-charges.html', 'Bank charges', bankCharges],
    ['expense-report.html', 'Expense report', expenseReport],
    ['cash-reports.html', 'Cash reports', cashReports],
    ['reports.html', 'Report centre', reportCentre],
    ['reports-family.html', 'Report family hub', reportFamily],
    ['reports-custom.html', 'Custom reports', reportsCustom],
    ['reports-scheduled.html', 'Scheduled reports', reportsScheduled],
    ['settings.html', 'Settings desk', settingsDesk],
    ['settings-branch.html', 'Branch settings', settingsBranch],
    ['settings-localization.html', 'Bengali settings', settingsLocalization],
    ['settings-tax.html', 'VAT & tax settings', settingsTax],
    ['maintenance.html', 'System maintenance', maintenanceDesk],
    ['notices.html', 'Notice board', noticeBoard],
    ['tasks.html', 'Tasks & projects', taskBoard],
    ['petty-cash.html', 'Petty cash', pettyCash],
    ['petty-cash-requests.html', 'Petty cash requests', pettyCashRequests],
    ['petty-cash-expenses.html', 'Petty cash expenses', pettyCashExpenses],
    ['petty-cash-replenishment.html', 'Petty cash replenishment', pettyCashReplenishment],
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

/*
 * The preview bar's own styles are the one part of this preview that never goes
 * to the app, and the built `assets/preview.css` is a copy of the Vite bundle
 * (app.css) rather than something this script compiles. So the chrome rules are
 * appended here, idempotently, instead of silently missing from the page.
 */
const chromeSource = readFileSync(resolve(here, 'preview.css'), 'utf8')
    .split('\n')
    .filter((line) => !line.trim().startsWith('@import'))
    .join('\n')
    .trim();
const builtCss = resolve(outDir, 'assets/preview.css');
const css = readFileSync(builtCss, 'utf8');

if (!css.includes('.erp-preview-bar')) {
    writeFileSync(builtCss, `${css.trimEnd()}\n\n${chromeSource}\n`, 'utf8');
    console.log('appended the preview chrome to assets/preview.css');
}
