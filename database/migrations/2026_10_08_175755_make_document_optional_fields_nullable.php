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
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('category', 50)->nullable()->change();
            $table->string('mime_type')->nullable()->change();
            $table->string('extension', 10)->nullable()->change();
            $table->longText('content')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('category', 50)->nullable(false)->change();
            $table->string('mime_type')->nullable(false)->change();
            $table->string('extension', 10)->nullable(false)->change();
            $table->longText('content')->nullable(false)->change();
        });
    }
};
