<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_admin_access_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignUuid('company_uuid')->nullable()->index()->references('uuid')->on('companies');
            $table->foreignUuid('ai_session_uuid')->nullable()->index()->references('uuid')->on('ai_sessions')->onDelete('set null');
            $table->foreignUuid('ai_task_uuid')->nullable()->index()->references('uuid')->on('ai_tasks')->onDelete('set null');
            $table->foreignUuid('viewed_by_uuid')->nullable()->index()->references('uuid')->on('users')->onDelete('set null');
            $table->string('action')->nullable()->index();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_admin_access_logs');
    }
};
