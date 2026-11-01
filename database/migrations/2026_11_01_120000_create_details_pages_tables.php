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
        Schema::create('details_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('slug', 100)->unique();
            $table->string('title', 120)->nullable();
            $table->text('intro')->nullable();
            $table->boolean('hide_branding')->default(false);
            $table->boolean('hide_credit')->default(false);
            $table->timestamps();
        });

        Schema::create('details_page_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('details_page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name', 80);
            $table->string('version', 80)->nullable();
            $table->string('address', 200);
            $table->string('password', 200)->nullable();
            $table->string('status_source', 16)->nullable();
            $table->string('status', 16)->nullable();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('container', 128)->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();

            $table->index(['details_page_id', 'sort']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('details_page_entries');
        Schema::dropIfExists('details_pages');
    }
};
