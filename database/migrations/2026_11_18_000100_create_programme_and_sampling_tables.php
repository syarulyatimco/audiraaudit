<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — audit programme (procedures responsive to assessed risks) and audit sampling
 * (population, completeness, documented sample size, approval, reproducible selection,
 * results and evaluation). Selections and populations are immutable once fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->foreignId('engagement_fsli_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('steps');
            $table->string('nature', 30);            // test_of_details | substantive_analytical | test_of_controls | other
            $table->string('timing', 20)->default('final'); // interim | final | roll_forward
            $table->jsonb('assertions')->default('[]');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('budget_hours', 7, 2)->nullable();
            $table->string('status', 20)->default('not_started'); // not_started | in_progress | completed
            $table->text('conclusion')->nullable();
            $table->string('source', 10)->default('manual');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawn_reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
        });
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT proc_nature CHECK (nature IN ('test_of_details','substantive_analytical','test_of_controls','other'))");
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT proc_timing CHECK (timing IN ('interim','final','roll_forward'))");
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT proc_status CHECK (status IN ('not_started','in_progress','completed'))");
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT proc_source CHECK (source IN ('manual','ai'))");
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT proc_done CHECK (status <> 'completed' OR conclusion IS NOT NULL)");
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT proc_json CHECK (jsonb_typeof(assertions) = 'array')");

        Schema::create('procedure_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->unique(['procedure_id', 'risk_id']);
        });

        Schema::create('sampling_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('engagement_fsli_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('title', 200);
            $table->text('objective');
            $table->string('test_type', 20);          // monetary | attributes
            $table->string('approach', 20);           // statistical | non_statistical
            $table->string('method', 20);             // mus | random | systematic | stratified | haphazard | judgemental
            $table->string('status', 20)->default('draft'); // draft | pending_approval | approved | rejected | selected | evaluated
            // Population
            $table->string('population_source', 200)->nullable();
            $table->unsignedInteger('population_count')->default(0);
            $table->bigInteger('population_value_sen')->default(0);
            $table->char('population_sha256', 64)->nullable();
            $table->bigInteger('control_total_sen')->nullable();       // what the population must reconcile to
            $table->string('control_total_source', 200)->nullable();
            $table->text('reconciliation_note')->nullable();          // required when it does not reconcile
            // Parameters (monetary)
            $table->bigInteger('tolerable_misstatement_sen')->nullable();
            $table->bigInteger('expected_misstatement_sen')->nullable();
            $table->bigInteger('key_item_threshold_sen')->nullable();
            $table->decimal('confidence_level', 5, 2)->nullable();    // e.g. 95.00
            $table->decimal('expansion_factor', 6, 3)->nullable();    // firm methodology input (MUS, expected misstatement > 0)
            $table->decimal('assurance_factor', 6, 3)->nullable();    // firm methodology input (non-statistical)
            $table->jsonb('strata')->nullable();                       // stratum upper bounds (sen)
            // Parameters (attributes)
            $table->decimal('tolerable_deviation_rate', 6, 3)->nullable();  // %
            $table->decimal('expected_deviation_rate', 6, 3)->nullable();   // %
            // Size and selection
            $table->unsignedInteger('sample_size')->nullable();       // items to select excluding key items
            $table->jsonb('size_calculation')->nullable();             // formula, inputs, result
            $table->text('method_rationale')->nullable();
            $table->string('selection_algorithm', 60)->nullable();
            $table->bigInteger('selection_seed')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->foreignId('selected_by')->nullable()->constrained('users');
            $table->jsonb('evaluation')->nullable();
            $table->text('conclusion')->nullable();
            $table->string('source', 10)->default('manual');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
        });
        DB::statement("ALTER TABLE sampling_plans ADD CONSTRAINT sp_type CHECK (test_type IN ('monetary','attributes'))");
        DB::statement("ALTER TABLE sampling_plans ADD CONSTRAINT sp_approach CHECK (approach IN ('statistical','non_statistical'))");
        DB::statement("ALTER TABLE sampling_plans ADD CONSTRAINT sp_method CHECK (method IN ('mus','random','systematic','stratified','haphazard','judgemental'))");
        // Judgemental and haphazard selection are never statistical; MUS is always statistical and monetary.
        DB::statement("ALTER TABLE sampling_plans ADD CONSTRAINT sp_method_approach CHECK ((method NOT IN ('judgemental','haphazard') OR approach = 'non_statistical') AND (method <> 'mus' OR (approach = 'statistical' AND test_type = 'monetary')))");
        DB::statement("ALTER TABLE sampling_plans ADD CONSTRAINT sp_status CHECK (status IN ('draft','pending_approval','approved','rejected','selected','evaluated'))");
        DB::statement('ALTER TABLE sampling_plans ADD CONSTRAINT sp_amounts CHECK ((tolerable_misstatement_sen IS NULL OR tolerable_misstatement_sen > 0) AND (expected_misstatement_sen IS NULL OR expected_misstatement_sen >= 0) AND (expected_misstatement_sen IS NULL OR tolerable_misstatement_sen IS NULL OR expected_misstatement_sen < tolerable_misstatement_sen))');
        DB::statement('ALTER TABLE sampling_plans ADD CONSTRAINT sp_conf CHECK (confidence_level IS NULL OR (confidence_level > 0 AND confidence_level < 100))');

        Schema::create('population_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('sampling_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('item_ref', 100);
            $table->string('description', 300)->nullable();
            $table->bigInteger('amount_sen');
            $table->date('item_date')->nullable();
            $table->unique(['sampling_plan_id', 'seq']);
        });

        Schema::create('sample_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('sampling_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('population_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('selection_order');
            $table->string('selection_reason', 20);   // key_item | random | systematic | mus | stratified | judgemental | haphazard
            $table->unsignedSmallInteger('stratum')->nullable();
            $table->bigInteger('selection_point_sen')->nullable(); // MUS: the monetary unit that selected the item
            $table->text('selection_note')->nullable();             // judgemental / haphazard: why this item
            $table->string('result', 20)->nullable();               // null (untested) | no_exception | exception
            $table->bigInteger('audited_amount_sen')->nullable();
            $table->text('result_note')->nullable();
            $table->foreignId('tested_by')->nullable()->constrained('users');
            $table->timestamp('tested_at')->nullable();
            $table->unique(['sampling_plan_id', 'population_item_id']);
        });
        DB::statement("ALTER TABLE sample_items ADD CONSTRAINT si_result CHECK (result IS NULL OR result IN ('no_exception','exception'))");

        // Populations are frozen once the plan leaves draft; selections can never be changed except to record results.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE st text;
            BEGIN
                SELECT status INTO st FROM sampling_plans WHERE id = COALESCE(NEW.sampling_plan_id, OLD.sampling_plan_id);
                IF st IS NOT NULL AND st NOT IN ('draft','rejected') THEN
                    RAISE EXCEPTION 'population of a % sampling plan is frozen', st;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER population_frozen BEFORE INSERT OR UPDATE OR DELETE ON population_items FOR EACH ROW EXECUTE FUNCTION population_frozen();

            CREATE OR REPLACE FUNCTION selection_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'sample selections cannot be deleted'; END IF;
                IF (to_jsonb(NEW) - 'result' - 'audited_amount_sen' - 'result_note' - 'tested_by' - 'tested_at')
                   <> (to_jsonb(OLD) - 'result' - 'audited_amount_sen' - 'result_note' - 'tested_by' - 'tested_at') THEN
                    RAISE EXCEPTION 'sample selections are immutable; only results may be recorded';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER selection_frozen BEFORE UPDATE OR DELETE ON sample_items FOR EACH ROW EXECUTE FUNCTION selection_frozen();

            CREATE OR REPLACE FUNCTION sampling_plan_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status IN ('approved','selected','evaluated') AND (
                    NEW.method, NEW.approach, NEW.test_type, NEW.population_sha256, NEW.population_value_sen, NEW.sample_size,
                    NEW.tolerable_misstatement_sen, NEW.expected_misstatement_sen, NEW.key_item_threshold_sen, NEW.confidence_level,
                    NEW.tolerable_deviation_rate, NEW.expected_deviation_rate, NEW.strata
                ) IS DISTINCT FROM (
                    OLD.method, OLD.approach, OLD.test_type, OLD.population_sha256, OLD.population_value_sen, OLD.sample_size,
                    OLD.tolerable_misstatement_sen, OLD.expected_misstatement_sen, OLD.key_item_threshold_sen, OLD.confidence_level,
                    OLD.tolerable_deviation_rate, OLD.expected_deviation_rate, OLD.strata
                ) THEN
                    RAISE EXCEPTION 'an approved sampling plan cannot change its population or parameters';
                END IF;
                IF OLD.selection_seed IS NOT NULL AND NEW.selection_seed IS DISTINCT FROM OLD.selection_seed THEN
                    RAISE EXCEPTION 'the selection seed is immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sampling_plan_frozen BEFORE UPDATE ON sampling_plans FOR EACH ROW EXECUTE FUNCTION sampling_plan_frozen();
        SQL);

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['procedures', 'procedure_risks', 'sampling_plans', 'population_items', 'sample_items'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sampling_plan_frozen ON sampling_plans; DROP FUNCTION IF EXISTS sampling_plan_frozen();
            DROP TRIGGER IF EXISTS selection_frozen ON sample_items; DROP FUNCTION IF EXISTS selection_frozen();
            DROP TRIGGER IF EXISTS population_frozen ON population_items; DROP FUNCTION IF EXISTS population_frozen();');
        foreach (['sample_items', 'population_items', 'sampling_plans', 'procedure_risks', 'procedures'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
