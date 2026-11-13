<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — invoices (gateway-agnostic), engagement monitoring alerts, firm-granted break-glass
 * support access, and supporting indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3)->default('MYR');
            $table->jsonb('lines');
            $table->bigInteger('subtotal_sen');
            $table->string('tax_name', 60)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->bigInteger('tax_sen')->default(0);
            $table->bigInteger('total_sen');
            $table->string('status', 20)->default('issued');   // issued | paid | void
            $table->date('due_on');
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('gateway', 30)->default('manual');
            $table->string('gateway_reference', 120)->nullable();
            $table->text('void_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['subscription_id', 'period_start']);
            $table->index(['firm_id', 'status']);
        });
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT inv_status CHECK (status IN ('issued','paid','void'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT inv_paid CHECK (status <> 'paid' OR (paid_at IS NOT NULL AND payment_reference IS NOT NULL))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT inv_void CHECK (status <> 'void' OR void_reason IS NOT NULL)");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT inv_total CHECK (total_sen = subtotal_sen + tax_sen AND subtotal_sen >= 0 AND tax_sen >= 0)');
        // Issued amounts never change; a wrong invoice is voided and reissued.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoice_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'invoices cannot be deleted'; END IF;
                IF OLD.status <> 'issued' THEN RAISE EXCEPTION 'paid or void invoices are immutable'; END IF;
                IF (to_jsonb(NEW) - 'status' - 'paid_at' - 'payment_method' - 'payment_reference' - 'gateway_reference' - 'void_reason' - 'recorded_by' - 'updated_at')
                   <> (to_jsonb(OLD) - 'status' - 'paid_at' - 'payment_method' - 'payment_reference' - 'gateway_reference' - 'void_reason' - 'recorded_by' - 'updated_at') THEN
                    RAISE EXCEPTION 'issued invoice amounts are immutable';
                END IF;
                RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS invoices_frozen ON invoices;
            CREATE TRIGGER invoices_frozen BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION invoice_frozen();
        SQL);

        Schema::create('monitoring_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40);
            $table->string('severity', 10);                // info | warn | danger
            $table->string('message', 300);
            $table->jsonb('data')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['engagement_id', 'kind', 'resolved_at']);
        });
        DB::statement("ALTER TABLE monitoring_alerts ADD CONSTRAINT ma_severity CHECK (severity IN ('info','warn','danger'))");
        DB::statement('CREATE UNIQUE INDEX monitoring_alerts_open_unique ON monitoring_alerts (engagement_id, kind) WHERE resolved_at IS NULL');

        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users');
            $table->text('reason');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT sag_window CHECK (expires_at > created_at AND expires_at <= created_at + interval \'72 hours\')');

        // Indexes for the completion/monitoring queries added in Phases 8–10.
        DB::statement('CREATE INDEX IF NOT EXISTS approvals_engagement_pending ON approvals (engagement_id) WHERE status = \'pending\'');
        DB::statement('CREATE INDEX IF NOT EXISTS findings_engagement_status ON findings (engagement_id, status)');
        DB::statement('CREATE INDEX IF NOT EXISTS pbc_items_engagement_status ON pbc_items (engagement_id, status, due_on)');

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['invoices', 'monitoring_alerts', 'support_access_grants'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS approvals_engagement_pending');
        DB::statement('DROP INDEX IF EXISTS findings_engagement_status');
        DB::statement('DROP INDEX IF EXISTS pbc_items_engagement_status');
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('monitoring_alerts');
        Schema::dropIfExists('invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_frozen() CASCADE');
    }
};
