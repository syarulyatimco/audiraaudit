<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — management-prepared financial statements (client portal) and links from programme
 * rows to risk-response procedures.
 *
 *  client_financial_statements  The client's working draft (statements + notes) and the auditor-only
 *                               tie-out map, one per engagement.
 *  client_fs_versions           Frozen submissions; the auditor reviews and accepts one
 *                               director-approved version for the audit report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_financial_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->unique()->constrained()->restrictOnDelete();
            $table->string('template', 30);
            $table->jsonb('statements');
            $table->jsonb('notes')->default('[]');
            $table->string('status', 20)->default('draft'); // draft | submitted | changes_requested | accepted
            $table->jsonb('tieout')->default('{}');         // auditor only: line id => [fsli codes]
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE client_financial_statements ADD CONSTRAINT cfs_status CHECK (status IN ('draft','submitted','changes_requested','accepted'))");

        Schema::create('client_fs_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('statement_id')->constrained('client_financial_statements')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('content');
            $table->char('content_sha256', 64);
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('submitted_at');
            $table->boolean('directors_approved')->default(false);
            $table->string('approved_by_names', 300)->nullable();
            $table->date('approved_on')->nullable();
            $table->string('review_status', 20)->default('received'); // received | changes_requested | accepted | superseded
            $table->text('review_comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['statement_id', 'version']);
        });
        DB::statement("ALTER TABLE client_fs_versions ADD CONSTRAINT cfv_review CHECK (review_status IN ('received','changes_requested','accepted','superseded'))");
        DB::statement('ALTER TABLE client_fs_versions ADD CONSTRAINT cfv_directors CHECK (NOT directors_approved OR (approved_by_names IS NOT NULL AND approved_on IS NOT NULL))');
        DB::statement("ALTER TABLE client_fs_versions ADD CONSTRAINT cfv_accepted CHECK (review_status <> 'accepted' OR (directors_approved AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");
        DB::statement("ALTER TABLE client_fs_versions ADD CONSTRAINT cfv_changes CHECK (review_status <> 'changes_requested' OR review_comment IS NOT NULL)");
        // A submitted version is a record of what management gave the auditor: its content, author
        // and directors' approval never change, and versions are never deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION client_fs_version_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'client FS versions cannot be deleted'; END IF;
                IF NEW.content IS DISTINCT FROM OLD.content OR NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256
                   OR NEW.version IS DISTINCT FROM OLD.version OR NEW.submitted_by IS DISTINCT FROM OLD.submitted_by
                   OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.directors_approved IS DISTINCT FROM OLD.directors_approved
                   OR NEW.approved_by_names IS DISTINCT FROM OLD.approved_by_names OR NEW.approved_on IS DISTINCT FROM OLD.approved_on THEN
                    RAISE EXCEPTION 'submitted client FS versions are immutable';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS client_fs_versions_frozen ON client_fs_versions;
            CREATE TRIGGER client_fs_versions_frozen BEFORE UPDATE OR DELETE ON client_fs_versions FOR EACH ROW EXECUTE FUNCTION client_fs_version_frozen();
        SQL);

        Schema::table('programme_rows', function (Blueprint $table) {
            $table->foreignId('procedure_id')->nullable()->constrained()->restrictOnDelete();
        });

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['client_financial_statements', 'client_fs_versions'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        Schema::table('programme_rows', fn (Blueprint $table) => $table->dropConstrainedForeignId('procedure_id'));
        Schema::dropIfExists('client_fs_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS client_fs_version_frozen() CASCADE');
        Schema::dropIfExists('client_financial_statements');
    }
};
