<?php

namespace Database\Seeders;

use App\Domain\Foundation\Module;
use App\Domain\Foundation\Translation;
use App\Domain\Foundation\Widget;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * DB-driven translations (decision D21): English + Bengali for module
 * names, widget labels, foundation menu entries and common UI strings.
 * Labels are UI text — not business data — so seeding them is required
 * for the bilingual shell (spec section F/Q).
 */
class TranslationSeeder extends Seeder
{
    use WithoutModelEvents;

    /** group => key => [en, bn] */
    protected const ROWS = [
        'module' => [
            'module.dashboard' => ['Dashboard', 'ড্যাশবোর্ড'],
            'module.sales' => ['Sales', 'বিক্রয়'],
            'module.purchase' => ['Purchase', 'ক্রয়'],
            'module.inventory' => ['Inventory', 'ইনভেন্টরি'],
            'module.customers' => ['Customers', 'ক্রেতা'],
            'module.suppliers' => ['Suppliers', 'সরবরাহকারী'],
            'module.returns' => ['Returns', 'রিটার্ন'],
            'module.cash_bank' => ['Cash & Bank', 'ক্যাশ ও ব্যাংক'],
            'module.accounting' => ['Accounting', 'হিসাববিজ্ঞান'],
            'module.employee' => ['Employee', 'কর্মচারী'],
            'module.marketing' => ['Marketing', 'মার্কেটিং'],
            'module.business_management' => ['Business Management', 'ব্যবসা ব্যবস্থাপনা'],
            'module.reports' => ['Reports', 'রিপোর্ট'],
            'module.masters' => ['Masters', 'মাস্টার'],
            'module.settings' => ['Settings', 'সেটিংস'],
        ],

        'menu' => [
            'menu.utility.approvals' => ['Approval Inbox', 'অনুমোদন ইনবক্স'],
            'menu.utility.audit_log' => ['Audit Log', 'অডিট লগ'],
            'menu.utility.workflows' => ['Workflows', 'ওয়ার্কফ্লো'],
            'menu.utility.workflow_settings' => ['Workflow Settings', 'ওয়ার্কফ্লো সেটিংস'],
            'menu.header.notifications' => ['Notifications', 'বিজ্ঞপ্তি'],
            'menu.header.profile' => ['My Profile', 'আমার প্রোফাইল'],
        ],

        'widget' => [
            'widget.todays_sales' => ["Today's Sales", 'আজকের বিক্রয়'],
            'widget.todays_purchase' => ["Today's Purchase", 'আজকের ক্রয়'],
            'widget.todays_cash_position' => ["Today's Cash Position", 'আজকের নগদ অবস্থা'],
            'widget.todays_collection' => ["Today's Collection", 'আজকের আদায়'],
            'widget.todays_payments_due' => ["Today's Payments Due", 'আজকের পরিশোধবকেয়া'],
            'widget.todays_expense' => ["Today's Expense", 'আজকের খরচ'],
            'widget.todays_profit' => ["Today's Profit", 'আজকের মুনাফা'],
            'widget.receivable_aging' => ['Receivable Aging (0-30 / 31-60 / 61-90 / 90+)', 'প্রাপ্য বকেয়ার বয়স (০-৩০ / ৩১-৬০ / ৬১-৯০ / ৯০+)'],
            'widget.payable_aging' => ['Payable Aging (0-30 / 31-60 / 61-90 / 90+)', 'প্রদেয় বকেয়ার বয়স (০-৩০ / ৩১-৬০ / ৬১-৯০ / ৯০+)'],
            'widget.top_10_customers' => ['Top 10 Customers', 'শীর্ষ ১০ ক্রেতা'],
            'widget.top_10_products' => ['Top 10 Products', 'শীর্ষ ১০ পণ্য'],
            'widget.top_10_employees' => ['Top 10 Employees', 'শীর্ষ ১০ কর্মচারী'],
            'widget.pending_orders' => ['Pending Orders', 'মুলতবি অর্ডার'],
            'widget.pending_purchase_orders' => ['Pending Purchase Orders', 'মুলতবি ক্রয় অর্ডার'],
            'widget.pending_approvals' => ['Pending Approvals', 'মুলতবি অনুমোদন'],
            'widget.pending_returns' => ['Pending Returns', 'মুলতবি রিটার্ন'],
            'widget.pending_refunds' => ['Pending Refunds', 'মুলতবি রিফান্ড'],
            'widget.low_stock_alert' => ['Low Stock Alert', 'কম স্টক সতর্কতা'],
            'widget.out_of_stock_alert' => ['Out of Stock Alert', 'স্টক শেষ সতর্কতা'],
            'widget.expiring_products_alert' => ['Expiring Products Alert', 'মেয়াদ শেষের পণ্য সতর্কতা'],
            'widget.payment_reminders' => ['Payment Reminders', 'পরিশোধের স্মারক'],
            'widget.sales_chart' => ['Sales Chart (Daily / Weekly / Monthly)', 'বিক্রয় চার্ট (দৈনিক / সাপ্তাহিক / মাসিক)'],
            'widget.purchase_chart' => ['Purchase Chart', 'ক্রয় চার্ট'],
            'widget.cash_flow_chart' => ['Cash Flow Chart', 'ক্যাশ ফ্লো চার্ট'],
            'widget.branch_activity' => ['Branch Comparison & Recent Activity', 'শাখা তুলনা ও সাম্প্রতিক কার্যক্রম'],
        ],

        'common' => [
            'common.save' => ['Save', 'সংরক্ষণ'],
            'common.cancel' => ['Cancel', 'বাতিল'],
            'common.close' => ['Close', 'বন্ধ'],
            'common.back' => ['Back', 'পেছনে'],
            'common.create' => ['Create', 'তৈরি'],
            'common.edit' => ['Edit', 'সম্পাদনা'],
            'common.delete' => ['Delete', 'মুছুন'],
            'common.view' => ['View', 'দেখুন'],
            'common.search' => ['Search', 'অনুসন্ধান'],
            'common.filter' => ['Filter', 'ফিল্টার'],
            'common.reset' => ['Reset', 'রিসেট'],
            'common.export' => ['Export', 'এক্সপোর্ট'],
            'common.import' => ['Import', 'ইমপোর্ট'],
            'common.print' => ['Print', 'প্রিন্ট'],
            'common.download' => ['Download', 'ডাউনলোড'],
            'common.upload' => ['Upload', 'আপলোড'],
            'common.submit' => ['Submit', 'জমা'],
            'common.confirm' => ['Confirm', 'নিশ্চিত'],
            'common.approve' => ['Approve', 'অনুমোদন'],
            'common.reject' => ['Reject', 'প্রত্যাখ্যান'],
            'common.return' => ['Return', 'ফেরত'],
            'common.comment' => ['Comment', 'মন্তব্য'],
            'common.actions' => ['Actions', 'অ্যাকশন'],
            'common.status' => ['Status', 'স্ট্যাটাস'],
            'common.details' => ['Details', 'বিস্তারিত'],
            'common.name' => ['Name', 'নাম'],
            'common.code' => ['Code', 'কোড'],
            'common.branch' => ['Branch', 'শাখা'],
            'common.warehouse' => ['Warehouse', 'গুদাম'],
            'common.date' => ['Date', 'তারিখ'],
            'common.amount' => ['Amount', 'পরিমাণ'],
            'common.total' => ['Total', 'মোট'],
            'common.notes' => ['Notes', 'নোট'],
            'common.optional' => ['optional', 'ঐচ্ছিক'],
            'common.not_applicable' => ['N/A', 'প্রযোজ্য নয়'],
            'common.no_records' => ['No records yet.', 'এখনো কোনো রেকর্ড নেই।'],
            'common.loading' => ['Loading…', 'লোড হচ্ছে…'],
            'common.yes' => ['Yes', 'হ্যাঁ'],
            'common.no' => ['No', 'না'],
            'common.enabled' => ['Enabled', 'চালু'],
            'common.disabled' => ['Disabled', 'বন্ধ'],
        ],

        'status' => [
            'status.active' => ['Active', 'সক্রিয়'],
            'status.planned' => ['Planned', 'পরিকল্পিত'],
            'status.pending' => ['Pending', 'মুলতবি'],
            'status.approved' => ['Approved', 'অনুমোদিত'],
            'status.rejected' => ['Rejected', 'প্রত্যাখ্যাত'],
            'status.returned' => ['Returned', 'ফেরত'],
            'status.cancelled' => ['Cancelled', 'বাতিল'],
            'status.locked' => ['Locked', 'লক'],
            'status.disabled' => ['Disabled', 'নিষ্ক্রিয়'],
        ],

        'page' => [
            'page.dashboard.title' => ['Dashboard', 'ড্যাশবোর্ড'],
            'page.dashboard.onboarding' => ['Finish setting up', 'সেটআপ সম্পন্ন করুন'],
            'page.login.title' => ['Sign in', 'সাইন ইন'],
            'page.setup.title' => ['First-boot setup', 'প্রথম বুট সেটআপ'],
            'page.users.title' => ['User Management', 'ব্যবহারকারী ব্যবস্থাপনা'],
            'page.roles.title' => ['Roles & Permissions', 'ভূমিকা ও অনুমতি'],
            'page.branches.title' => ['Branch Settings', 'শাখা সেটিংস'],
            'page.warehouses.title' => ['Warehouses', 'গুদামসমূহ'],
            'page.approvals.title' => ['Approval Inbox', 'অনুমোদন ইনবক্স'],
            'page.audit.title' => ['Audit Log', 'অডিট লগ'],
            'page.workflows.title' => ['Approval Workflows', 'অনুমোদন ওয়ার্কফ্লো'],
            'page.documents.title' => ['Documents', 'ডকুমেন্ট'],
            'page.notifications.title' => ['Notifications', 'বিজ্ঞপ্তি'],
            'page.profile.title' => ['My Profile', 'আমার প্রোফাইল'],
        ],
    ];

    public function run(): void
    {
        foreach (self::ROWS as $group => $entries) {
            foreach ($entries as $key => [$en, $bn]) {
                Translation::updateOrCreate(
                    ['locale' => 'en', 'translation_group' => $group, 'translation_key' => $key],
                    ['value' => $en],
                );

                Translation::updateOrCreate(
                    ['locale' => 'bn', 'translation_group' => $group, 'translation_key' => $key],
                    ['value' => $bn],
                );
            }
        }

        // EN fallbacks for module + widget labels derived from the catalog
        // (Bengali rows above cover them; this fills any catalog addition).
        foreach (Module::query()->pluck('label_key') as $labelKey) {
            Translation::firstOrCreate(
                ['locale' => 'en', 'translation_group' => 'module', 'translation_key' => $labelKey],
                ['value' => Module::query()->where('label_key', $labelKey)->value('name') ?? $labelKey],
            );
        }

        foreach (Widget::query()->get(['code', 'label']) as $widget) {
            Translation::firstOrCreate(
                ['locale' => 'en', 'translation_group' => 'widget', 'translation_key' => 'widget.'.$widget->code],
                ['value' => $widget->label],
            );
        }
    }
}
