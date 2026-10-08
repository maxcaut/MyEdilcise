<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('user_id')->unique();
            $table->string('telegram_id')->nullable()->unique();
            $table->string('link_hash', 64)->nullable()->unique();
            $table->timestamp('link_expires_at')->nullable();
            $table->longText('draft')->nullable();
            $table->timestamp('draft_expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('chat_id')->index();
            $table->text('payload')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_accounts');
    }
};
