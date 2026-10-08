<?php

namespace Database\Seeders;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\FiscalYear;
use App\Domain\Masters\Bank;
use App\Domain\Masters\CancelReason;
use App\Domain\Masters\Courier;
use App\Domain\Masters\District;
use App\Domain\Masters\Holiday;
use App\Domain\Masters\LeaveType;
use App\Domain\Masters\PaymentMethod;
use App\Domain\Masters\ReturnReason;
use App\Domain\Masters\SmsProvider;
use App\Domain\Masters\Unit;
use App\Domain\Masters\Upazila;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * STRUCTURAL reference data only (spec §0.3 / §14): the 64 Bangladesh
 * districts + a reference upazila list, a core payment-method registry,
 * national holidays, default leave types, courier partners, units, banks
 * (public SWIFT/BEFTN metadata), SMS/return/cancel reason registries, and
 * the current fiscal year. Never customers, products, invoices, or any
 * fake business transactions.
 */
class ReferenceDataSeeder extends Seeder
{
    use WithoutModelEvents;

    /** All 64 BD districts: code, name, division. */
    protected const DISTRICTS = [
        ['DHK', 'Dhaka', 'Dhaka'],
        ['GAZ', 'Gazipur', 'Dhaka'],
        ['NAR', 'Narayanganj', 'Dhaka'],
        ['NRS', 'Narsingdi', 'Dhaka'],
        ['TAN', 'Tangail', 'Dhaka'],
        ['MAN', 'Manikganj', 'Dhaka'],
        ['MUN', 'Munshiganj', 'Dhaka'],
        ['FAR', 'Faridpur', 'Dhaka'],
        ['GOR', 'Gopalganj', 'Dhaka'],
        ['MAD', 'Madaripur', 'Dhaka'],
        ['RAJ', 'Rajbari', 'Dhaka'],
        ['SHO', 'Shariatpur', 'Dhaka'],
        ['KIS', 'Kishoreganj', 'Dhaka'],
        ['BBA', 'Brahmanbaria', 'Chattogram'],
        ['CHA', 'Chattogram', 'Chattogram'],
        ['COX', 'Coxs Bazar', 'Chattogram'],
        ['COM', 'Comilla', 'Chattogram'],
        ['FEN', 'Feni', 'Chattogram'],
        ['LAK', 'Lakshmipur', 'Chattogram'],
        ['NOA', 'Noakhali', 'Chattogram'],
        ['CHB', 'Chandpur', 'Chattogram'],
        ['HAB', 'Habiganj', 'Sylhet'],
        ['MAU', 'Moulvibazar', 'Sylhet'],
        ['SUN', 'Sunamganj', 'Sylhet'],
        ['SYL', 'Sylhet', 'Sylhet'],
        ['JHA', 'Jashore', 'Khulna'],
        ['KHU', 'Khulna', 'Khulna'],
        ['SAT', 'Satkhira', 'Khulna'],
        ['BAG', 'Bagerhat', 'Khulna'],
        ['NAR-S', 'Narail', 'Khulna'],
        ['KUS', 'Kushtia', 'Khulna'],
        ['JHE', 'Jhenaidah', 'Khulna'],
        ['MAG', 'Magura', 'Khulna'],
        ['MEH', 'Meherpur', 'Khulna'],
        ['CHA-K', 'Chuadanga', 'Khulna'],
        ['RAN', 'Rangpur', 'Rangpur'],
        ['DIN', 'Dinajpur', 'Rangpur'],
        ['GAI', 'Gaibandha', 'Rangpur'],
        ['KUR', 'Kurigram', 'Rangpur'],
        ['LAL', 'Lalmonirhat', 'Rangpur'],
        ['NIL', 'Nilphamari', 'Rangpur'],
        ['PAN', 'Panchagarh', 'Rangpur'],
        ['THA', 'Thakurgaon', 'Rangpur'],
        ['BOG', 'Bogura', 'Rajshahi'],
        ['JOY', 'Joypurhat', 'Rajshahi'],
        ['NAO', 'Naogaon', 'Rajshahi'],
        ['NAT', 'Natore', 'Rajshahi'],
        ['CHAP', 'Chapainawabganj', 'Rajshahi'],
        ['PAB', 'Pabna', 'Rajshahi'],
        ['RAJ-S', 'Rajshahi', 'Rajshahi'],
        ['SIR', 'Sirajganj', 'Rajshahi'],
        ['BAR', 'Barishal', 'Barishal'],
        ['JHA-K', 'Jhalokati', 'Barishal'],
        ['BHO', 'Bhola', 'Barishal'],
        ['PAT', 'Patuakhali', 'Barishal'],
        ['PIRO', 'Pirojpur', 'Barishal'],
        ['BARG', 'Barguna', 'Barishal'],
        ['JAM', 'Jamalpur', 'Mymensingh'],
        ['NET', 'Netrokona', 'Mymensingh'],
        ['MYM', 'Mymensingh', 'Mymensingh'],
        ['SER', 'Sherpur', 'Mymensingh'],
        ['BAN', 'Bandarban', 'Chattogram'],
        ['RHA', 'Rangamati', 'Chattogram'],
        ['KHAGR', 'Khagrachhari', 'Chattogram'],
    ];

