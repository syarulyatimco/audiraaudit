<?php

namespace Tests\Feature\Tenancy;

use App\Models\ActivityLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves isolation holds at the database layer even when application scopes are bypassed
 * (raw queries), using the non-superuser application role.
 */
class RowLevelSecurityTest extends TestCase
{
    public function test_application_role_is_not_superuser_and_cannot_bypass_rls(): void
    {
        $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        $this->assertFalse($role->rolsuper, 'App must not connect as a PostgreSQL superuser.');
        $this->assertFalse($role->rolbypassrls);
    }

    public function test_raw_queries_only_return_current_tenant_rows(): void
    {
        [$firmA, $adminA] = $this->makeFirm();
        [$firmB, $adminB] = $this->makeFirm();
        $this->client($adminA);
        $this->client($adminB);
        $this->client($adminB);

        $this->tenant()->set($firmA->id);
        $this->assertSame(1, DB::table('clients')->count());
        $this->assertSame([$firmA->id], DB::table('firms')->pluck('id')->all());

        $this->tenant()->set($firmB->id);
        $this->assertSame(2, DB::table('clients')->count());

        $this->tenant()->set(null);
        $this->assertSame(0, DB::table('clients')->count());
    }

    public function test_raw_insert_into_another_tenant_is_rejected(): void
    {
        [$firmA] = $this->makeFirm();
        [$firmB, $adminB] = $this->makeFirm();

        $this->tenant()->set($firmA->id);
        $this->expectException(QueryException::class);
        DB::table('client_contacts')->insert([
            'firm_id' => $firmB->id, 'client_id' => $this->tenant()->runAsSystem(fn () => $this->client($adminB)->id),
            'name' => 'Injected', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_activity_log_is_append_only_at_database_level(): void
    {
        [, $admin] = $this->makeFirm();
        $log = app(\App\Support\Audit\ActivityLogger::class)->log('test.event', 'Something happened', null, [], $admin, $admin->firm_id);

        try {
            DB::table('activity_logs')->where('id', $log->id)->update(['description' => 'tampered']);
            $this->fail('Update should have been blocked');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_activity_log_model_refuses_updates(): void
    {
        [, $admin] = $this->makeFirm();
        $log = app(\App\Support\Audit\ActivityLogger::class)->log('test.event', 'x', null, [], $admin, $admin->firm_id);

        $this->expectException(\LogicException::class);
        $log->description = 'changed';
        $log->save();
    }

    public function test_activity_logs_are_isolated_between_firms(): void
    {
        [, $adminA] = $this->makeFirm();
        [, $adminB] = $this->makeFirm();
        app(\App\Support\Audit\ActivityLogger::class)->log('client.created', 'Secret B event', null, [], $adminB, $adminB->firm_id);

        $this->actingAs($adminA)->get('/firm/activity')->assertOk()->assertDontSee('Secret B event');
        $this->tenant()->set($adminA->firm_id);
        $this->assertSame(0, ActivityLog::where('description', 'Secret B event')->count());
    }
}
