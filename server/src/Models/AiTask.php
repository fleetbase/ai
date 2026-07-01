<?php

namespace Fleetbase\Ai\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Company;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\Filterable;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiTask extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use Searchable;
    use Filterable;
    use SoftDeletes;

    protected $table = 'ai_tasks';

    protected $fillable = [
        'ai_session_uuid',
        'company_uuid',
        'created_by_uuid',
        'task_type',
        'status',
        'prompt',
        'response',
        'response_summary',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'context',
        'usage',
        'metadata',
        'error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'context'      => Json::class,
        'usage'        => Json::class,
        'metadata'     => Json::class,
        'error'        => Json::class,
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $searchableColumns = ['prompt', 'response_summary', 'task_type', 'status'];

    public function steps()
    {
        return $this->hasMany(AiTaskStep::class, 'ai_task_uuid', 'uuid');
    }

    public function session()
    {
        return $this->belongsTo(AiSession::class, 'ai_session_uuid', 'uuid');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_uuid', 'uuid');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }
}
