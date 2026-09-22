<?php

use Fleetbase\Ai\Console\Commands\ExportAiLogs;
use Fleetbase\Ai\Http\Controllers\Internal\AiAdminController;
use Fleetbase\Ai\Http\Controllers\Internal\AiTaskController;
use Fleetbase\Ai\Models\AiAdminAccessLog;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\AiLogExporter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class AiExportTask extends EloquentModel
{
    use Illuminate\Database\Eloquent\SoftDeletes;

    protected $connection = 'default';
    protected $table      = 'ai_tasks';
    protected $guarded    = [];
    protected $casts      = ['metadata' => 'array', 'created_at' => 'datetime', 'completed_at' => 'datetime'];

    public function steps()
    {
        return $this->hasMany(AiExportStep::class, 'ai_task_uuid', 'uuid');
    }

    public function session()
    {
        return $this->belongsTo(AiExportSession::class, 'ai_session_uuid', 'uuid');
    }
}

class AiExportStep extends EloquentModel
{
    protected $connection = 'default';
    protected $table      = 'ai_task_steps';
    protected $guarded    = [];
    protected $casts      = ['output' => 'array'];
}

class AiExportSession extends EloquentModel
{
    protected $connection = 'default';
    protected $table      = 'ai_sessions';
    protected $guarded    = [];
}

