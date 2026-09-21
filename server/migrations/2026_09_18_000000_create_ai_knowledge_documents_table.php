<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->string('source')->index();
            $table->string('url', 500)->unique();
            $table->string('path')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('module')->nullable()->index();
            $table->string('section')->nullable();
            $table->string('audience')->default('end_user')->index();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_documents');
    }
};
