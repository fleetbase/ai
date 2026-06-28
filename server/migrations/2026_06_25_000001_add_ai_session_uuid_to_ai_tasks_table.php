<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_tasks') && !Schema::hasColumn('ai_tasks', 'ai_session_uuid')) {
            Schema::table('ai_tasks', function (Blueprint $table) {
                $table->foreignUuid('ai_session_uuid')->nullable()->after('uuid')->index()->references('uuid')->on('ai_sessions')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_tasks') && Schema::hasColumn('ai_tasks', 'ai_session_uuid')) {
            Schema::table('ai_tasks', function (Blueprint $table) {
                $table->dropForeign(['ai_session_uuid']);
                $table->dropColumn('ai_session_uuid');
            });
        }
    }
};