    /**
     * Reference upazila list (district code => [code, name, name_bn?]).
     * Structural administrative data only — at least the sadar/upazila
     * headquarters of every district, plus the full upazila set for the
     * major commercial districts used by address forms.
     */
    protected const UPAZILAS = [
        'DHK' => [
            ['DHK-01', 'Dhaka Sadar', 'ঢাকা সদর'],
            ['DHK-02', 'Savar', 'সাভার'],
            ['DHK-03', 'Dhamrai', 'ধামরাই'],
            ['DHK-04', 'Keraniganj', 'কেরানীগঞ্জ'],
            ['DHK-05', 'Nawabganj', 'নবাবগঞ্জ'],
            ['DHK-06', 'Dohar', 'দোহার'],
        ],
        'GAZ' => [['GAZ-01', 'Gazipur Sadar', 'গাজীপুর সদর'], ['GAZ-02', 'Kaliakair', 'কালিয়াকৈর'], ['GAZ-03', 'Kapasia', 'কাপাসিয়া'], ['GAZ-04', 'Sreepur', 'শ্রীপুর']],
        'NAR' => [['NAR-01', 'Narayanganj Sadar', 'নারায়ণগঞ্জ সদর'], ['NAR-02', 'Bandar', 'বন্দর'], ['NAR-03', 'Rupganj', 'রূপগঞ্জ'], ['NAR-04', 'Sonargaon', 'সোনারগাঁও']],
        'NRS' => [['NRS-01', 'Narsingdi Sadar', 'নরসিংদী সদর']],
        'TAN' => [['TAN-01', 'Tangail Sadar', 'টাঙ্গাইল সদর']],
        'MAN' => [['MAN-01', 'Manikganj Sadar', 'মানিকগঞ্জ সদর']],
        'MUN' => [['MUN-01', 'Munshiganj Sadar', 'মুন্সিগঞ্জ সদর']],
        'FAR' => [['FAR-01', 'Faridpur Sadar', 'ফরিদপুর সদর']],
        'GOR' => [['GOR-01', 'Gopalganj Sadar', 'গোপালগঞ্জ সদর']],
        'MAD' => [['MAD-01', 'Madaripur Sadar', 'মাদারীপুর সদর']],
        'RAJ' => [['RAJ-01', 'Rajbari Sadar', 'রাজবাড়ী সদর']],
        'SHO' => [['SHO-01', 'Shariatpur Sadar', 'শরীয়তপুর সদর']],
        'KIS' => [['KIS-01', 'Kishoreganj Sadar', 'কিশোরগঞ্জ সদর']],
        'BBA' => [['BBA-01', 'Brahmanbaria Sadar', 'ব্রাহ্মণবাড়িয়া সদর']],
        'CHA' => [
            ['CHA-01', 'Chattogram Sadar', 'চট্টগ্রাম সদর'],
            ['CHA-02', 'Pahartali', 'পাহাড়তলী'],
            ['CHA-03', 'Hathazari', 'হাটহাজারী'],
            ['CHA-04', 'Sitakunda', 'সীতাকুণ্ড'],
            ['CHA-05', 'Mirsharai', 'মীরসরাই'],
            ['CHA-06', 'Patiya', 'পটিয়া'],
            ['CHA-07', 'Rangunia', 'রাঙ্গুনিয়া'],
            ['CHA-08', 'Sandwip', 'সন্দ্বীপ'],
        ],
        'COX' => [['COX-01', 'Coxs Bazar Sadar', 'কক্সবাজার সদর']],
        'COM' => [['COM-01', 'Comilla Sadar', 'কুমিল্লা সদর']],
        'FEN' => [['FEN-01', 'Feni Sadar', 'ফেনী সদর']],
        'LAK' => [['LAK-01', 'Lakshmipur Sadar', 'লক্ষ্মীপুর সদর']],
        'NOA' => [['NOA-01', 'Noakhali Sadar', 'নোয়াখালী সদর']],
        'CHB' => [['CHB-01', 'Chandpur Sadar', 'চাঁদপুর সদর']],
        'HAB' => [['HAB-01', 'Habiganj Sadar', 'হবিগঞ্জ সদর']],
        'MAU' => [['MAU-01', 'Moulvibazar Sadar', 'মৌলভীবাজার সদর']],
        'SUN' => [['SUN-01', 'Sunamganj Sadar', 'সুনামগঞ্জ সদর']],
        'SYL' => [['SYL-01', 'Sylhet Sadar', 'সিলেট সদর']],
        'JHA' => [['JHA-01', 'Jashore Sadar', 'যশোর সদর']],
        'KHU' => [['KHU-01', 'Khulna Sadar', 'খুলনা সদর']],
        'SAT' => [['SAT-01', 'Satkhira Sadar', 'সাতক্ষীরা সদর']],
        'BAG' => [['BAG-01', 'Bagerhat Sadar', 'বাগেরহাট সদর']],
        'NAR-S' => [['NAR-S-01', 'Narail Sadar', 'নড়াইল সদর']],
        'KUS' => [['KUS-01', 'Kushtia Sadar', 'কুষ্টিয়া সদর']],
        'JHE' => [['JHE-01', 'Jhenaidah Sadar', 'ঝিনাইদহ সদর']],
        'MAG' => [['MAG-01', 'Magura Sadar', 'মাগুরা সদর']],
        'MEH' => [['MEH-01', 'Meherpur Sadar', 'মেহেরপুর সদর']],
        'CHA-K' => [['CHA-K-01', 'Chuadanga Sadar', 'চুয়াডাঙ্গা সদর']],
        'RAN' => [['RAN-01', 'Rangpur Sadar', 'রংপুর সদর']],
        'DIN' => [['DIN-01', 'Dinajpur Sadar', 'দিনাজপুর সদর']],
        'GAI' => [['GAI-01', 'Gaibandha Sadar', 'গাইবান্ধা সদর']],
        'KUR' => [['KUR-01', 'Kurigram Sadar', 'কুড়িগ্রাম সদর']],
        'LAL' => [['LAL-01', 'Lalmonirhat Sadar', 'লালমনিরহাট সদর']],
        'NIL' => [['NIL-01', 'Nilphamari Sadar', 'নীলফামারী সদর']],
        'PAN' => [['PAN-01', 'Panchagarh Sadar', 'পঞ্চগড় সদর']],
        'THA' => [['THA-01', 'Thakurgaon Sadar', 'ঠাকুরগাঁও সদর']],
        'BOG' => [['BOG-01', 'Bogura Sadar', 'বগুড়া সদর']],
        'JOY' => [['JOY-01', 'Joypurhat Sadar', 'জয়পুরহাট সদর']],
        'NAO' => [['NAO-01', 'Naogaon Sadar', 'নওগাঁ সদর']],
        'NAT' => [['NAT-01', 'Natore Sadar', 'নাটোর সদর']],
        'CHAP' => [['CHAP-01', 'Chapainawabganj Sadar', 'চাঁপাইনবাবগঞ্জ সদর']],
        'PAB' => [['PAB-01', 'Pabna Sadar', 'পাবনা সদর']],
        'RAJ-S' => [['RAJ-S-01', 'Rajshahi Sadar', 'রাজশাহী সদর']],
        'SIR' => [['SIR-01', 'Sirajganj Sadar', 'সিরাজগঞ্জ সদর']],
        'BAR' => [['BAR-01', 'Barishal Sadar', 'বরিশাল সদর']],
        'JHA-K' => [['JHA-K-01', 'Jhalokati Sadar', 'ঝালকাঠি সদর']],
        'BHO' => [['BHO-01', 'Bhola Sadar', 'ভোলা সদর']],
        'PAT' => [['PAT-01', 'Patuakhali Sadar', 'পটুয়াখালী সদর']],
        'PIRO' => [['PIRO-01', 'Pirojpur Sadar', 'পিরোজপুর সদর']],
        'BARG' => [['BARG-01', 'Barguna Sadar', 'বরগুনা সদর']],
        'JAM' => [['JAM-01', 'Jamalpur Sadar', 'জামালপুর সদর']],
        'NET' => [['NET-01', 'Netrokona Sadar', 'নেত্রকোণা সদর']],
        'MYM' => [['MYM-01', 'Mymensingh Sadar', 'ময়মনসিংহ সদর']],
        'SER' => [['SER-01', 'Sherpur Sadar', 'শেরপুর সদর']],
        'BAN' => [['BAN-01', 'Bandarban Sadar', 'বান্দরবান সদর']],
        'RHA' => [['RHA-01', 'Rangamati Sadar', 'রাঙ্গামাটি সদর']],
        'KHAGR' => [['KHAGR-01', 'Khagrachhari Sadar', 'খাগড়াছড়ি সদর']],
    ];

