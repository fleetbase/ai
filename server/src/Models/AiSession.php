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

class AiSession extends Model
{
    use HasUuid;
    use HasApiModelBehavior;
    use Searchable;
    use Filterable;
    use SoftDeletes;

    protected $table = 'ai_sessions';

    protected $fillable = [
        'company_uuid',
        'created_by_uuid',
        'title',
        'status',
        'metadata',
        'last_message_at',
        'ended_at',
    ];

    protected $casts = [
        'metadata'        => Json::class,
        'last_message_at' => 'datetime',
        'ended_at'        => 'datetime',
    ];

    protected $searchableColumns = ['title', 'status'];

    public function tasks()
    {
        return $this->hasMany(AiTask::class, 'ai_session_uuid', 'uuid');
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