function aiExportDatabase(): void
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'default');
    $capsule->getDatabaseManager()->setDefaultConnection('default');
    EloquentModel::setConnectionResolver($capsule->getDatabaseManager());

    $schema = $capsule->getConnection('default')->getSchemaBuilder();
    $schema->create('ai_tasks', function ($table) {
        $table->increments('id');
        foreach (['uuid', 'ai_session_uuid', 'company_uuid', 'created_by_uuid', 'status', 'provider', 'model'] as $column) {
            $table->string($column)->nullable();
        }
        $table->text('prompt')->nullable();
        $table->text('response')->nullable();
        $table->integer('feedback_rating')->nullable();
        $table->text('feedback_comment')->nullable();
        $table->integer('input_tokens')->nullable();
        $table->integer('output_tokens')->nullable();
        $table->integer('total_tokens')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamp('completed_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    $schema->create('ai_task_steps', function ($table) {
        $table->increments('id');
        $table->string('ai_task_uuid');
        $table->string('type');
        $table->json('output')->nullable();
        $table->timestamps();
    });
    $schema->create('ai_sessions', function ($table) {
        $table->increments('id');
        $table->string('uuid');
        $table->string('title');
        $table->timestamps();
    });

    $db = $capsule->getConnection('default');
    $db->table('ai_sessions')->insert(['uuid' => 'session-1', 'title' => 'Where do I add a user?']);
    $db->table('ai_tasks')->insert([
        ['uuid' => 'task-1', 'ai_session_uuid' => 'session-1', 'company_uuid' => 'company-1', 'status' => 'answered', 'provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'prompt' => 'Where do I add a user?', 'response' => 'Open **IAM → Users**, then click "New".', 'feedback_rating' => -1, 'feedback_comment' => 'Wrong page', 'total_tokens' => 42, 'metadata' => json_encode(['tool_calls' => 2, 'degraded' => true]), 'created_at' => '2026-09-01 10:00:00'],
        ['uuid' => 'task-2', 'ai_session_uuid' => 'session-1', 'company_uuid' => 'company-2', 'status' => 'failed', 'provider' => 'openai', 'model' => 'gpt-5.4', 'prompt' => 'Hola', 'response' => null, 'feedback_rating' => null, 'feedback_comment' => null, 'total_tokens' => null, 'metadata' => null, 'created_at' => '2026-09-10 10:00:00'],
    ]);
    $db->table('ai_task_steps')->insert([
        ['ai_task_uuid' => 'task-1', 'type' => 'tool_call', 'output' => json_encode(['results' => [['title' => 'Users']]])],
        ['ai_task_uuid' => 'task-1', 'type' => 'provider_call', 'output' => null],
    ]);
}

function aiExportTo(string $format, ?Builder $query = null): string
{
    $handle = fopen('php://memory', 'w+');
    (new AiLogExporter())->write($query ?? AiExportTask::query(), $handle, $format);
    rewind($handle);

    return stream_get_contents($handle);
}

test('log exporter writes one json line per task with its steps and session', function () {
    aiExportDatabase();

    $lines   = array_values(array_filter(explode("\n", aiExportTo('jsonl'))));
    $records = array_map(fn ($line) => json_decode($line, true), $lines);

    expect($records)->toHaveCount(2)
        ->and($records[0]['uuid'])->toBe('task-1')
        ->and($records[0]['session']['title'])->toBe('Where do I add a user?')
        ->and(array_column($records[0]['steps'], 'type'))->toBe(['tool_call', 'provider_call'])
        ->and($records[0]['steps'][0]['output'])->toBe(['results' => [['title' => 'Users']]])
        ->and($records[1]['steps'])->toBe([]);
});

test('log exporter writes csv rows with review signals', function () {
    aiExportDatabase();

    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", aiExportTo('csv')))));

    expect($rows[0])->toBe(AiLogExporter::CSV_COLUMNS)
        ->and(array_combine($rows[0], $rows[1]))->toMatchArray([
            'uuid'             => 'task-1',
            'response'         => 'Open **IAM → Users**, then click "New".',
            'feedback_rating'  => '-1',
            'feedback_comment' => 'Wrong page',
            'tool_calls'       => '2',
            'degraded'         => 'true',
            'truncated'        => 'false',
        ]);
});

test('export command filters by date and company and rejects unknown formats', function () {
    aiExportDatabase();
    $path = sys_get_temp_dir() . '/fleetbase-ai-export-' . uniqid() . '.jsonl';

    $run = function (array $input) {
        $command = new class extends ExportAiLogs {
            protected function baseQuery(): Builder
            {
                return AiExportTask::query();
            }
        };
        $command->setLaravel(new Illuminate\Container\Container());
        $buffer = new BufferedOutput();
        $args   = new ArrayInput($input, $command->getDefinition());
        $command->setInput($args);
        $command->setOutput(new Illuminate\Console\OutputStyle($args, $buffer));

        return [$command->handle(new AiLogExporter()), $buffer->fetch()];
    };

    [$code, $output] = $run(['--output' => $path, '--from' => '2026-09-05', '--to' => '2026-09-30', '--company' => 'company-2']);
    $written         = array_values(array_filter(explode("\n", file_get_contents($path))));

    [$bad]                           = $run(['--format' => 'xml']);
    [$unwritable, $unwritableOutput] = $run(['--output' => '/nonexistent-dir/export.jsonl']);
    [$withDeleted]                   = $run(['--output' => $path, '--with-deleted' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Exported 1 AI tasks')
        ->and($output)->toContain('Store and share it securely')
        ->and($written)->toHaveCount(1)
        ->and(json_decode($written[0], true)['uuid'])->toBe('task-2')
        ->and($bad)->toBe(1)
        ->and($unwritable)->toBe(1)
        ->and($unwritableOutput)->toContain('Unable to write')
        ->and($withDeleted)->toBe(0);
});

test('admin export requires the audit log permission, logs access, and streams the chosen format', function () {
    $controller = new class extends AiAdminController {
        public array $logs     = [];
        public ?Builder $query = null;
        public bool $allowed   = true;

        protected function can(Fleetbase\Http\Requests\AdminRequest $request, string $permission): bool
        {
            return $this->allowed && $permission === 'ai view audit logs';
        }

        protected function tasksQuery(): Builder
        {
            return $this->query = aiAdminFilterBuilder();
        }

        protected function createAccessLog(array $attributes): AiAdminAccessLog
        {
            $this->logs[] = $attributes;

            return new AiAdminAccessLog();
        }

        protected function download(callable $callback, string $filename, string $contentType)
        {
            ob_start();
            $callback();
            $body = ob_get_clean();

            return compact('filename', 'contentType', 'body');
        }
    };

    $exporter = new class extends AiLogExporter {
        public array $formats = [];

        public function write(Builder $tasks, $handle, string $format = 'jsonl'): int
        {
            $this->formats[] = $format;
            fwrite($handle, 'streamed as ' . $format);

            return 1;
        }
    };

    $response = $controller->export(aiAdminRequestDouble(['format' => 'csv', 'feedback' => 'negative', 'degraded' => '1', 'truncated' => '1'], true), $exporter);
    $default  = $controller->export(aiAdminRequestDouble(['format' => 'xml', 'feedback' => 'positive'], true), $exporter);

    expect($response['contentType'])->toBe('text/csv')
        ->and($response['filename'])->toEndWith('.csv')
        ->and($response['body'])->toBe('streamed as csv')
        ->and($default['contentType'])->toBe('application/x-ndjson')
        ->and($default['body'])->toBe('streamed as jsonl')
        ->and($exporter->formats)->toBe(['csv', 'jsonl'])
        ->and($controller->logs[0]['action'])->toBe('export_tasks')
        ->and($controller->logs[0]['metadata']['format'])->toBe('csv')
        ->and($controller->logs[0]['metadata']['filters'])->toMatchArray(['feedback' => 'negative'])
        ->and($controller->query->calls)->toContain(['where', 'feedback_rating', '>', 0, 'and'])
        ->and(fn () => tap($controller, fn ($c) => $c->allowed = false)->export(aiAdminRequestDouble([], true), $exporter))->toThrow(RuntimeException::class);
});

test('admin export applies the same filters as the log view', function () {
    $controller = new class extends AiAdminController {
        public array $logs     = [];
        public ?Builder $query = null;

        protected function can(Fleetbase\Http\Requests\AdminRequest $request, string $permission): bool
        {
            return true;
        }

        protected function tasksQuery(): Builder
        {
            return $this->query = aiAdminFilterBuilder();
        }

        protected function createAccessLog(array $attributes): AiAdminAccessLog
        {
            $this->logs[] = $attributes;

            return new AiAdminAccessLog();
        }

        protected function download(callable $callback, string $filename, string $contentType)
        {
            return compact('filename');
        }
    };

    $controller->export(aiAdminRequestDouble([
        'ai_session_uuid' => 'session-1',
        'status'          => 'answered',
        'task_status'     => 'failed',
        'session_status'  => 'ended',
        'search'          => ' dispatch ',
        'provider'        => 'openai',
        'from'            => '2026-07-01',
        'to'              => '2026-07-02',
    ], true), new AiLogExporter());

    $calls    = $controller->query->calls;
    $statuses = array_values(array_filter($calls, fn ($call) => $call[0] === 'where' && $call[1] === 'status'));
    $search   = array_values(array_filter($calls, fn ($call) => $call[0] === 'where_nested'))[0][1];

    // task_status wins over the legacy status parameter, so an export matches the answer-status filter.
    expect($statuses)->toBe([['where', 'status', 'failed', null, 'and']])
        ->and($calls)->toContain(['where', 'ai_session_uuid', 'session-1', null, 'and'])
        ->and($calls)->toContain(['where', 'provider', 'openai', null, 'and'])
        ->and($calls)->toContain(['whereHas', 'session', [['where', 'status', 'ended', null, 'and']], '>=', 1])
        ->and($search[0])->toBe(['where', 'prompt', 'like', '%dispatch%', 'and'])
        ->and($search)->toContain(['orWhere', 'ai_session_uuid', 'dispatch', null])
        ->and($controller->logs[0]['metadata']['filters'])->toMatchArray([
            'ai_session_uuid' => 'session-1',
            'task_status'     => 'failed',
            'session_status'  => 'ended',
            'search'          => ' dispatch ',
        ]);
});

test('session filters find sessions with rated, degraded, or truncated answers', function () {
    $controller = new AiAdminController();
    $query      = aiAdminFilterBuilder();

    aiInvokeProtected($controller, 'applySessionFilters', $query, aiAdminRequestDouble(['task_status' => 'failed', 'feedback' => 'negative', 'degraded' => '1', 'truncated' => 'true']));

    $nested = collect($query->calls)->firstWhere(0, 'whereHas');

    expect($nested[1])->toBe('tasks')
        ->and($nested[2])->toBe([
            ['where', 'status', 'failed', null, 'and'],
            ['where', 'feedback_rating', '<', 0, 'and'],
            ['where', 'metadata->degraded', true, null, 'and'],
            ['where', 'metadata->truncated', true, null, 'and'],
        ]);
});

test('users rate their own answers and can clear a rating', function () {
    $task = aiTaskDouble(['uuid' => 'task-uuid']);

    $controller = new class($task) extends AiTaskController {
        public function __construct(private AiTask $task)
        {
        }

        protected function findTask(string $id): AiTask
        {
            return $this->task;
        }
    };

    $rate = function (array $input) use ($controller) {
        return $controller->feedback('task-uuid', new class($input) extends Illuminate\Http\Request {
            public function __construct(array $input)
            {
                parent::__construct($input);
            }

            public function validate(array $rules, ...$params)
            {
                return $this->all();
            }
        });
    };

    $rate(['rating' => -1, 'comment' => 'It sent me to the wrong page']);
    $rated = ['rating' => $task->feedback_rating, 'comment' => $task->feedback_comment, 'at' => $task->feedback_at];

    $rate(['rating' => null]);

    expect($rated['rating'])->toBe(-1)
        ->and($rated['comment'])->toBe('It sent me to the wrong page')
        ->and($rated['at'])->not->toBeNull()
        ->and($task->feedback_rating)->toBeNull()
        ->and($task->feedback_comment)->toBeNull();
});

test('knowledge base status and sync are limited to system administrators and audited', function () {
    $controller = new class extends AiAdminController {
        public array $logs       = [];
        public array $dispatched = [];

        protected function knowledgeStats(): array
        {
            return ['documents' => 310, 'chunks' => 2669, 'by_audience' => ['end_user' => 200], 'by_module' => ['fleet-ops' => 77], 'last_synced' => '2026-09-17T10:00:00+00:00'];
        }

        protected function createAccessLog(array $attributes): AiAdminAccessLog
        {
            $this->logs[] = $attributes;

            return new AiAdminAccessLog();
        }

        protected function dispatchKnowledgeSync(string $source): void
        {
            $this->dispatched[] = $source;
        }
    };

    $bootstrapper = new Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper(
        new Fleetbase\Ai\Services\Knowledge\KnowledgeSearch(new Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier()),
        new Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer(new Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier())
    );

    $status  = aiJsonPayload($controller->knowledge(aiAdminRequestDouble([], true), $bootstrapper));
    $site    = aiJsonPayload($controller->syncKnowledge(aiAdminRequestDouble(['source' => 'anything'], true)));
    $archive = aiJsonPayload($controller->syncKnowledge(aiAdminRequestDouble(['source' => 'snapshot'], true)));

    expect($status['knowledge'])->toMatchArray(['documents' => 310, 'snapshot_available' => true, 'docs_enabled' => true])
        ->and($site)->toBe(['status' => 'queued', 'source' => 'site'])
        ->and($archive['source'])->toBe('snapshot')
        ->and($controller->dispatched)->toBe(['site', 'snapshot'])
        ->and($controller->logs[0]['action'])->toBe('knowledge_sync')
        ->and(fn () => $controller->knowledge(aiAdminRequestDouble([], false), $bootstrapper))->toThrow(RuntimeException::class, 'system administrators')
        ->and(fn () => $controller->syncKnowledge(aiAdminRequestDouble([], false)))->toThrow(RuntimeException::class, 'system administrators');
});

test('knowledge sync job indexes the docs site or the packaged snapshot', function () {
    $indexer = new class(new Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier()) extends Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer {
        public array $calls = [];

        public function index(Fleetbase\Ai\Contracts\KnowledgeSourceInterface $source, ?callable $onProgress = null): array
        {
            $this->calls[] = 'site';

            return ['indexed' => 1, 'unchanged' => 0, 'failed' => 0, 'pruned' => 0];
        }

        public function importSnapshot(string $path): array
        {
            $this->calls[] = 'snapshot:' . basename($path);

            return ['indexed' => 2, 'unchanged' => 0, 'failed' => 0, 'pruned' => 0];
        }
    };
    $bootstrapper = new Fleetbase\Ai\Services\Knowledge\KnowledgeBootstrapper(new Fleetbase\Ai\Services\Knowledge\KnowledgeSearch(new Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier()), $indexer);

    $site     = (new Fleetbase\Ai\Jobs\SyncAiKnowledge())->handle($indexer, new Fleetbase\Ai\Services\Knowledge\DocsSiteSource(), $bootstrapper);
    $snapshot = (new Fleetbase\Ai\Jobs\SyncAiKnowledge('snapshot'))->handle($indexer, new Fleetbase\Ai\Services\Knowledge\DocsSiteSource(), $bootstrapper);

    expect($site['indexed'])->toBe(1)
        ->and($snapshot['indexed'])->toBe(2)
        ->and($indexer->calls)->toBe(['site', 'snapshot:docs-snapshot.json.gz']);
});
