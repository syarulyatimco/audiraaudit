<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable();            // encrypted cast
            $table->text('mfa_recovery_codes')->nullable();    // encrypted JSON of sha256 hashes
            $table->timestampTz('mfa_enabled_at')->nullable();
            $table->unsignedBigInteger('mfa_last_timestep')->nullable(); // TOTP replay protection
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mfa_secret', 'mfa_recovery_codes', 'mfa_enabled_at', 'mfa_last_timestep']));
    }
};
