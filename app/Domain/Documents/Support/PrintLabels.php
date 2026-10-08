<?php

namespace App\Domain\Documents\Support;

/**
 * §16-23 — the printed label set, in the two languages this build ships.
 *
 * Only the *labels* change with the copy's locale. The numeral system and the
 * spelling-out of amounts remain the company's own localization settings
 * (§15-07), so a Bengali copy of a document from a company that prints Western
 * figures keeps Western figures: one setting decides numerals, the copy decides
 * the words around them.
 */
final class PrintLabels
{
    public const LOCALES = ['en' => 'English', 'bn' => 'বাংলা'];

    /** @return array<string, string> */
    public static function for(?string $locale): array
    {
        return self::SETS[$locale ?? 'en'] ?? self::SETS['en'];
    }

    public const SETS = [
        'en' => [
            'document' => 'Document',
            'date' => 'Date',
            'reference' => 'Reference',
            'subtotal' => 'Subtotal',
            'discount' => 'Discount',
            'tax' => 'Tax',
            'shipping' => 'Shipping',
            'rounding' => 'Rounding',
            'total' => 'Total',
            'paid' => 'Paid',
            'due' => 'Due',
            'notes' => 'Notes',
            'in_words' => 'In words',
            'signature' => 'Authorised signature',
            'received_by' => 'Received by',
            'page' => 'Page',
            'printed' => 'Printed',
            'by' => 'by',
            'copy' => 'Copy',
            'original' => 'Original',
            'continued' => 'continued',
            'rows' => 'rows',
            'no_rows' => 'Nothing to print for this selection — the period has no entries.',
            'amount' => 'Amount',
            'verified' => 'Verified',
        ],
        'bn' => [
            'document' => 'দলিল',
            'date' => 'তারিখ',
            'reference' => 'নম্বর',
            'subtotal' => 'উপমোট',
            'discount' => 'ছাড়',
            'tax' => 'কর',
            'shipping' => 'পরিবহন',
            'rounding' => 'রাউন্ডিং',
            'total' => 'মোট',
            'paid' => 'পরিশোধিত',
            'due' => 'বাকি',
            'notes' => 'নোট',
            'in_words' => 'কথায়',
            'signature' => 'অনুমোদিত স্বাক্ষর',
            'received_by' => 'গ্রহণকারী',
            'page' => 'পৃষ্ঠা',
            'printed' => 'মুদ্রিত',
            'by' => 'কর্তৃক',
            'copy' => 'কপি',
            'original' => 'মূল',
            'continued' => 'চলমান',
            'rows' => 'সারি',
            'no_rows' => 'এই নির্বাচনের জন্য ছাপানোর মতো কিছু নেই — সময়কালে কোনো এন্ট্রি নেই।',
            'amount' => 'পরিমাণ',
            'verified' => 'যাচাইকৃত',
        ],
    ];
}
