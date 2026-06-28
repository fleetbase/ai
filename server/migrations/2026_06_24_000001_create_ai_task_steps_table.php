<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_steps', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignUuid('ai_task_uuid')->nullable()->index()->references('uuid')->on('ai_tasks')->onDelete('cascade');
            $table->foreignUuid('company_uuid')->nullable()->index()->references('uuid')->on('companies');
            $table->foreignUuid('created_by_uuid')->nullable()->index()->references('uuid')->on('users');
            $table->string('type')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('tool')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('usage')->nullable();
            $table->json('metadata')->nullable();
            $table->json('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_steps');
    }
};
