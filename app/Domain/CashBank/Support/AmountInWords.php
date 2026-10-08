<?php

namespace App\Domain\CashBank\Support;

use InvalidArgumentException;

/**
 * An amount as a person writes it on a cheque (§08-14).
 *
 * A cheque is signed against its words: the words are what the bank reads when
 * the figures are ambiguous, so this is not a formatting nicety — it is the
 * part of the document that has to be right. Two languages, because a cheque
 * written in Dhaka is read by people who write in either, and the desk should
 * not force an English-only slip on a Bengali-speaking office.
 *
 * The numbering is the one the country actually uses: crore, lakh, thousand in
 * the South Asian grouping (12,34,567 is twelve lakh thirty-four thousand five
 * hundred and sixty-seven), not million and billion.
 *
 * Framework-free on purpose: no config, no database, no translations — a
 * conversion you can reason about on its own and check digit by digit.
 */
class AmountInWords
{
    /** 0–19, the only English numbers a cheque needs spelled out one by one. */
    protected const EN_ONES = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
        'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
        'seventeen', 'eighteen', 'nineteen',
    ];

    protected const EN_TENS = [
        2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty',
        6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety',
    ];

    /**
     * 0–99 in Bangla. Bengali numbers above twenty are not built by joining a
     * tens word to a unit word the way English numbers are — একুশ is not "বিশ এক"
     * — so the table is written out. Spelling follows the standard orthography.
     */
    protected const BN_NUMBERS = [
        'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
        'দশ', 'এগারো', 'বারো', 'তেরো', 'চোদ্দ', 'পনেরো', 'ষোল', 'সতেরো', 'আঠারো', 'উনিশ',
        'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'ঊনত্রিশ',
        'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'ঊনচল্লিশ',
        'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'ঊনপঞ্চাশ',
        'পঞ্চাশ', 'একান্ন', 'বায়ান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'ঊনষাট',
        'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'ঊনসত্তর',
        'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'ঊনআশি',
        'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'ঊননব্বই',
        'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
    ];

    /** The hundred-prefixes Bangla writes as one word rather than two. */
    protected const BN_HUNDREDS = [
        1 => 'একশ', 2 => 'দুইশ', 3 => 'তিনশ', 4 => 'চারশ', 5 => 'পাঁচশ',
        6 => 'ছশ', 7 => 'সাতশ', 8 => 'আটশ', 9 => 'নয়শ',
    ];

    protected const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /**
     * "Taka forty-five lakh sixty thousand seven hundred and fifty and fifty
     * paisa only" — the words a bank reads.
     */
    public static function en(string|float|int $amount, string $currency = 'taka', string $fraction = 'paisa'): string
    {
        [$whole, $cents] = self::split($amount);

        if ($whole === 0 && $cents === 0) {
            return ucfirst($currency).' zero only';
        }

        $words = self::enInteger($whole);

        if ($whole > 0) {
            $words = ucfirst($currency).' '.$words;
        }

        if ($cents > 0) {
            $words = trim($words.' and '.self::enInteger($cents).' '.$fraction);
        }

        return trim($words.' only');
    }

    /**
     * "টাকা পঁয়তাল্লিশ লাখ ষাট হাজার সাতশ পঞ্চাশ এবং পঞ্চাশ পয়সা মাত্র"
     */
    public static function bn(string|float|int $amount, string $currency = 'টাকা', string $fraction = 'পয়সা'): string
    {
        [$whole, $cents] = self::split($amount);

        if ($whole === 0 && $cents === 0) {
            return $currency.' শূন্য মাত্র';
        }

        $words = $whole > 0 ? $currency.' '.self::bnInteger($whole) : '';

        if ($cents > 0) {
            $words = trim($words.' এবং '.self::bnInteger($cents).' '.$fraction);
        }

        return trim($words.' মাত্র');
    }

    /** The same figure in Bangla numerals, grouping kept: ১২,৩৪,৫৬৭.৫০ */
    public static function bnFigures(string|float|int $amount): string
    {
        [$whole, $cents] = self::split($amount);

        $grouped = self::groupSouthAsian((string) $whole);

        return self::bnDigits($grouped).'.'.self::bnDigits(str_pad((string) $cents, 2, '0', STR_PAD_LEFT));
    }

    /** 1234567 → "12,34,567" — lakh-wise grouping, the way the subcontinent counts. */
    public static function groupSouthAsian(string $whole): string
    {
        $negative = str_starts_with($whole, '-');

        if ($negative) {
            $whole = substr($whole, 1);
        }

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > 3) {
            $head = substr($whole, 0, -3);
            $tail = substr($whole, -3);

            $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head);
            $whole = $head.','.$tail;
        }

        return ($negative ? '-' : '').$whole;
    }

    public static function bnDigits(string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            self::BN_DIGITS,
            $value,
        );
    }

    /** Amount → [whole units, hundredths], refusing anything that is not money. */
    protected static function split(string|float|int $amount): array
    {
        if (is_string($amount) && str_contains($amount, ',')) {
            $amount = str_replace(',', '', $amount);
        }

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Cannot write \"{$amount}\" in words: it is not an amount.");
        }

        $value = round(abs((float) $amount), 2);
        $whole = (int) floor($value);
        $cents = (int) round(($value - $whole) * 100);

        // Rounding half a paisa up can carry into the whole: 1.995 → 2.00.
        if ($cents === 100) {
            $whole++;
            $cents = 0;
        }

        return [$whole, $cents];
    }

    protected static function enInteger(int $value): string
    {
        if ($value === 0) {
            return 'zero';
        }

        $crore = intdiv($value, 10000000);
        $value -= $crore * 10000000;
        $lakh = intdiv($value, 100000);
        $value -= $lakh * 100000;
        $thousand = intdiv($value, 1000);
        $value -= $thousand * 1000;
        $hundred = intdiv($value, 100);
        $rest = $value - $hundred * 100;

        $parts = [];

        if ($crore > 0) {
            $parts[] = $crore > 99 ? self::enInteger($crore).' crore' : self::enTwo($crore).' crore';
        }

        if ($lakh > 0) {
            $parts[] = self::enTwo($lakh).' lakh';
        }

        if ($thousand > 0) {
            $parts[] = self::enTwo($thousand).' thousand';
        }

        if ($hundred > 0) {
            $parts[] = self::enOnes($hundred).' hundred';
        }

        if ($rest > 0) {
            $words = self::enTwo($rest);

            if ($parts === []) {
                return $words;
            }

            // "two hundred and thirty-four", the way a cheque is written.
            return implode(' ', $parts).($hundred > 0 ? ' and '.$words : ' '.$words);
        }

        return implode(' ', $parts);
    }

    /** 0–19 by name, 20–99 tens-with-hyphen: twenty-one. */
    protected static function enTwo(int $value): string
    {
        if ($value < 20) {
            return self::enOnes($value);
        }

        $tens = intdiv($value, 10);
        $unit = $value - $tens * 10;

        return self::EN_TENS[$tens].($unit > 0 ? '-'.self::EN_ONES[$unit] : '');
    }

    protected static function enOnes(int $value): string
    {
        return self::EN_ONES[$value];
    }

    protected static function bnInteger(int $value): string
    {
        if ($value === 0) {
            return 'শূন্য';
        }

        $crore = intdiv($value, 10000000);
        $value -= $crore * 10000000;
        $lakh = intdiv($value, 100000);
        $value -= $lakh * 100000;
        $thousand = intdiv($value, 1000);
        $value -= $thousand * 1000;
        $hundred = intdiv($value, 100);
        $rest = $value - $hundred * 100;

        $parts = [];

        if ($crore > 0) {
            $parts[] = ($crore > 99 ? self::bnInteger($crore) : self::BN_NUMBERS[$crore]).' কোটি';
        }

        if ($lakh > 0) {
            $parts[] = self::BN_NUMBERS[$lakh].' লাখ';
        }

        if ($thousand > 0) {
            $parts[] = self::BN_NUMBERS[$thousand].' হাজার';
        }

        if ($hundred > 0) {
            // ২৩৪ is written চারশ চৌত্রিশ: the hundred is one word, the rest follows.
            $parts[] = self::BN_HUNDREDS[$hundred];
        }

        if ($rest > 0) {
            $parts[] = self::BN_NUMBERS[$rest];
        }

        return implode(' ', $parts);
    }
}
