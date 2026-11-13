<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('price_per_user_sen'); // RM x 100
            $table->char('currency', 3)->default('MYR');
            $table->unsignedSmallInteger('min_paid_users')->default(3);
            $table->unsignedSmallInteger('max_users')->nullable();
            $table->unsignedInteger('ai_credits_per_user')->default(0);
            $table->jsonb('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('firms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('registration_no', 40)->nullable();
            $table->string('audit_firm_no', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address_line1', 160)->nullable();
            $table->string('address_line2', 160)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->char('country', 2)->default('MY');
            $table->string('default_framework', 20)->default('mfrs');
            $table->string('status', 20)->default('trial')->index();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement("ALTER TABLE firms ADD CONSTRAINT firms_status_check CHECK (status IN ('trial','active','suspended','cancelled'))");

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('price_per_user_sen');
            $table->unsignedSmallInteger('min_paid_users');
            $table->timestampTz('current_period_start');
            $table->timestampTz('current_period_end');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['firm_id', 'status']);
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing','active','past_due','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('firms');
        Schema::dropIfExists('plans');
    }
};
