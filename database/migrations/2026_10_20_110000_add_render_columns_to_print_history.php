<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16-23/§16-24/§16-25 — what a print row has to be able to answer.
 *
 * `print_history` already recorded *that* something was printed, by whom, from
 * which address and how many copies. The reusable renderer adds four facts the
 * paper itself carried, because a printed document is only accountable if the
 * row can be compared with the sheet in somebody's hand:
 *
 *  · `document_id` — the filed copy whose bytes were checksummed, so "what
 *    exactly was printed" is a file and not a memory;
 *  · `printed_title` — the title that actually appeared (INVOICE, MUSHAK 9.1,
 *    DELIVERY CHALLAN), because the title is a rule and not a decoration;
 *  · `page_format` — a4 or thermal: the same document prints differently on the
 *    counter printer and on the office one;
 *  · `locale` and `watermark` — a Bengali copy and a "COPY" copy are different
 *    papers from the same document, and the history says which one you hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_history', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('document_type_id')
                ->constrained('documents')->nullOnDelete();
            $table->string('printed_title', 96)->nullable()->after('format');
            $table->string('page_format', 8)->default('a4')->after('printed_title');
            $table->char('locale', 5)->default('en')->after('page_format');
            $table->string('watermark', 32)->nullable()->after('locale');
            $table->char('checksum', 64)->nullable()->after('watermark');
        });
    }

    public function down(): void
    {
        Schema::table('print_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
            $table->dropColumn(['printed_title', 'page_format', 'locale', 'watermark', 'checksum']);
        });
    }
};
