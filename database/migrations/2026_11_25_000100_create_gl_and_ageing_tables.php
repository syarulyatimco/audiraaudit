<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 — general ledger, journal entry testing and ageing reports.
 *
 *  gl_imports / gl_lines             Imported GL (immutable lines), reconciled per account to the TB.
 *  jet_tests / jet_test_items        Saved journal entry tests: criteria, selected entries, results.
 *  ageing_reports / ageing_lines     Aged receivables / payables listings with bucket definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending | active | superseded | discarded
            $table->unsignedInteger('header_row')->default(1);
            $table->jsonb('column_map')->default('{}');
            $table->jsonb('preview')->default('[]');
            $table->unsignedInteger('line_count')->default(0);
            $table->bigInteger('debit_sen')->default(0);
            $table->bigInteger('credit_sen')->default(0);
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->jsonb('reconciliation')->nullable();
            $table->foreignId('imported_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE gl_imports ADD CONSTRAINT gl_status CHECK (status IN ('pending','active','superseded','discarded'))");
        DB::statement("CREATE UNIQUE INDEX gl_imports_one_active ON gl_imports (engagement_id) WHERE status = 'active'");

        Schema::create('gl_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('gl_import_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->string('account_code', 50);
            $table->string('account_name', 200)->nullable();
            $table->date('posted_on');
            $table->string('reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->bigInteger('debit_sen')->default(0);
            $table->bigInteger('credit_sen')->default(0);
            $table->bigInteger('amount_sen'); // debit − credit
            $table->string('journal_type', 50)->nullable();
            $table->string('posted_by', 100)->nullable();
            $table->boolean('is_opening')->default(false);
            $table->unique(['gl_import_id', 'seq']);
            $table->index(['gl_import_id', 'account_code']);
            $table->index(['gl_import_id', 'posted_on']);
        });
        DB::statement('ALTER TABLE gl_lines ADD CONSTRAINT gl_line_amount CHECK (amount_sen = debit_sen - credit_sen AND debit_sen >= 0 AND credit_sen >= 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION gl_lines_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'imported general ledger lines are immutable'; END $$;
            DROP TRIGGER IF EXISTS gl_lines_immutable ON gl_lines;
            CREATE TRIGGER gl_lines_immutable BEFORE UPDATE OR DELETE ON gl_lines FOR EACH ROW EXECUTE FUNCTION gl_lines_immutable();
        SQL);

        Schema::create('jet_tests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->foreignId('gl_import_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('title', 200);
            $table->jsonb('criteria');
            $table->string('status', 20)->default('open'); // open | concluded
            $table->text('conclusion')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('concluded_by')->nullable()->constrained('users');
            $table->timestamp('concluded_at')->nullable();
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
        });
        DB::statement("ALTER TABLE jet_tests ADD CONSTRAINT jet_status CHECK ((status = 'open' AND concluded_by IS NULL) OR (status = 'concluded' AND conclusion IS NOT NULL AND concluded_by IS NOT NULL AND concluded_at IS NOT NULL))");

        Schema::create('jet_test_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('jet_test_id')->constrained()->restrictOnDelete();
            $table->foreignId('gl_line_id')->constrained()->restrictOnDelete();
            $table->jsonb('reasons');
            $table->string('result', 20)->nullable(); // no_exception | exception
            $table->text('note')->nullable();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('tested_by')->nullable()->constrained('users');
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();
            $table->unique(['jet_test_id', 'gl_line_id']);
        });
        DB::statement("ALTER TABLE jet_test_items ADD CONSTRAINT jet_item_result CHECK ((result IS NULL AND tested_by IS NULL) OR (result IN ('no_exception','exception') AND tested_by IS NOT NULL AND tested_at IS NOT NULL))");
        DB::statement("ALTER TABLE jet_test_items ADD CONSTRAINT jet_item_exception CHECK (result IS DISTINCT FROM 'exception' OR note IS NOT NULL)");

        Schema::create('ageing_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);    // receivables | payables
            $table->string('period', 10);  // current | prior
            $table->foreignId('engagement_fsli_id')->nullable()->constrained()->nullOnDelete();
            $table->date('as_at');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('header_row')->default(1);
            $table->jsonb('column_map')->default('{}');
            $table->jsonb('buckets')->default('[]'); // [{label, min_days}]
            $table->jsonb('preview')->default('[]');
            $table->unsignedInteger('line_count')->default(0);
            $table->bigInteger('total_sen')->default(0);
            $table->foreignId('imported_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE ageing_reports ADD CONSTRAINT ageing_type CHECK (type IN ('receivables','payables') AND period IN ('current','prior') AND status IN ('pending','active','superseded','discarded'))");
        DB::statement("CREATE UNIQUE INDEX ageing_one_active ON ageing_reports (engagement_id, type, period) WHERE status = 'active'");

        Schema::create('ageing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('ageing_report_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->string('party_code', 60);
            $table->string('party_name', 200)->nullable();
            $table->bigInteger('total_sen');
            $table->jsonb('bucket_sen'); // amounts in the order of the report's buckets
            $table->unique(['ageing_report_id', 'seq']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ageing_lines_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'imported ageing lines are immutable'; END $$;
            DROP TRIGGER IF EXISTS ageing_lines_immutable ON ageing_lines;
            CREATE TRIGGER ageing_lines_immutable BEFORE UPDATE OR DELETE ON ageing_lines FOR EACH ROW EXECUTE FUNCTION ageing_lines_immutable();
        SQL);

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['gl_imports', 'gl_lines', 'jet_tests', 'jet_test_items', 'ageing_reports', 'ageing_lines'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        foreach (['ageing_lines', 'ageing_reports', 'jet_test_items', 'jet_tests', 'gl_lines', 'gl_imports'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS gl_lines_immutable() CASCADE; DROP FUNCTION IF EXISTS ageing_lines_immutable() CASCADE;');
    }
};
