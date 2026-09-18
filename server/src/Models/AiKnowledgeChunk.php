<?php

namespace Fleetbase\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A heading-delimited section of an indexed documentation page.
 */
class AiKnowledgeChunk extends Model
{
    protected $table = 'ai_knowledge_chunks';

    public function getConnectionName()
    {
        return $this->connection ?: config('fleetbase.connection.db', 'mysql');
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'uuid',
        'ai_knowledge_document_uuid',
        'heading',
        'heading_path',
        'anchor',
        'audience',
        'position',
        'content',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function document()
    {
        return $this->belongsTo(AiKnowledgeDocument::class, 'ai_knowledge_document_uuid', 'uuid');
    }
}
