<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('category', 30);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['firm_id', 'engagement_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('original_name', 255);
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('pending');
            $table->string('scan_engine', 40)->nullable();
            $table->string('scan_detail', 255)->nullable();
            $table->timestampTz('scanned_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_id', 'version_no']);
        });
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_scan_check CHECK (scan_status IN ('pending','clean','infected','failed'))");
        Schema::table('documents', fn (Blueprint $t) => $t->foreign('current_version_id')->references('id')->on('document_versions'));

        // Evidence integrity: file identity (path, hash, size) can never change after upload.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_versions_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() > 1 THEN RETURN OLD; END IF;
                    RAISE EXCEPTION 'document versions cannot be deleted';
                END IF;
                IF NEW.sha256 <> OLD.sha256 OR NEW.path <> OLD.path OR NEW.disk <> OLD.disk
                   OR NEW.size_bytes <> OLD.size_bytes OR NEW.document_id <> OLD.document_id OR NEW.version_no <> OLD.version_no THEN
                    RAISE EXCEPTION 'document version file identity is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER document_versions_immutable BEFORE UPDATE OR DELETE ON document_versions
                FOR EACH ROW EXECUTE FUNCTION document_versions_guard();
        SQL);

        Schema::create('working_papers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('title', 200);
            $table->string('section', 30);
            $table->string('system_key', 40)->nullable();
            $table->text('objective')->nullable();
            $table->text('work_performed')->nullable();
            $table->text('conclusion')->nullable();
            $table->string('status', 20)->default('draft');
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['engagement_id', 'reference']);
            $table->index(['firm_id', 'engagement_id', 'status']);
        });
        DB::statement("ALTER TABLE working_papers ADD CONSTRAINT working_papers_status_check CHECK (status IN ('draft','prepared','reviewed'))");
        DB::statement('CREATE UNIQUE INDEX working_papers_system_key ON working_papers (engagement_id, system_key) WHERE system_key IS NOT NULL');

        Schema::create('wp_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('working_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('role', 20);
            $table->char('content_hash', 64);
            $table->timestampTz('signed_at');
            $table->timestampTz('invalidated_at')->nullable();
            $table->string('invalidation_reason', 255)->nullable();
            $table->index(['working_paper_id', 'role']);
        });
        DB::statement("ALTER TABLE wp_signoffs ADD CONSTRAINT wp_signoffs_role_check CHECK (role IN ('preparer','reviewer'))");

        Schema::create('review_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('working_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by')->constrained('users');
            $table->text('body');
            $table->string('status', 20)->default('open');
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users');
            $table->timestampTz('responded_at')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users');
            $table->timestampTz('cleared_at')->nullable();
            $table->timestampsTz();
            $table->index(['working_paper_id', 'status']);
        });
        DB::statement("ALTER TABLE review_notes ADD CONSTRAINT review_notes_status_check CHECK (status IN ('open','responded','cleared'))");

        Schema::create('working_paper_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('working_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('linked_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['working_paper_id', 'document_id']);
        });

        Schema::create('working_paper_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('working_paper_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_paragraph_id')->constrained('library_paragraphs')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('added_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['working_paper_id', 'library_paragraph_id']);
        });

        Schema::create('entity_understanding_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->string('section_key', 60);
            $table->text('content')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['engagement_id', 'section_key']);
        });
    }

    public function down(): void
    {
        foreach (['entity_understanding_sections', 'working_paper_citations', 'working_paper_documents', 'review_notes', 'wp_signoffs', 'working_papers'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('documents', fn (Blueprint $t) => $t->dropForeign(['current_version_id']));
        DB::unprepared('DROP TRIGGER IF EXISTS document_versions_immutable ON document_versions; DROP FUNCTION IF EXISTS document_versions_guard();');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
