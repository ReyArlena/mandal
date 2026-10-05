<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('app', 32);
            $table->string('provider', 32);
            $table->string('repo');
            $table->string('token', 64)->unique();
            $table->text('secret');
            $table->string('remote_id')->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->string('last_delivery_status', 32)->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'app']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
