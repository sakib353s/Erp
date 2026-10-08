<?php

namespace Database\Seeders;

use App\Domain\Foundation\Translation;
use Illuminate\Database\Seeder;

/**
 * §16-50 — the bilingual dictionary the interface is translated from.
 *
 * Every visible string in the application that is not a piece of business data
 * (a customer's name, a product's SKU) is expected to live here, keyed by a
 * stable `translation_key` and written twice: English and Bangla. Views resolve a
 * string with `t('key', 'English fallback')`, so a row that is missing simply
 * falls back to the English it would otherwise have hard-coded — which is exactly
 * why adding a translation here is safe: it can never blank the screen.
 *
 * Why a seeder and not a fixture: the catalogue of strings grows with the
 * catalogue of screens, and a seeder is the one place that keeps the two in step
 * across installs. It is idempotent — `updateOrCreate` on the unique
 * (locale, group, key) — so running it again edits, never duplicates.
 *
 * What is covered here (and, more importantly, what the suite checks): the status
 * vocabulary (so a badge reads "বাকি" not "due"), the buttons and field labels on
 * every form, the document types the printers name, the notification copy, the
 * report titles, the settings groups and the navigation sections and modules. The
 * leaf menu items that have no row here keep their English label — translating
 * every one is the next pass, and the navigation builder's fallback means it is a
 * content addition, not a code change.
 */
