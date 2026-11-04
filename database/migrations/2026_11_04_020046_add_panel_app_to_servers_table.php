<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name of the app hosting the Mandal panel on each server, as its
     * inventory last reported it, so the panel knows which server runs it
     * without connecting.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('panel_app', 32)->nullable()->after('cli_build');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('panel_app');
        });
    }
};
