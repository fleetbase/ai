<?php

namespace Fleetbase\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A documentation page indexed for Fleetbase AI. Knowledge is shared system-wide, not per company.
 */
class AiKnowledgeDocument extends Model
{
    protected $table = 'ai_knowledge_documents';

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
        'source',
        'url',
        'path',
        'title',
        'description',
        'module',
        'section',
        'audience',
        'content_hash',
        'fetched_at',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
    ];

    public function chunks()
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'ai_knowledge_document_uuid', 'uuid')->orderBy('position');
    }
}