class TranslationSeeder extends Seeder
{
    /**
     * group => [key => [en, bn]].
     *
     * @var array<string, array<string, array{0:string, 1:string}>>
     */
    protected const DICTIONARY = [
        'status' => [
            'draft' => ['Draft', 'খসড়া'],
            'sent' => ['Sent', 'প্রেরিত'],
            'viewed' => ['Viewed', 'দেখা হয়েছে'],
            'accepted' => ['Accepted', 'গৃহীত'],
            'declined' => ['Declined', 'প্রত্যাখ্যান'],
            'expired' => ['Expired', 'মেয়াদোত্তীর্ণ'],
            'converted' => ['Converted', 'রূপান্তরিত'],
            'issued' => ['Issued', 'জারিকৃত'],
            'partial' => ['Partially paid', 'আংশিক পরিশোধিত'],
            'paid' => ['Paid', 'পরিশোধিত'],
            'due' => ['Due', 'বাকি'],
            'overdue' => ['Overdue', 'বকেয়া'],
            'dispatched' => ['Dispatched', 'প্রেরিত'],
            'delivered' => ['Delivered', 'সরবরাহিত'],
            'returned' => ['Returned', 'ফেরত'],
            'processing' => ['Processing', 'প্রক্রিয়াধীন'],
            'approved' => ['Approved', 'অনুমোদিত'],
            'rejected' => ['Rejected', 'প্রত্যাখ্যাত'],
            'completed' => ['Completed', 'সম্পন্ন'],
            'working' => ['Working', 'চলমান'],
            'open' => ['Open', 'খোলা'],
            'pending' => ['Pending', 'মুলতুবি'],
            'not_configured' => ['Not configured', 'কনফিগার করা হয়নি'],
            'active' => ['Active', 'সক্রিয়'],
            'valid' => ['Valid', 'বৈধ'],
            'recorded' => ['Recorded', 'রেকর্ডকৃত'],
            'scheduled' => ['Scheduled', 'নির্ধারিত'],
            'queued' => ['Queued', 'সারিবদ্ধ'],
            'launched' => ['Launched', 'চালু'],
            'claimed' => ['Claimed', 'দাবিকৃত'],
            'due_soon' => ['Due soon', 'শীঘ্রই বাকি'],
            'expiring' => ['Expiring', 'মেয়াদ শেষ হচ্ছে'],
            'paused' => ['Paused', 'স্থগিত'],
            'retired' => ['Retired', 'অবসরপ্রাপ্ত'],
            'disposed' => ['Disposed', 'অপসারিত'],
            'cancelled' => ['Cancelled', 'বাতিল'],
            'void' => ['Void', 'বাতিল'],
            'voided' => ['Voided', 'বাতিল'],
            'loss' => ['Loss', 'ক্ষতি'],
            'short' => ['Short', 'ঘাটতি'],
            'refund' => ['Refund', 'ফেরত'],
            'replace' => ['Replace', 'প্রতিস্থাপন'],
            'repair' => ['Repair', 'মেরামত'],
        ],
        'btn' => [
            'save' => ['Save', 'সংরক্ষণ'],
            'cancel' => ['Cancel', 'বাতিল'],
            'create' => ['Create', 'তৈরি'],
            'new' => ['New', 'নতুন'],
            'delete' => ['Delete', 'মুছে ফেলুন'],
            'edit' => ['Edit', 'সম্পাদনা'],
            'search' => ['Search', 'অনুসন্ধান'],
            'export' => ['Export', 'রপ্তানি'],
            'print' => ['Print', 'মুদ্রণ'],
            'submit' => ['Submit', 'জমা দিন'],
            'confirm' => ['Confirm', 'নিশ্চিত করুন'],
            'approve' => ['Approve', 'অনুমোদন'],
            'reject' => ['Reject', 'প্রত্যাখ্যান'],
            'back' => ['Back', 'পিছনে'],
            'next' => ['Next', 'পরবর্তী'],
            'close' => ['Close', 'বন্ধ'],
            'add' => ['Add', 'যোগ করুন'],
            'remove' => ['Remove', 'সরিয়ে দিন'],
            'view' => ['View', 'দেখুন'],
            'download' => ['Download', 'ডাউনলোড'],
            'send' => ['Send', 'প্রেরণ'],
            'yes' => ['Yes', 'হ্যাঁ'],
            'no' => ['No', 'না'],
            'filter' => ['Filter', 'ফিল্টার'],
            'reset' => ['Reset', 'রিসেট'],
            'update' => ['Update', 'আপডেট'],
            'generate' => ['Generate', 'তৈরি করুন'],
        ],
        'field' => [
            'name' => ['Name', 'নাম'],
            'code' => ['Code', 'কোড'],
            'phone' => ['Phone', 'ফোন'],
            'email' => ['Email', 'ইমেইল'],
            'address' => ['Address', 'ঠিকানা'],
            'date' => ['Date', 'তারিখ'],
            'amount' => ['Amount', 'পরিমাণ'],
            'quantity' => ['Quantity', 'পরিমাণ'],
            'price' => ['Price', 'মূল্য'],
            'total' => ['Total', 'মোট'],
            'customer' => ['Customer', 'গ্রাহক'],
            'supplier' => ['Supplier', 'সরবরাহকারী'],
            'product' => ['Product', 'পণ্য'],
            'branch' => ['Branch', 'শাখা'],
            'status' => ['Status', 'অবস্থা'],
            'notes' => ['Notes', 'নোট'],
            'description' => ['Description', 'বিবরণ'],
            'warehouse' => ['Warehouse', 'গুদাম'],
            'invoice' => ['Invoice', 'চালান'],
            'order' => ['Order', 'অর্ডার'],
            'challan' => ['Challan', 'চালান'],
            'balance' => ['Balance', 'ব্যালেন্স'],
            'due' => ['Due', 'বাকি'],
            'paid' => ['Paid', 'পরিশোধিত'],
            'discount' => ['Discount', 'ছাড়'],
            'tax' => ['Tax', 'কর'],
        ],
        'doc' => [
            'invoice' => ['Invoice', 'চালান'],
            'quotation' => ['Quotation', 'উদ্ধৃতি'],
            'delivery_challan' => ['Delivery Challan', 'ডেলিভারি চালান'],
            'purchase_order' => ['Purchase Order', 'ক্রয় অর্ডার'],
            'purchase_bill' => ['Purchase Bill', 'ক্রয় বিল'],
            'receipt' => ['Receipt', 'রসিদ'],
            'payment' => ['Payment', 'পেমেন্ট'],
            'warranty' => ['Warranty', 'ওয়ারেন্টি'],
            'credit_note' => ['Credit Note', 'ক্রেডিট নোট'],
            'debit_note' => ['Debit Note', 'ডেবিট নোট'],
            'proforma' => ['Proforma', 'প্রোফর্মা'],
            'statement' => ['Statement', 'বিবরণী'],
            'report' => ['Report', 'প্রতিবেদন'],
            'label' => ['Label', 'লেবেল'],
        ],
        'notification' => [
            'invoice_paid' => ['Invoice paid', 'চালান পরিশোধিত'],
            'order_placed' => ['New order placed', 'নতুন অর্ডার হয়েছে'],
            'challan_delivered' => ['Challan delivered', 'চালান সরবরাহ করা হয়েছে'],
            'warranty_claimed' => ['Warranty claimed', 'ওয়ারেন্টি দাবি করা হয়েছে'],
            'payment_received' => ['Payment received', 'পেমেন্ট প্রাপ্ত'],
            'stock_low' => ['Stock running low', 'স্টক কমে যাচ্ছে'],
            'expiry_soon' => ['Stock expiring soon', 'স্টক শীঘ্রই মেয়াদোত্তীর্ণ'],
            'meeting_reminder' => ['Meeting reminder', 'সভার রিমাইন্ডার'],
        ],
        'report' => [
            'sales_summary' => ['Sales summary', 'বিক্রয় সারাংশ'],
            'outstanding' => ['Outstanding', 'বকেয়া'],
            'stock_report' => ['Stock report', 'স্টক প্রতিবেদন'],
            'purchase_report' => ['Purchase report', 'ক্রয় প্রতিবেদন'],
            'profit_loss' => ['Profit & loss', 'লাভ ও ক্ষতি'],
            'tax_report' => ['Tax report', 'কর প্রতিবেদন'],
            'aging' => ['Receivable aging', 'পাওনা বয়স'],
            'cash_flow' => ['Cash flow', 'নগদ প্রবাহ'],
        ],
        'setting' => [
            'general' => ['General', 'সাধারণ'],
            'localization' => ['Localization', 'স্থানীয়করণ'],
            'company' => ['Company', 'কোম্পানি'],
            'security' => ['Security', 'নিরাপত্তা'],
            'notifications' => ['Notifications', 'নোটিফিকেশন'],
            'backups' => ['Backups', 'ব্যাকআপ'],
            'maintenance' => ['Maintenance', 'রক্ষণাবেক্ষণ'],
            'integrations' => ['Integrations', 'ইন্টিগ্রেশন'],
            'api' => ['API', 'API'],
        ],
        'nav' => [
            'dashboard' => ['Dashboard', 'ড্যাশবোর্ড'],
            'my_work' => ['My work', 'আমার কাজ'],
            'sell' => ['Sell', 'বিক্রয়'],
            'buy_stock' => ['Buy & stock', 'ক্রয় ও স্টক'],
            'money' => ['Money', 'অর্থ'],
            'people' => ['People', 'ব্যক্তি'],
            'insight' => ['Insight', 'অন্তর্দৃষ্টি'],
            'governance' => ['Governance', 'সুশাসন'],
            'configuration' => ['Configuration', 'কনফিগারেশন'],
        ],
        'module' => [
            'dashboard' => ['Dashboard', 'ড্যাশবোর্ড'],
            'sales' => ['Sales', 'বিক্রয়'],
            'purchase' => ['Purchase', 'ক্রয়'],
            'inventory' => ['Inventory', 'ইনভেন্টরি'],
            'customers' => ['Customers', 'গ্রাহক'],
            'suppliers' => ['Suppliers', 'সরবরাহকারী'],
            'returns' => ['Returns', 'ফেরত'],
            'cash_bank' => ['Cash & Bank', 'নগদ ও ব্যাংক'],
            'accounting' => ['Accounting', 'হিসাব'],
            'employee' => ['Employees', 'কর্মচারী'],
            'marketing' => ['Marketing', 'মার্কেটিং'],
            'business_management' => ['Business', 'ব্যবসা'],
            'reports' => ['Reports', 'প্রতিবেদন'],
            'masters' => ['Masters', 'মাস্টার'],
            'settings' => ['Settings', 'সেটিংস'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DICTIONARY as $group => $entries) {
            foreach ($entries as $key => [$en, $bn]) {
                $this->put('en', $group, $key, $en);
                $this->put('bn', $group, $key, $bn);
            }
        }
    }

    /**
     * The full key — `status.paid`, `doc.invoice`, `module.sales` — is what the
     * views look up (`t('status.paid', 'Paid')`), so the stored `translation_key`
     * must be that whole string. `translation_group` keeps the surface it belongs
     * to for reporting, but the lookup never combines the two.
     */
    protected function put(string $locale, string $group, string $key, string $value): void
    {
        Translation::updateOrCreate(
            ['locale' => $locale, 'translation_group' => $group, 'translation_key' => $group.'.'.$key],
            ['value' => $value],
        );
    }
}
