<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12-14 — the asset register, its vehicles, and the trips they make.
 *
 * Three tables, and one decision holding them together: **a vehicle is an
 * asset**. Registration papers, a driver, a fitness certificate and a purchase
 * cost are four views of one thing, and splitting vehicles into their own table
 * would mean a register where the trucks are missing and a vehicle list with no
 * cost. `category` says what kind of asset it is (`vehicle`, `equipment`,
 * `furniture`, `computer`, `machinery`, `other`), and the vehicle-only columns
 * are simply empty for a laptop.
 *
 * The statutory dates — fitness, insurance, tax token — are deliberately **not**
 * columns here. They are `business_records` rows of kind `certificate` or
 * `insurance` that link back to the asset, which is the whole reason §12-09 was
 * built as a register: a vehicle's fitness certificate and the company's trade
 * licence are the same animal (issued by somebody, run out on a date), and they
 * must appear on the same renewals lens and the same compliance calendar rather
 * than in a vehicle table nobody's calendar knows about.
 *
 * Depreciation is recorded, not invented. An asset carries its method, its life
 * and its salvage value, and `accumulated_depreciation` moves **only** when a
 * journal entry has actually been posted — which is why `capitalised_at` exists:
 * an asset whose purchase cost is not in the books yet cannot be depreciated
 * without inventing a loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 40);                        // AST-000001, allocated per company
            $table->string('category', 24)->default('other'); // vehicle|equipment|furniture|computer|machinery|other
            $table->string('name', 191);
            $table->string('description', 500)->nullable();

            // Where it is and who answers for it. A register that says "somewhere
            // in the warehouse" is a register that cannot find anything.
            $table->string('location', 160)->nullable();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('acquired_on')->nullable();
            $table->decimal('acquisition_cost', 15, 2)->nullable();
            $table->string('supplier_name', 160)->nullable();
            $table->string('invoice_ref', 120)->nullable();
            $table->date('warranty_expires_on')->nullable();

            $table->string('condition', 16)->nullable();       // new|good|fair|poor
            $table->string('status', 16)->default('in_use');   // in_use|stored|under_repair|disposed

            // Vehicle detail — empty for everything that is not a vehicle.
            $table->string('registration_no', 40)->nullable();
            $table->string('engine_no', 60)->nullable();
            $table->string('chassis_no', 60)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('odometer_reading')->nullable();

            /* Depreciation. `capitalised_at` is the gate: no capitalisation, no
               depreciation — the cost has to be in the books for a monthly charge
               against it to mean anything. */
            $table->string('depreciation_method', 16)->default('none'); // none|straight_line
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->decimal('accumulated_depreciation', 15, 2)->default(0);
            $table->date('capitalised_at')->nullable();
            $table->date('depreciation_starts_on')->nullable();
            $table->date('last_depreciated_on')->nullable();

            // Where the cost was capitalised — informational, and shown next to
            // the schedule so the accountant can see which account it is in.
            $table->string('gl_account_code', 12)->nullable();

            // Disposal: a decision with a date, a reason and what came back.
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 15, 2)->nullable();
            $table->string('disposal_reason', 255)->nullable();
            $table->foreignId('disposed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'registration_no']);
            $table->index(['company_id', 'category', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('business_asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_asset_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32); // created|updated|moved|assigned|capitalised|depreciated|disposed|trip|record_linked
            $table->date('happened_on');
            $table->string('note', 255)->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_asset_id', 'happened_on']);
        });

        Schema::create('vehicle_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->date('trip_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('from_location', 160)->nullable();
            $table->string('to_location', 160)->nullable();
            $table->string('purpose', 255)->nullable();

            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('driver_name', 120)->nullable();

            /* Odometer readings are what make a distance trustworthy; when both
               are recorded the distance is arithmetic, not an estimate. */
            $table->unsignedBigInteger('odometer_start')->nullable();
            $table->unsignedBigInteger('odometer_end')->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();

            $table->decimal('fuel_litres', 10, 3)->nullable();
            $table->decimal('fuel_cost', 15, 2)->nullable();
            $table->decimal('other_cost', 15, 2)->nullable();
            $table->string('cost_note', 255)->nullable();

            // Set when the cost was recorded on the expense desk, so the trip and
            // the books point at the same document.
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'trip_date']);
            $table->index(['business_asset_id', 'trip_date']);
        });

        Schema::table('business_records', function (Blueprint $table) {
            // §12-09 + §12-14: a certificate can belong to one asset — a vehicle's
            // fitness, a machine's insurance. It keeps the record in the register
            // that watches dates, and lets the vehicle page show its own papers.
            $table->foreignId('business_asset_id')->nullable()->after('branch_id')
                ->constrained('business_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_asset_id');
        });

        Schema::dropIfExists('vehicle_trips');
        Schema::dropIfExists('business_asset_events');
        Schema::dropIfExists('business_assets');
    }
};
