<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name', 120)->nullable();
            $table->string('firm_role', 20);
            $table->boolean('is_firm_admin')->default(false);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['firm_id', 'email']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('registration_no', 40)->nullable();
            $table->string('entity_type', 40);
            $table->string('reporting_framework', 20);
            $table->string('industry', 120)->nullable();
            $table->unsignedTinyInteger('fye_month');
            $table->unsignedTinyInteger('fye_day');
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 30)->default('prospect');
            $table->string('acceptance_risk', 10)->nullable();
            $table->boolean('independence_confirmed')->default(false);
            $table->text('acceptance_notes')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['firm_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX clients_firm_regno_unique ON clients (firm_id, lower(registration_no)) WHERE registration_no IS NOT NULL AND deleted_at IS NULL');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('prospect','pending_acceptance','accepted','declined','inactive'))");
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_fye_check CHECK (fye_month BETWEEN 1 AND 12 AND fye_day BETWEEN 1 AND 31)');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('position', 120)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
        });

        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->string('engagement_type', 40);
            $table->string('reporting_framework', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('reporting_deadline')->nullable();
            $table->string('status', 20)->default('planning');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['firm_id', 'code']);
            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'client_id']);
        });
        DB::statement('ALTER TABLE engagements ADD CONSTRAINT engagements_period_check CHECK (period_end > period_start)');
        DB::statement("ALTER TABLE engagements ADD CONSTRAINT engagements_status_check CHECK (status IN ('planning','fieldwork','completion','reporting','signed_off','archived'))");

        Schema::create('engagement_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->foreignId('added_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['engagement_id', 'user_id']);
            $table->index(['firm_id', 'user_id']);
        });
        DB::statement("CREATE UNIQUE INDEX engagement_members_one_partner ON engagement_members (engagement_id) WHERE role = 'engagement_partner'");
        DB::statement("CREATE UNIQUE INDEX engagement_members_one_eqr ON engagement_members (engagement_id) WHERE role = 'eqr_reviewer'");

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->morphs('approvable');
            $table->string('type', 40);
            $table->string('status', 20)->default('pending');
            $table->text('request_comment')->nullable();
            $table->text('decision_comment')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestampTz('requested_at');
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->index(['firm_id', 'status']);
        });
        DB::statement('ALTER TABLE approvals ADD CONSTRAINT approvals_segregation_check CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_status_check CHECK (status IN ('pending','approved','rejected','cancelled'))");
        DB::statement("ALTER TABLE approvals ADD CONSTRAINT approvals_decision_check CHECK ((status IN ('approved','rejected')) = (decided_by IS NOT NULL AND decided_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX approvals_one_pending ON approvals (approvable_type, approvable_id, type) WHERE status = 'pending'");

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 20)->default('user');
            $table->string('actor_label', 120);
            $table->string('event', 80);
            $table->nullableMorphs('subject');
            $table->string('description', 500);
            $table->jsonb('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['firm_id', 'created_at']);
        });

        // Append-only audit trail. Cascades from firm deletion are the only permitted delete path
        // (pg_trigger_depth() > 0 means the DELETE originates from a FK cascade).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_logs_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'activity_logs is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_logs_no_update BEFORE UPDATE OR DELETE ON activity_logs
                FOR EACH ROW EXECUTE FUNCTION activity_logs_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_no_update ON activity_logs; DROP FUNCTION IF EXISTS activity_logs_immutable();');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('engagement_members');
        Schema::dropIfExists('engagements');
        Schema::dropIfExists('client_contacts');
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['client_id']));
        Schema::dropIfExists('clients');
        Schema::dropIfExists('invitations');
    }
};
