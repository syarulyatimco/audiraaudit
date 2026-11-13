<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — client portal and PBC ("prepared by client") management: portal invitations for
 * client contacts, PBC items with due dates and review, client uploads linked to items,
 * engagement messages (AI drafts need a person to approve and send), and document extractions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->string('email', 190);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pbc_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 40)->default('other');
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_contact_id')->nullable()->constrained('users')->nullOnDelete(); // client portal user
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('draft'); // draft | requested | submitted | accepted | returned | cancelled
            $table->text('review_note')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('source', 10)->default('manual');
            $table->timestamps();
            $table->unique(['engagement_id', 'reference']);
            $table->index(['firm_id', 'engagement_id', 'status']);
        });
        DB::statement("ALTER TABLE pbc_items ADD CONSTRAINT pbc_status CHECK (status IN ('draft','requested','submitted','accepted','returned','cancelled'))");
        DB::statement("ALTER TABLE pbc_items ADD CONSTRAINT pbc_category CHECK (category IN ('financial','legal','tax','banking','payroll','fixed_assets','receivables','payables','inventory','governance','other'))");
        DB::statement("ALTER TABLE pbc_items ADD CONSTRAINT pbc_returned_note CHECK (status <> 'returned' OR review_note IS NOT NULL)");

        Schema::create('pbc_item_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('pbc_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('linked_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['pbc_item_id', 'document_id']);
        });

        Schema::create('engagement_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->constrained()->restrictOnDelete();
            $table->foreignId('pbc_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10);          // outbound (firm → client) | inbound (client → firm)
            $table->string('status', 10);             // draft | sent
            $table->string('subject', 200);
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users'); // null for agent drafts
            $table->string('author_type', 10)->default('user'); // user | agent
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->jsonb('pbc_item_ids')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['firm_id', 'engagement_id', 'status']);
        });
        DB::statement("ALTER TABLE engagement_messages ADD CONSTRAINT msg_dir CHECK (direction IN ('outbound','inbound'))");
        DB::statement("ALTER TABLE engagement_messages ADD CONSTRAINT msg_status CHECK (status IN ('draft','sent'))");
        // An outbound message reaches the client only when a person has sent it.
        DB::statement("ALTER TABLE engagement_messages ADD CONSTRAINT msg_sent CHECK (status <> 'sent' OR (sent_by IS NOT NULL AND sent_at IS NOT NULL))");
        DB::statement("ALTER TABLE engagement_messages ADD CONSTRAINT msg_inbound CHECK (direction <> 'inbound' OR (status = 'sent' AND author_type = 'user'))");

        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_run_id')->nullable()->constrained('ai_runs')->nullOnDelete();
            $table->string('document_type', 60);
            $table->text('summary')->nullable();
            $table->jsonb('fields');
            $table->foreignId('accepted_by')->constrained('users');
            $table->timestamps();
            $table->unique(['document_version_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sent_message_frozen() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'sent' THEN RAISE EXCEPTION 'sent messages cannot be deleted'; END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'sent' AND (to_jsonb(NEW) - 'read_at' - 'updated_at') <> (to_jsonb(OLD) - 'read_at' - 'updated_at') THEN
                    RAISE EXCEPTION 'sent messages are immutable';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sent_message_frozen BEFORE UPDATE OR DELETE ON engagement_messages FOR EACH ROW EXECUTE FUNCTION sent_message_frozen();
        SQL);

        $cond = "current_setting('app.bypass_rls', true) = 'on' OR firm_id = NULLIF(current_setting('app.firm_id', true), '')::bigint";
        foreach (['portal_invitations', 'pbc_items', 'pbc_item_documents', 'engagement_messages', 'document_extractions'] as $t) {
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$cond}) WITH CHECK ({$cond})");
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sent_message_frozen ON engagement_messages; DROP FUNCTION IF EXISTS sent_message_frozen();');
        foreach (['document_extractions', 'engagement_messages', 'pbc_item_documents', 'pbc_items', 'portal_invitations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
