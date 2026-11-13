<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — planning: materiality (versioned, approved by the engagement partner, frozen once
 * approved), risk assessment (risk register with significant-risk approval), audit strategy.
 * Approvals gain an engagement_id so engagement-scoped approvals are only visible to the team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->foreignId('engagement_id')->nullable()->after('firm_id')->constrained()->restrictOnDelete();
            $table->index(['firm_id', 'engagement_id', 'status']);
        });

        Schema::create('materiality_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('stage', 20);                  // planning | revised | final
            $table->string('status', 20)->default('draft'); // draft | pending_approval | approved | rejected | superseded
            $table->string('benchmark', 30);
            $table->string('benchmark_basis', 30);        // current_year | prior_year | budget | normalised | other
            $table->bigInteger('benchmark_amount_sen');
            $table->text('benchmark_reason')->nullable();  // required when the amount is not the AUDIRA figure
            $table->decimal('om_percentage', 6, 3);
            $table->bigInteger('om_sen');
            $table->decimal('pm_percentage', 6, 3);        // % of overall materiality
            $table->bigInteger('pm_sen');
            $table->decimal('ct_percentage', 6, 3);        // % of overall materiality
            $table->bigInteger('ct_sen');
            $table->text('rationale');
            $table->text('pm_rationale')->nullable();
            $table->text('ct_rationale')->nullable();
            $table->text('revision_reason')->nullable();
            $table->jsonb('fs_snapshot')->nullable();      // benchmark candidates computed by AUDIRA when saved
            $table->string('source', 10)->default('manual');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['engagement_id', 'version']);
            $table->index(['firm_id', 'engagement_id']);
        });
        DB::statement("ALTER TABLE materiality_assessments ADD CONSTRAINT mat_stage CHECK (stage IN ('planning','revised','final'))");
        DB::statement("ALTER TABLE materiality_assessments ADD CONSTRAINT mat_status CHECK (status IN ('draft','pending_approval','approved','rejected','superseded'))");
        DB::statement("ALTER TABLE materiality_assessments ADD CONSTRAINT mat_benchmark CHECK (benchmark IN ('profit_before_tax','revenue','total_assets','total_expenses','equity','gross_profit','other'))");
        DB::statement("ALTER TABLE materiality_assessments ADD CONSTRAINT mat_basis CHECK (benchmark_basis IN ('current_year','prior_year','budget','normalised','other'))");
        DB::statement('ALTER TABLE materiality_assessments ADD CONSTRAINT mat_amounts CHECK (benchmark_amount_sen > 0 AND om_sen > 0 AND pm_sen > 0 AND ct_sen > 0 AND pm_sen <= om_sen AND ct_sen < pm_sen)');
        DB::statement('ALTER TABLE materiality_assessments ADD CONSTRAINT mat_pcts CHECK (om_percentage > 0 AND om_percentage <= 100 AND pm_percentage > 0 AND pm_percentage <= 100 AND ct_percentage > 0 AND ct_percentage < 100)');
        DB::statement("ALTER TABLE materiality_assessments ADD CONSTRAINT mat_source CHECK (source IN ('manual','ai'))");
        // One approved version and at most one open (draft/pending/rejected) version per engagement.
        DB::statement("CREATE UNIQUE INDEX mat_one_approved ON materiality_assessments (engagement_id) WHERE status = 'approved'");
        DB::statement("CREATE UNIQUE INDEX mat_one_open ON materiality_assessments (engagement_id) WHERE status IN ('draft','pending_approval','rejected')");

        Schema::create('specific_materialities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('materiality_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_fsli_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('area', 200);                   // class of transactions, balance or disclosure
            $table->bigInteger('amount_sen');
            $table->text('rationale');
            $table->timestamps();
        });
        DB::statement('ALTER TABLE specific_materialities ADD CONSTRAINT spm_amount CHECK (amount_sen > 0)');

        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('title', 200);
            $table->text('description');
            $table->string('level', 20);                   // financial_statement | assertion
            $table->jsonb('assertions')->default('[]');    // validated against App\Enums\Assertion
            $table->jsonb('inherent_factors')->default('[]');
            $table->string('likelihood', 10);              // low | medium | high
            $table->string('magnitude', 10);
            $table->boolean('is_significant')->default(false);
            $table->string('significance_status', 20)->nullable(); // null (not submitted) | pending | approved | rejected
            $table->boolean('is_fraud_risk')->default(false);
            $table->string('presumed_key', 30)->nullable(); // revenue_recognition | management_override (presumed fraud risks)
            $table->text('rebuttal_reason')->nullable();     // presumption rebutted (revenue recognition only)
            $table->boolean('controls_reliance')->default(false);
            $table->text('controls_description')->nullable();
            $table->text('planned_response')->nullable();
            $table->string('source', 10)->default('manual');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawn_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
            $table->index(['firm_id', 'engagement_id']);
        });
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_level CHECK (level IN ('financial_statement','assertion'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_lm CHECK (likelihood IN ('low','medium','high') AND magnitude IN ('low','medium','high'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_sig CHECK ((is_significant AND (significance_status IS NULL OR significance_status IN ('pending','approved','rejected'))) OR (NOT is_significant AND significance_status IS NULL))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_json CHECK (jsonb_typeof(assertions) = 'array' AND jsonb_typeof(inherent_factors) = 'array')");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_source CHECK (source IN ('manual','ai','presumed'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_presumed CHECK (presumed_key IS NULL OR presumed_key IN ('revenue_recognition','management_override'))");
        DB::statement("ALTER TABLE risks ADD CONSTRAINT risk_rebuttal CHECK (rebuttal_reason IS NULL OR presumed_key = 'revenue_recognition')");
        DB::statement('CREATE UNIQUE INDEX risks_one_presumed ON risks (engagement_id, presumed_key) WHERE presumed_key IS NOT NULL');
        DB::statement('ALTER TABLE risks ADD CONSTRAINT risk_withdrawn CHECK ((withdrawn_at IS NULL) = (withdrawn_reason IS NULL))');

        Schema::create('risk_fslis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_fsli_id')->constrained()->restrictOnDelete();
            $table->unique(['risk_id', 'engagement_fsli_id']);
        });

        Schema::create('audit_strategies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->unique()->constrained()->restrictOnDelete();
            $table->jsonb('sections')->default('{}');      // text per section key in config('audira.audit_strategy')
            $table->string('controls_approach', 20)->default('substantive'); // substantive | combined
            $table->date('planning_meeting_on')->nullable();
            $table->date('fieldwork_start_on')->nullable();
            $table->date('fieldwork_end_on')->nullable();
            $table->date('report_target_on')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE audit_strategies ADD CONSTRAINT strat_approach CHECK (controls_approach IN ('substantive','combined'))");
        DB::statement("ALTER TABLE audit_strategies ADD CONSTRAINT strat_dates CHECK (fieldwork_end_on IS NULL OR fieldwork_start_on IS NULL OR fieldwork_end_on >= fieldwork_start_on)");

        // Approved / superseded materiality is frozen: only approved → superseded is allowed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION materiality_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'materiality version % cannot be deleted', OLD.version; END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'superseded' THEN RAISE EXCEPTION 'superseded materiality is immutable'; END IF;
                IF OLD.status = 'approved' THEN
                    IF NEW.status <> 'superseded'
                       OR (to_jsonb(NEW) - 'status' - 'superseded_at' - 'updated_at') <> (to_jsonb(OLD) - 'status' - 'superseded_at' - 'updated_at') THEN
                        RAISE EXCEPTION 'approved materiality is immutable; start a revision instead';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER materiality_frozen BEFORE UPDATE OR DELETE ON materiality_assessments FOR EACH ROW EXECUTE FUNCTION materiality_frozen();

            CREATE OR REPLACE FUNCTION specific_materiality_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE st text;
            BEGIN
                SELECT status INTO st FROM materiality_assessments WHERE id = COALESCE(NEW.materiality_assessment_id, OLD.materiality_assessment_id);
                IF st IS NOT NULL AND st NOT IN ('draft','rejected') THEN
                    RAISE EXCEPTION 'specific materiality of a % assessment cannot change', st;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER specific_materiality_frozen BEFORE INSERT OR UPDATE OR DELETE ON specific_materialities FOR EACH ROW EXECUTE FUNCTION specific_materiality_frozen();
        SQL);

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['materiality_assessments', 'specific_materialities', 'risks', 'risk_fslis', 'audit_strategies'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS specific_materiality_frozen ON specific_materialities; DROP FUNCTION IF EXISTS specific_materiality_frozen();
            DROP TRIGGER IF EXISTS materiality_frozen ON materiality_assessments; DROP FUNCTION IF EXISTS materiality_frozen();');
        Schema::dropIfExists('audit_strategies');
        Schema::dropIfExists('risk_fslis');
        Schema::dropIfExists('risks');
        Schema::dropIfExists('specific_materialities');
        Schema::dropIfExists('materiality_assessments');
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropIndex(['firm_id', 'engagement_id', 'status']);
            $table->dropConstrainedForeignId('engagement_id');
        });
    }
};
