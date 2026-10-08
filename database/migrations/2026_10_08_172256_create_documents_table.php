<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->string('supplier', 150);
            $table->date('date')->index();
            $table->string('category', 50)->index();
            $table->decimal('amount', 10, 2);
            $table->uuid('uploaded_by');
            $table->string('mime_type');
            $table->string('extension', 10);
            $table->longText('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
