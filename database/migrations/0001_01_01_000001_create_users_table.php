<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('firm_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_id')->nullable()->index(); // FK added with clients table
            $table->string('type', 20);
            $table->string('firm_role', 20)->nullable();
            $table->boolean('is_firm_admin')->default(false);
            $table->string('name', 120);
            $table->string('email');
            $table->string('password');
            $table->string('status', 20)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->index(['firm_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (
            (type = 'platform' AND firm_id IS NULL AND client_id IS NULL AND firm_role IS NULL)
         OR (type = 'firm' AND firm_id IS NOT NULL AND client_id IS NULL AND firm_role IN ('partner','manager','senior','associate','administrator'))
         OR (type = 'client' AND firm_id IS NOT NULL AND client_id IS NOT NULL AND firm_role IS NULL AND is_firm_admin = false))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','deactivated'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
