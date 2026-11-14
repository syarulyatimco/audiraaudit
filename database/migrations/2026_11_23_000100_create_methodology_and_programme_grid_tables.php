<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — audit methodology libraries and Excel-style programme grids.
 *
 *  methodology_libraries   AUDIRA's platform template (firm_id NULL, read-only to firms) and firm copies.
 *  methodology_programmes  One programme per stage (planning / controls / substantive), routed to the
 *                          engagement as a whole or to FSLIs by code (or by classification as a fallback).
 *  methodology_steps       The rows of a library programme.
 *  programme_sheets        The engagement's copy of a programme, attached to an FSLI and stage.
 *  programme_rows          Grid rows with preparer ("done by") and reviewer sign-offs bound to a content hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('methodology_libraries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->nullable()->constrained()->restrictOnDelete(); // NULL = AUDIRA template
            $table->string('code', 40)->nullable();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->foreignId('based_on_id')->nullable()->constrained('methodology_libraries')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('active'); // active | archived
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['firm_id', 'status']);
        });
        DB::statement("ALTER TABLE methodology_libraries ADD CONSTRAINT ml_status CHECK (status IN ('active','archived'))");
        DB::statement('CREATE UNIQUE INDEX methodology_libraries_platform_code ON methodology_libraries (code) WHERE firm_id IS NULL');

        Schema::create('methodology_programmes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('library_id')->constrained('methodology_libraries')->cascadeOnDelete();
            $table->string('stage', 20);  // planning | controls | substantive
            $table->string('scope', 20);  // engagement | fsli | classification
            $table->jsonb('fsli_codes')->default('[]');
            $table->string('fsli_classification', 30)->nullable();
            $table->string('ref_prefix', 12);
            $table->string('title', 200);
            $table->text('objective')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['library_id', 'ref_prefix']);
        });
        DB::statement("ALTER TABLE methodology_programmes ADD CONSTRAINT mp_stage CHECK (stage IN ('planning','controls','substantive'))");
        DB::statement("ALTER TABLE methodology_programmes ADD CONSTRAINT mp_scope CHECK (
            (scope = 'engagement' AND fsli_classification IS NULL)
            OR (scope = 'fsli' AND jsonb_array_length(fsli_codes) > 0 AND fsli_classification IS NULL)
            OR (scope = 'classification' AND fsli_classification IS NOT NULL))");

        Schema::create('methodology_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('programme_id')->constrained('methodology_programmes')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('ref', 20);
            $table->text('procedure');
            $table->string('assertions', 60)->nullable();
            $table->string('isa_refs', 160)->nullable();
            $table->text('guidance')->nullable();
            $table->timestamps();
            $table->index(['programme_id', 'position']);
        });

        Schema::table('engagements', function (Blueprint $table) {
            $table->foreignId('methodology_library_id')->nullable()->constrained('methodology_libraries')->restrictOnDelete();
            $table->timestamp('methodology_applied_at')->nullable();
        });

        Schema::create('programme_sheets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_fsli_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('stage', 20);
            $table->string('reference', 20);
            $table->string('title', 200);
            $table->text('objective')->nullable();
            $table->foreignId('source_programme_id')->nullable()->constrained('methodology_programmes')->nullOnDelete();
            $table->jsonb('columns')->default('[]'); // auditor-added columns: [{key, label}]
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
            $table->index(['firm_id', 'engagement_id', 'stage']);
        });
        DB::statement("ALTER TABLE programme_sheets ADD CONSTRAINT ps_stage CHECK (stage IN ('planning','controls','substantive'))");

        Schema::create('programme_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('sheet_id')->constrained('programme_sheets')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('ref', 20)->default('');
            $table->text('procedure')->default('');
            $table->string('assertions', 60)->nullable();
            $table->string('risk', 200)->nullable();
            $table->text('wp_ref')->nullable();
            $table->text('conclusion')->nullable();
            $table->jsonb('extra')->default('{}');
            $table->string('isa_refs', 160)->nullable();
            $table->text('guidance')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->char('signed_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->index(['sheet_id', 'position']);
        });
        // Sign-off integrity: "done" needs the person, time and content hash together; review needs
        // a prior "done" by someone else.
        DB::statement('ALTER TABLE programme_rows ADD CONSTRAINT pr_done CHECK ((done_by IS NULL AND done_at IS NULL AND signed_hash IS NULL) OR (done_by IS NOT NULL AND done_at IS NOT NULL AND signed_hash IS NOT NULL))');
        DB::statement('ALTER TABLE programme_rows ADD CONSTRAINT pr_review CHECK ((reviewed_by IS NULL AND reviewed_at IS NULL) OR (done_by IS NOT NULL AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND reviewed_by <> done_by))');
        // A signed row cannot be deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION programme_row_signed_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.done_by IS NOT NULL THEN RAISE EXCEPTION 'signed programme rows cannot be deleted'; END IF;
                RETURN OLD;
            END $$;
            DROP TRIGGER IF EXISTS programme_rows_signed_guard ON programme_rows;
            CREATE TRIGGER programme_rows_signed_guard BEFORE DELETE ON programme_rows FOR EACH ROW EXECUTE FUNCTION programme_row_signed_guard();
        SQL);

        $tenant = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['programme_sheets', 'programme_rows'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$tenant}) WITH CHECK ({$tenant})");
        }
        // Methodology: everyone may READ the AUDIRA template (firm_id NULL); only the owning firm may
        // write its copies; the template itself is written only by platform seeding (bypass).
        foreach (['methodology_libraries', 'methodology_programmes', 'methodology_steps'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY methodology_read ON {$t} FOR SELECT USING (firm_id IS NULL OR {$tenant})");
            DB::statement("CREATE POLICY methodology_insert ON {$t} FOR INSERT WITH CHECK ({$tenant})");
            DB::statement("CREATE POLICY methodology_update ON {$t} FOR UPDATE USING ({$tenant}) WITH CHECK ({$tenant})");
            DB::statement("CREATE POLICY methodology_delete ON {$t} FOR DELETE USING ({$tenant})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_rows');
        DB::unprepared('DROP FUNCTION IF EXISTS programme_row_signed_guard() CASCADE');
        Schema::dropIfExists('programme_sheets');
        Schema::table('engagements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('methodology_library_id');
            $table->dropColumn('methodology_applied_at');
        });
        Schema::dropIfExists('methodology_steps');
        Schema::dropIfExists('methodology_programmes');
        Schema::dropIfExists('methodology_libraries');
    }
};
