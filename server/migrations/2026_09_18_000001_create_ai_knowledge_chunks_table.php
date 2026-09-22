<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignUuid('ai_knowledge_document_uuid')->index()->references('uuid')->on('ai_knowledge_documents')->onDelete('cascade');
            $table->string('heading')->nullable();
            $table->string('heading_path', 500)->nullable();
            $table->string('anchor')->nullable();
            $table->string('audience')->default('end_user')->index();
            $table->unsignedInteger('position')->default(0);
            $table->mediumText('content');
            $table->timestamps();
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('ai_knowledge_chunks', function (Blueprint $table) {
                $table->fullText(['heading_path', 'content'], 'ai_knowledge_chunks_fulltext');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