    /** Unit-of-measure registry (structural conversion base). */
    protected const UNITS = [
        ['PCS', 'Piece', 'pc'],
        ['KG', 'Kilogram', 'kg'],
        ['G', 'Gram', 'g'],
        ['M', 'Metre', 'm'],
        ['L', 'Litre', 'L'],
        ['BOX', 'Box', 'box'],
        ['PKT', 'Packet', 'pkt'],
        ['DOZ', 'Dozen', 'dz'],
        ['SET', 'Set', 'set'],
    ];

    /** Major BD banks — public SWIFT/BEFTN routing metadata only. */
    protected const BANKS = [
        ['DBBL', 'Dutch-Bangla Bank', 'DBBLBDDH', '090'],
        ['BRAC', 'BRAC Bank', 'BRAKBDDH', '060'],
        ['EBL', 'Eastern Bank', 'EBLDBDDH', '095'],
        ['CITY', 'City Bank', 'CIBLBDDH', '084'],
        ['IBBL', 'Islami Bank Bangladesh', 'IBBLBDDH', '012'],
        ['AIBL', 'Al-Arafah Islami Bank', 'ARABBDDH', '011'],
        ['UCB', 'United Commercial Bank', 'UCBLBDDH', '071'],
        ['BKB', 'Bangladesh Krishi Bank', 'BKBIBDDH', '020'],
        ['RUPALI', 'Rupali Bank', 'RUPABDDH', '021'],
        ['SONALI', 'Sonali Bank', 'SBLBBDDH', '022'],
        ['JANATA', 'Janata Bank', 'JANBBDDH', '018'],
        ['AGRANI', 'Agrani Bank', 'AGRABBDDH', '025'],
        ['ISLAMI-S', 'Islami Bank (Special)', 'IBBLBDDH', '012'],
        ['HSBC', 'HSBC Bangladesh', 'HSBDDH Dhaka', '140'],
        ['STAN', 'Standard Chartered', 'SCBDDHDH', '140'],
        ['CITY-UK', 'Citibank N.A.', 'CITIBDDH', '140'],
    ];

