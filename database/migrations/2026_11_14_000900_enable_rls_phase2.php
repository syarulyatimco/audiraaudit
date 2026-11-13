<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TENANT_TABLES = [
        'documents', 'document_versions', 'working_papers', 'wp_signoffs', 'review_notes',
        'working_paper_documents', 'working_paper_citations', 'entity_understanding_sections',
    ];

    private const CONDITION = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement('CREATE POLICY tenant_isolation ON '.$table.' USING ('.self::CONDITION.') WITH CHECK ('.self::CONDITION.')');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
