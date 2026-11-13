<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — completion checklist, final conclusions and completion approvals, engagement quality
 * review, financial statements snapshot, audit report with opinion approval and re-authenticated
 * sign-off, firm report templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completion_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('key', 60);
            $table->string('section', 60);
            $table->string('title', 300);
            $table->text('response')->nullable();
            $table->string('status', 20)->default('open'); // open | done | not_applicable
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->string('source', 10)->default('manual');
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['engagement_id', 'key']);
        });
        DB::statement("ALTER TABLE completion_items ADD CONSTRAINT ci_status CHECK (status IN ('open','done','not_applicable'))");
        DB::statement("ALTER TABLE completion_items ADD CONSTRAINT ci_done CHECK (status = 'open' OR (response IS NOT NULL AND completed_by IS NOT NULL AND completed_at IS NOT NULL))");

        Schema::create('engagement_completions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->unique()->constrained()->restrictOnDelete();
            $table->text('misstatements_conclusion')->nullable();
            $table->text('going_concern_conclusion')->nullable();
            $table->text('overall_conclusion')->nullable();
            $table->string('conclusions_status', 20)->nullable(); // pending | approved | rejected
            $table->string('conclusions_sha256', 64)->nullable();
            $table->string('completion_status', 20)->nullable();
            $table->text('eqr_conclusion')->nullable();
            $table->foreignId('eqr_completed_by')->nullable()->constrained('users');
            $table->timestamp('eqr_completed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        foreach (['conclusions_status', 'completion_status'] as $c) {
            DB::statement("ALTER TABLE engagement_completions ADD CONSTRAINT ec_{$c} CHECK ({$c} IS NULL OR {$c} IN ('pending','approved','rejected'))");
        }
        DB::statement("ALTER TABLE engagement_completions ADD CONSTRAINT ec_eqr CHECK ((eqr_completed_at IS NULL) = (eqr_completed_by IS NULL) AND (eqr_completed_at IS NULL OR eqr_conclusion IS NOT NULL))");

        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->string('opinion', 20);
            $table->text('body');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['firm_id', 'opinion']);
        });

        Schema::create('audit_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->unique()->constrained()->restrictOnDelete();
            $table->string('opinion', 20)->nullable();             // unmodified | qualified | adverse | disclaimer
            $table->text('basis_for_modification')->nullable();
            $table->boolean('going_concern_uncertainty')->default(false);
            $table->text('going_concern_paragraph')->nullable();
            $table->text('emphasis_of_matter')->nullable();
            $table->text('other_matter')->nullable();
            $table->date('report_date')->nullable();
            $table->text('body')->nullable();
            $table->string('opinion_status', 20)->nullable();
            $table->string('signoff_status', 20)->nullable();
            $table->string('status', 20)->default('draft');       // draft | signed
            $table->string('body_sha256', 64)->nullable();
            $table->jsonb('fs_snapshot')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users');
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE report_templates ADD CONSTRAINT rt_opinion CHECK (opinion IN ('unmodified','qualified','adverse','disclaimer'))");
        DB::statement("ALTER TABLE audit_reports ADD CONSTRAINT ar_opinion CHECK (opinion IS NULL OR opinion IN ('unmodified','qualified','adverse','disclaimer'))");
        DB::statement("ALTER TABLE audit_reports ADD CONSTRAINT ar_modified CHECK (opinion IS NULL OR opinion = 'unmodified' OR basis_for_modification IS NOT NULL)");
        DB::statement("ALTER TABLE audit_reports ADD CONSTRAINT ar_gc CHECK (NOT going_concern_uncertainty OR going_concern_paragraph IS NOT NULL)");
        foreach (['opinion_status', 'signoff_status'] as $c) {
            DB::statement("ALTER TABLE audit_reports ADD CONSTRAINT ar_{$c} CHECK ({$c} IS NULL OR {$c} IN ('pending','approved','rejected'))");
        }
        DB::statement("ALTER TABLE audit_reports ADD CONSTRAINT ar_signed CHECK (status IN ('draft','signed') AND (status = 'draft' OR (signed_by IS NOT NULL AND signed_at IS NOT NULL AND body_sha256 IS NOT NULL AND fs_snapshot IS NOT NULL AND opinion_status = 'approved')))");
        // A signed report can never change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION signed_report_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.status = 'signed' THEN RAISE EXCEPTION 'signed audit reports are immutable'; END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            DROP TRIGGER IF EXISTS audit_reports_frozen ON audit_reports;
            CREATE TRIGGER audit_reports_frozen BEFORE UPDATE OR DELETE ON audit_reports FOR EACH ROW EXECUTE FUNCTION signed_report_frozen();
        SQL);

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['completion_items', 'engagement_completions', 'report_templates', 'audit_reports'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_reports');
        DB::unprepared('DROP FUNCTION IF EXISTS signed_report_frozen() CASCADE');
        Schema::dropIfExists('report_templates');
        Schema::dropIfExists('engagement_completions');
        Schema::dropIfExists('completion_items');
    }
};
