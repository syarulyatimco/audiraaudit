<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — testing and evidence (procedure working papers, evidence per sample item), findings
 * (misstatements and control deficiencies), the summary of audit differences, and approval of
 * material adjustments before posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->foreignId('working_paper_id')->nullable()->after('conclusion')->constrained()->nullOnDelete();
        });
        Schema::table('sample_items', function (Blueprint $table) {
            $table->foreignId('evidence_document_id')->nullable()->after('result_note')->constrained('documents')->restrictOnDelete();
        });
        // Evidence may be linked while results are recorded.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION selection_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'sample selections cannot be deleted'; END IF;
                IF (to_jsonb(NEW) - 'result' - 'audited_amount_sen' - 'result_note' - 'tested_by' - 'tested_at' - 'evidence_document_id')
                   <> (to_jsonb(OLD) - 'result' - 'audited_amount_sen' - 'result_note' - 'tested_by' - 'tested_at' - 'evidence_document_id') THEN
                    RAISE EXCEPTION 'sample selections are immutable; only results may be recorded';
                END IF;
                RETURN NEW;
            END $$;
        SQL);

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('type', 30);                  // misstatement | control_deficiency | other
            $table->string('title', 200);
            $table->text('description');
            $table->text('cause')->nullable();
            $table->text('recommendation')->nullable();
            $table->foreignId('engagement_fsli_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sampling_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sample_item_id')->nullable()->constrained()->nullOnDelete();
            // Misstatements (ISA 450)
            $table->string('misstatement_class', 20)->nullable(); // factual | judgemental | projected
            $table->bigInteger('amount_sen')->nullable();         // recorded minus audited: positive = overstatement of the FSLI, negative = understatement
            $table->string('correction_status', 20)->nullable();  // proposed | corrected | uncorrected
            $table->text('uncorrected_reason')->nullable();
            $table->foreignId('adjustment_id')->nullable()->constrained()->nullOnDelete();
            // Control deficiencies (ISA 265)
            $table->string('deficiency_severity', 30)->nullable(); // deficiency | significant_deficiency
            $table->boolean('report_to_management')->default(false);
            $table->text('management_response')->nullable();
            // Significance and lifecycle
            $table->boolean('is_significant')->default(false);
            $table->string('significance_status', 20)->nullable(); // null | pending | approved | rejected
            $table->string('status', 20)->default('open');          // open | resolved | withdrawn
            $table->text('resolution')->nullable();
            $table->string('source', 10)->default('manual');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
            $table->index(['firm_id', 'engagement_id', 'type']);
        });
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_type CHECK (type IN ('misstatement','control_deficiency','other'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_class CHECK (misstatement_class IS NULL OR misstatement_class IN ('factual','judgemental','projected'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_misstatement CHECK (type <> 'misstatement' OR (misstatement_class IS NOT NULL AND amount_sen IS NOT NULL AND correction_status IS NOT NULL))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_correction CHECK (correction_status IS NULL OR correction_status IN ('proposed','corrected','uncorrected'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_uncorrected CHECK (correction_status <> 'uncorrected' OR uncorrected_reason IS NOT NULL)");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_severity CHECK (deficiency_severity IS NULL OR deficiency_severity IN ('deficiency','significant_deficiency'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_sig CHECK ((is_significant AND (significance_status IS NULL OR significance_status IN ('pending','approved','rejected'))) OR (NOT is_significant AND significance_status IS NULL))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_status CHECK (status IN ('open','resolved','withdrawn'))");
        DB::statement("ALTER TABLE findings ADD CONSTRAINT find_source CHECK (source IN ('manual','ai','sample'))");

        Schema::table('adjustments', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable()->after('status'); // null | pending | approved | rejected (material adjustments)
        });

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        DB::statement('ALTER TABLE findings ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE findings FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON findings USING ({$cond}) WITH CHECK ({$cond})");
    }

    public function down(): void
    {
        Schema::table('adjustments', fn (Blueprint $t) => $t->dropColumn('approval_status'));
        Schema::dropIfExists('findings');
        Schema::table('sample_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('evidence_document_id'));
        Schema::table('procedures', fn (Blueprint $t) => $t->dropConstrainedForeignId('working_paper_id'));
    }
};
