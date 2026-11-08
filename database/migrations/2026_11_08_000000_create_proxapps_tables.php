<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('token_id');
            $table->text('token_secret');
            $table->string('tls_mode', 16)->default('pin');
            $table->string('fingerprint')->nullable();
            $table->string('pin')->nullable();
            $table->timestamps();
        });

        Schema::create('network_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bridge')->default('vmbr0');
            $table->unsignedInteger('vlan')->nullable();
            $table->string('cidr');
            $table->string('gateway')->nullable();
            $table->string('dns')->nullable();
            $table->string('searchdomain')->nullable();
            $table->string('pool_start')->nullable();
            $table->string('pool_end')->nullable();
            $table->string('vmid_pattern')->nullable();
            $table->boolean('firewall')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->string('app_id');
            $table->string('app_name');
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('node');
            $table->unsignedInteger('vmid');
            $table->string('hostname');
            $table->string('ip')->nullable();
            $table->string('status', 16)->index();
            $table->string('step')->nullable();
            $table->json('spec');
            $table->text('secrets')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('level', 16)->default('info');
            $table->string('step')->nullable();
            $table->text('message');
            $table->timestamp('created_at')->nullable();
            $table->index(['deployment_id', 'id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('deployment_logs');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('network_profiles');
        Schema::dropIfExists('connections');
    }
};