    /** SMS provider registry — always not_configured until real creds saved. */
    protected const SMS_PROVIDERS = [
        ['BULKSMS', 'BulkSMS BD', 'https://bulksmsbd.net/api/v3/sms/send'],
        ['ALPHA', 'Alpha SMS', 'https://sms.alpha.net.bd/api/send'],
        ['GP', 'Grameenphone Bulk', null],
        ['ROBI', 'Robi Bulk', null],
        ['TELETALK', 'Teletalk Bulk', null],
    ];

    protected const RETURN_REASONS = [
        ['DEFECTIVE', 'Defective product', false, 10],
        ['WRONG-ITEM', 'Wrong item delivered', false, 20],
        ['DAMAGED', 'Damaged in transit', true, 30],
        ['NOT-AS-DESCRIBED', 'Not as described', false, 40],
        ['LATE', 'Late delivery', false, 50],
        ['SIZE', 'Size / fit issue', false, 60],
        ['OTHER', 'Other', false, 90],
    ];

    protected const CANCEL_REASONS = [
        ['CUSTOMER', 'Customer cancelled', 10],
        ['OUT-OF-STOCK', 'Out of stock', 20],
        ['PAYMENT', 'Payment failed', 30],
        ['DUPLICATE', 'Duplicate order', 40],
        ['FRAUD', 'Fraud / suspicious', 50],
        ['OTHER', 'Other', 90],
    ];

