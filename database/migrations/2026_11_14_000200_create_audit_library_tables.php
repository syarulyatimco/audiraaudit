<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide, version-controlled library of authorised source material (no firm_id: shared,
 * curated by Superadmin, read-only to firms). Published paragraphs are immutable (DB trigger),
 * so any citation in a working paper permanently points at the exact text relied upon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_frameworks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('category', 20);
            $table->string('issuer', 160)->nullable();
            $table->string('jurisdiction', 60)->default('Malaysia');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE library_frameworks ADD CONSTRAINT library_frameworks_category_check CHECK (category IN ('reporting','auditing','quality','legislation','regulatory','methodology'))");

        Schema::create('library_standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')->constrained('library_frameworks')->restrictOnDelete();
            $table->string('reference', 60);
            $table->string('title', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['framework_id', 'reference']);
        });

        Schema::create('library_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('standard_id')->constrained('library_standards')->restrictOnDelete();
            $table->string('version_label', 80);
            $table->date('effective_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('source_title', 255);
            $table->string('source_url', 500)->nullable();
            $table->string('source_disk', 30)->nullable();
            $table->string('source_path', 500)->nullable();
            $table->string('source_filename', 255)->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->text('change_summary')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['standard_id', 'version_label']);
        });
        DB::statement("ALTER TABLE library_versions ADD CONSTRAINT library_versions_status_check CHECK (status IN ('draft','published','superseded'))");
        DB::statement("CREATE UNIQUE INDEX library_versions_one_published ON library_versions (standard_id) WHERE status = 'published'");

        Schema::create('library_paragraphs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('library_versions')->cascadeOnDelete();
            $table->string('paragraph_ref', 40);
            $table->string('heading', 255)->nullable();
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['version_id', 'paragraph_ref']);
        });
        DB::statement("ALTER TABLE library_paragraphs ADD COLUMN search tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(paragraph_ref,'') || ' ' || coalesce(heading,'') || ' ' || body)) STORED");
        DB::statement('CREATE INDEX library_paragraphs_search_idx ON library_paragraphs USING gin (search)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION library_paragraphs_guard() RETURNS trigger AS $$
            DECLARE v_status text;
            BEGIN
                SELECT status INTO v_status FROM library_versions
                 WHERE id = (CASE WHEN TG_OP = 'DELETE' THEN OLD.version_id ELSE NEW.version_id END);
                IF v_status IS NOT NULL AND v_status <> 'draft' THEN
                    RAISE EXCEPTION 'library paragraphs of a % version are immutable', v_status;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER library_paragraphs_immutable BEFORE INSERT OR UPDATE OR DELETE ON library_paragraphs
                FOR EACH ROW EXECUTE FUNCTION library_paragraphs_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS library_paragraphs_immutable ON library_paragraphs; DROP FUNCTION IF EXISTS library_paragraphs_guard();');
        Schema::dropIfExists('library_paragraphs');
        Schema::dropIfExists('library_versions');
        Schema::dropIfExists('library_standards');
        Schema::dropIfExists('library_frameworks');
    }
};
