<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: FSLI templates, engagement FSLIs, trial balance imports, account mapping and the
 * audit adjustment journal. All amounts are integer sen; balances are signed (debit positive).
 */
return new class extends Migration
{
    private const CLASSIFICATIONS = "('non_current_asset','current_asset','equity','non_current_liability','current_liability','revenue','cost_of_sales','other_income','expense','finance_cost','tax_expense')";

    public function up(): void
    {
        // Platform-level templates (no firm_id).
        Schema::create('fsli_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });
        Schema::create('fsli_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('fsli_templates')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('classification', 30);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unique(['template_id', 'code']);
        });
        DB::statement('ALTER TABLE fsli_template_items ADD CONSTRAINT fsli_template_items_class_check CHECK (classification IN '.self::CLASSIFICATIONS.')');

        Schema::create('engagement_fslis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('classification', 30);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_custom')->default(false);
            $table->foreignId('working_paper_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['engagement_id', 'code']);
        });
        DB::statement('ALTER TABLE engagement_fslis ADD CONSTRAINT engagement_fslis_class_check CHECK (classification IN '.self::CLASSIFICATIONS.')');

        Schema::create('tb_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10);
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('header_row')->nullable();
            $table->jsonb('column_map')->nullable();
            $table->jsonb('raw_rows');
            $table->unsignedInteger('row_count')->default(0);
            $table->bigInteger('total_debit_sen')->default(0);
            $table->bigInteger('total_credit_sen')->default(0);
            $table->jsonb('warnings')->nullable();
            $table->foreignId('imported_by')->constrained('users');
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
            $table->index(['engagement_id', 'period', 'status']);
        });
        DB::statement("ALTER TABLE tb_imports ADD CONSTRAINT tb_imports_period_check CHECK (period IN ('current','prior'))");
        DB::statement("ALTER TABLE tb_imports ADD CONSTRAINT tb_imports_status_check CHECK (status IN ('pending','active','superseded','discarded'))");
        DB::statement("CREATE UNIQUE INDEX tb_imports_one_active ON tb_imports (engagement_id, period) WHERE status = 'active'");
        DB::statement("ALTER TABLE tb_imports ADD CONSTRAINT tb_imports_balanced CHECK (status <> 'active' OR total_debit_sen = total_credit_sen)");

        Schema::create('tb_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tb_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');
            $table->string('account_code', 50);
            $table->string('account_name', 255);
            $table->bigInteger('debit_sen')->default(0);
            $table->bigInteger('credit_sen')->default(0);
            $table->bigInteger('balance_sen');
            $table->unique(['tb_import_id', 'account_code']);
        });
        DB::statement('ALTER TABLE tb_lines ADD CONSTRAINT tb_lines_amounts_check CHECK (debit_sen >= 0 AND credit_sen >= 0 AND balance_sen = debit_sen - credit_sen)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tb_lines_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'imported trial balance lines are immutable (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER tb_lines_immutable BEFORE UPDATE OR DELETE ON tb_lines FOR EACH ROW EXECUTE FUNCTION tb_lines_guard();
        SQL);

        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('account_code', 50);
            $table->foreignId('engagement_fsli_id')->constrained('engagement_fslis')->restrictOnDelete();
            $table->string('source', 20)->default('manual');
            $table->foreignId('mapped_by')->constrained('users');
            $table->timestampsTz();
            $table->unique(['engagement_id', 'account_code']);
        });
        DB::statement("ALTER TABLE account_mappings ADD CONSTRAINT account_mappings_source_check CHECK (source IN ('manual','carried_forward','rule','ai'))");

        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('type', 20);
            $table->text('description');
            $table->string('status', 20)->default('draft');
            $table->foreignId('prepared_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestampsTz();
            $table->unique(['engagement_id', 'number']);
        });
        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_type_check CHECK (type IN ('audit','reclassification','client'))");
        DB::statement("ALTER TABLE adjustments ADD CONSTRAINT adjustments_status_check CHECK (status IN ('draft','posted','voided'))");
        DB::statement('ALTER TABLE adjustments ADD CONSTRAINT adjustments_segregation_check CHECK (posted_by IS NULL OR posted_by <> prepared_by)');

        Schema::create('adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adjustment_id')->constrained()->cascadeOnDelete();
            $table->string('account_code', 50);
            $table->string('description', 255)->nullable();
            $table->bigInteger('debit_sen')->default(0);
            $table->bigInteger('credit_sen')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
        DB::statement('ALTER TABLE adjustment_lines ADD CONSTRAINT adjustment_lines_amount_check CHECK (debit_sen >= 0 AND credit_sen >= 0 AND (debit_sen > 0) <> (credit_sen > 0))');

        // Posted adjustments are frozen: lines cannot change once the journal is posted or voided.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION adjustment_lines_guard() RETURNS trigger AS $$
            DECLARE v_status text;
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                SELECT status INTO v_status FROM adjustments
                 WHERE id = (CASE WHEN TG_OP = 'DELETE' THEN OLD.adjustment_id ELSE NEW.adjustment_id END);
                IF v_status IS NOT NULL AND v_status <> 'draft' THEN
                    RAISE EXCEPTION 'lines of a % adjustment are immutable', v_status;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER adjustment_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON adjustment_lines
                FOR EACH ROW EXECUTE FUNCTION adjustment_lines_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS adjustment_lines_immutable ON adjustment_lines; DROP FUNCTION IF EXISTS adjustment_lines_guard();');
        DB::unprepared('DROP TRIGGER IF EXISTS tb_lines_immutable ON tb_lines; DROP FUNCTION IF EXISTS tb_lines_guard();');
        foreach (['adjustment_lines', 'adjustments', 'account_mappings', 'tb_lines', 'tb_imports', 'engagement_fslis', 'fsli_template_items', 'fsli_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
