<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central updates: version/OS-update state per deployment, one row per update run,
     * update log lines live in deployment_logs (with update_run_id).
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('app_version', 64)->nullable();
            $table->string('latest_version', 64)->nullable();
            $table->unsignedInteger('os_updates')->nullable();
            $table->timestamp('updates_checked_at')->nullable();
            $table->text('update_check_error')->nullable();
        });

        Schema::create('update_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('queued'); // queued|running|success|failed|rolled_back|cancelled
            $table->string('trigger', 20)->default('manual'); // manual|schedule
            $table->string('step', 32)->nullable();
            $table->string('snapshot', 64)->nullable();
            $table->boolean('snapshot_exists')->default(false);
            $table->string('from_version', 64)->nullable();
            $table->string('to_version', 64)->nullable();
            $table->unsignedInteger('os_packages')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['deployment_id', 'id']);
        });

        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->foreignId('update_run_id')->nullable()->after('deployment_id')
                ->constrained('update_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('update_run_id');
        });
        Schema::dropIfExists('update_runs');
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'latest_version', 'os_updates', 'updates_checked_at', 'update_check_error']);
        });
    }
};
