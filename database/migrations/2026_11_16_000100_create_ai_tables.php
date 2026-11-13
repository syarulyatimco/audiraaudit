<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: AI platform. Every agent run is bound to one firm and one engagement, logged step by
 * step, and metered. Agents never write domain data: their outputs are drafts a person accepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false);
            $table->timestampTz('ai_enabled_at')->nullable();
            $table->foreignId('ai_enabled_by')->nullable()->constrained('users');
            $table->jsonb('ai_disabled_agents')->nullable();
        });

        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('agent', 40);
            $table->string('task', 40);
            $table->string('status', 20)->default('queued');
            $table->foreignId('requested_by')->constrained('users');
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('error')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('credits')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['firm_id', 'engagement_id', 'created_at']);
        });
        DB::statement("ALTER TABLE ai_runs ADD CONSTRAINT ai_runs_status_check CHECK (status IN ('queued','running','completed','needs_review','failed','cancelled'))");

        Schema::create('ai_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->string('type', 20);
            $table->string('name', 80)->nullable();
            $table->jsonb('payload')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['ai_run_id', 'seq']);
        });
        DB::statement("ALTER TABLE ai_run_steps ADD CONSTRAINT ai_run_steps_type_check CHECK (type IN ('llm_request','llm_response','tool_call','tool_result','tool_denied','guardrail','retry','error'))");

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedBigInteger('cost_micros')->default(0); // USD micro-units, from configured prices
            $table->unsignedInteger('credits');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['firm_id', 'created_at']);
        });

        // Execution logs and usage are evidence of what the AI did: append-only.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ai_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                RAISE EXCEPTION '% is append-only (% blocked)', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER ai_run_steps_append_only BEFORE UPDATE OR DELETE ON ai_run_steps FOR EACH ROW EXECUTE FUNCTION ai_append_only();
            CREATE TRIGGER ai_usage_append_only BEFORE UPDATE OR DELETE ON ai_usage FOR EACH ROW EXECUTE FUNCTION ai_append_only();
        SQL);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
            foreach (['ai_runs', 'ai_run_steps', 'ai_usage'] as $t) {
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ai_run_steps_append_only ON ai_run_steps; DROP TRIGGER IF EXISTS ai_usage_append_only ON ai_usage; DROP FUNCTION IF EXISTS ai_append_only();');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_run_steps');
        Schema::dropIfExists('ai_runs');
        Schema::table('firms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_enabled_by');
            $table->dropColumn(['ai_enabled', 'ai_enabled_at', 'ai_disabled_agents']);
        });
    }
};
