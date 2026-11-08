<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional Proxmox resource pool new containers are created in. Lets the API
     * token's write permissions be limited to that pool.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('pool', 40)->nullable()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('pool');
        });
    }
};
