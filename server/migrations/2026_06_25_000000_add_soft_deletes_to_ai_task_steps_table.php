<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_task_steps') && !Schema::hasColumn('ai_task_steps', 'deleted_at')) {
            Schema::table('ai_task_steps', function (Blueprint $table) {
                $table->softDeletes()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_task_steps') && Schema::hasColumn('ai_task_steps', 'deleted_at')) {
            Schema::table('ai_task_steps', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
