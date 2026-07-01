<?php

namespace Fleetbase\Ai\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\Model;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasUuid;

class AiAdminAccessLog extends Model
{
    use HasUuid;
    use HasApiModelBehavior;

    protected $table = 'ai_admin_access_logs';

    protected $fillable = [
        'company_uuid',
        'ai_session_uuid',
        'ai_task_uuid',
        'viewed_by_uuid',
        'action',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => Json::class,
    ];
}