    public function run(): void
    {
        $company = Company::current();

        // --- 64 districts (global reference — no company_id) ---
        $districtIds = [];
        foreach (self::DISTRICTS as $i => [$code, $name, $division]) {
            $district = District::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'division' => $division,
                    'sort' => ($i + 1) * 10,
                    'is_active' => true,
                ],
            );
            $districtIds[$code] = $district->id;
        }

        // --- Reference upazila list (structural admin data) ---
        foreach (self::UPAZILAS as $districtCode => $rows) {
            $districtId = $districtIds[$districtCode] ?? null;

            if ($districtId === null) {
                continue;
            }

            foreach ($rows as $j => $row) {
                $code = $row[0];
                $name = $row[1];
                $nameBn = $row[2] ?? null;

                Upazila::updateOrCreate(
                    ['district_id' => $districtId, 'code' => $code],
                    [
                        'name' => $name,
                        'name_bn' => $nameBn,
                        'sort' => ($j + 1) * 10,
                        'is_active' => true,
                    ],
                );
            }
        }

        if ($company === null) {
            return; // company-scoped rows wait until first boot
        }

        // --- Units of measure (structural) ---
        foreach (self::UNITS as [$code, $name, $symbol]) {
            Unit::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'symbol' => $symbol,
                    'base_unit' => $code === 'PCS' ? null : 'PCS',
                    'conversion_factor' => $code === 'PCS' ? null : 1,
                    'is_active' => true,
                ],
            );
        }

        // --- BD bank registry (public SWIFT/BEFTN metadata) ---
        foreach (self::BANKS as [$code, $name, $swift, $routing]) {
            Bank::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'swift_code' => $swift,
                    'routing_number' => $routing,
                    'website' => null,
                    'is_active' => true,
                ],
            );
        }

        // --- SMS providers (truthful not_configured until real creds) ---
        foreach (self::SMS_PROVIDERS as [$code, $name, $endpoint]) {
            SmsProvider::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'api_endpoint' => $endpoint,
                    'sender_id' => null,
                    'config_status' => 'not_configured',
                    'is_active' => true,
                ],
            );
        }

        // --- Return / cancel reason registries ---
        foreach (self::RETURN_REASONS as [$code, $name, $inspect, $sort]) {
            ReturnReason::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'requires_inspection' => $inspect,
                    'is_active' => true,
                    'sort' => $sort,
                ],
            );
        }

        foreach (self::CANCEL_REASONS as [$code, $name, $sort]) {
            CancelReason::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'is_active' => true,
                    'sort' => $sort,
                ],
            );
        }

        // --- Payment methods (BD registry) ---
        $methods = [
            ['CASH', 'Cash', null, false, true],
            ['BKASH', 'bKash', 'bkash', true, false],
            ['NAGAD', 'Nagad', 'nagad', true, false],
            ['ROCKET', 'Rocket', 'rocket', true, false],
            ['UPAY', 'Upay', 'upay', true, false],
            ['CARD', 'Card', 'card', true, false],
            ['CHEQUE', 'Cheque', 'cheque', true, false],
            ['BEFTN', 'BEFTN', 'beftn', true, false],
            ['TRANSFER', 'Bank Transfer', 'transfer', true, false],
        ];

        foreach ($methods as $i => [$code, $name, $provider, $requiresRef, $isDefault]) {
            PaymentMethod::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'provider' => $provider,
                    'requires_reference' => $requiresRef,
                    'is_default' => $isDefault,
                    'is_active' => true,
                    'sort' => ($i + 1) * 10,
                ],
            );
        }

        // --- BD national / public holidays (reference list) ---
        $year = (int) now()->year;
        $holidays = [
            [$year.'-02-21', 'Language Martyrs Day', 'ভাষা শহীদ দিবস', 'national', true],
            [$year.'-03-26', 'Independence Day', 'স্বাধীনতা দিবস', 'national', true],
            [$year.'-04-14', 'Pahela Baishakh', 'পহেলা বৈশাখ', 'public', true],
            [$year.'-05-01', 'May Day', 'মে দিবস', 'public', true],
            [$year.'-06-15', 'National Mourning Day', 'শোক দিবস', 'national', true],
            [$year.'-12-16', 'Victory Day', 'বিজয় দিবস', 'national', true],
            [$year.'-12-25', 'Christmas Day', 'বড়দিন', 'public', true],
        ];

        foreach ($holidays as [$date, $name, $nameBn, $type, $recurring]) {
            Holiday::updateOrCreate(
                ['company_id' => $company->id, 'date' => $date],
                [
                    'name' => $name,
                    'name_bn' => $nameBn,
                    'type' => $type,
                    'is_recurring' => $recurring,
                    'is_active' => true,
                ],
            );
        }

        // --- Leave types ---
        $leaveTypes = [
            ['CL', 'Casual Leave', 12, true],
            ['SL', 'Sick Leave', 14, true],
            ['EL', 'Earned Leave', 10, true],
            ['ML', 'Maternity Leave', 168, true],
            ['UL', 'Unpaid Leave', 0, false],
        ];

        foreach ($leaveTypes as [$code, $name, $days, $paid]) {
            LeaveType::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'default_days' => $days,
                    'is_paid' => $paid,
                    'is_active' => true,
                ],
            );
        }

        // --- Courier partners (registry rows; credentials live in settings) ---
        // Normalize the legacy misspelling so the provider adapters match
        // exactly one row per brand (02-92).
        Courier::query()->where('code', 'SUNDBAN')->update(['code' => 'SUNDARBAN']);

        $couriers = [
            ['PATHAO', 'Pathao'],
            ['REDX', 'RedX'],
            ['STEADFAST', 'Steadfast'],
            ['PAPERFLY', 'Paperfly'],
            ['ECOURIER', 'E-Courier'],
            ['SUNDARBAN', 'Sundarban'],
            ['SAPARIBAHAN', 'SA Paribahan'],
        ];

        foreach ($couriers as $i => [$code, $name]) {
            Courier::updateOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'service_status' => 'operational',
                    'configuration_status' => 'not_configured',
                    'is_active' => true,
                    'sort' => ($i + 1) * 10,
                ],
            );
        }

        // --- Current fiscal year (BD default starts July) ---
        $startMonth = (int) ($company->fiscal_year_start_month ?? 7);
        $fyStart = $startMonth <= (int) now()->month
            ? now()->year
            : now()->year - 1;

        $startsOn = sprintf('%04d-%02d-01', $fyStart, $startMonth);
        $endsOn = (new \DateTime($startsOn))->modify('+1 year -1 day')->format('Y-m-d');

        /*
         * Matched on the code, not on the starting date: `starts_on` is a date
         * column, so a stored row reads back as `2026-07-01 00:00:00` and a
         * where-clause of `2026-07-01` never finds it — the seeder would try to
         * insert the year it had just written, and `(company_id, code)` would
         * refuse. A seeder that cannot be run twice is a seeder that cannot be
         * re-run after a restore, which is most of when anybody runs it.
         */
        FiscalYear::updateOrCreate(
            [
                'company_id' => $company->id,
                'code' => 'FY'.sprintf('%04d', $fyStart),
            ],
            [
                'starts_on' => $startsOn,
                'name' => 'FY '.sprintf('%04d', $fyStart).'-'.sprintf('%04d', $fyStart + 1),
                'ends_on' => $endsOn,
                'status' => 'open',
                'is_current' => true,
            ],
        );
    }
}
