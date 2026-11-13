<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level tenant isolation (defence in depth behind FirmScope). See docs/SECURITY.md.
 * Any new tenant-owned table MUST be added to self::TENANT_TABLES in a new migration.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'subscriptions', 'invitations', 'clients', 'client_contacts', 'engagements',
        'engagement_members', 'approvals', 'activity_logs',
    ];

    private const CONDITION = "current_setting('app.bypass_rls', true) = 'on' OR %s = NULLIF(current_setting('app.firm_id', true), '')::bigint";

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            $this->protect($table, 'firm_id');
        }
        $this->protect('firms', 'id');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([...self::TENANT_TABLES, 'firms'] as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function protect(string $table, string $column): void
    {
        $condition = sprintf(self::CONDITION, $column);
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$condition}) WITH CHECK ({$condition})");
    }
};
