<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The build hash of the CLI last seen on each server, so the servers
     * list can flag an out-of-date one without connecting.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('cli_build', 12)->nullable()->after('host_key');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('cli_build');
        });
    }
};
