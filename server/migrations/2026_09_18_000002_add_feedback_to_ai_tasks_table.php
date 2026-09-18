<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_tasks', function (Blueprint $table) {
            $table->tinyInteger('feedback_rating')->nullable()->index()->after('total_tokens');
            $table->text('feedback_comment')->nullable()->after('feedback_rating');
            $table->timestamp('feedback_at')->nullable()->after('feedback_comment');
        });
    }

    public function down(): void
    {
        Schema::table('ai_tasks', function (Blueprint $table) {
            $table->dropColumn(['feedback_rating', 'feedback_comment', 'feedback_at']);
        });
    }
};
