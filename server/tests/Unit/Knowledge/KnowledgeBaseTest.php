<?php

use Fleetbase\Ai\Contracts\KnowledgeSourceInterface;
use Fleetbase\Ai\Models\AiKnowledgeChunk;
use Fleetbase\Ai\Models\AiKnowledgeDocument;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Services\Knowledge\DocsSiteSource;
use Fleetbase\Ai\Services\Knowledge\KnowledgeIndexer;
use Fleetbase\Ai\Services\Knowledge\KnowledgeSearch;
use Fleetbase\Ai\Support\AiAudience;
use Fleetbase\Ai\Support\AiToolContext;
use Fleetbase\Ai\Support\Capabilities\ReadDocTool;
use Fleetbase\Ai\Support\Capabilities\SearchDocsTool;
use Fleetbase\Ai\Support\Knowledge\DocsHtmlParser;
use Fleetbase\Ai\Support\Knowledge\KnowledgeAudienceClassifier;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function aiEndUser(array $permissions = []): AiAudience
{
    return new class(false, null, $permissions) extends AiAudience {
        public function __construct(bool $isSystemAdmin, ?string $userUuid, private array $granted)
        {
            parent::__construct($isSystemAdmin, $userUuid);
        }

        protected function checkPermission(string $permission): bool
        {
            return in_array($permission, $this->granted, true);
        }
    };
}

function aiKnowledgeClassifier(): KnowledgeAudienceClassifier
{
    return new KnowledgeAudienceClassifier(
        ['platform/system-setup', 'cli'],
        ['api', 'platform/developer-console', 'fleet-ops/navigator-app']
    );
}

function aiKnowledgeDatabase(): void
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'mysql');
    $capsule->getDatabaseManager()->setDefaultConnection('mysql');
    EloquentModel::setConnectionResolver($capsule->getDatabaseManager());
    EloquentModel::clearBootedModels();

    $schema = $capsule->getConnection('mysql')->getSchemaBuilder();
    $schema->create('ai_knowledge_documents', function ($table) {
        $table->increments('id');
        $table->uuid('uuid')->nullable()->unique();
        $table->string('source');
        $table->string('url', 500)->unique();
        $table->string('path')->nullable();
        $table->string('title')->nullable();
        $table->text('description')->nullable();
        $table->string('module')->nullable();
        $table->string('section')->nullable();
        $table->string('audience')->default('end_user');
        $table->string('content_hash', 64)->nullable();
        $table->timestamp('fetched_at')->nullable();
        $table->timestamps();
    });
    $schema->create('ai_knowledge_chunks', function ($table) {
        $table->increments('id');
        $table->uuid('uuid')->nullable()->unique();
        $table->uuid('ai_knowledge_document_uuid');
        $table->string('heading')->nullable();
        $table->string('heading_path', 500)->nullable();
        $table->string('anchor')->nullable();
        $table->string('audience')->default('end_user');
        $table->unsignedInteger('position')->default(0);
        $table->text('content');
        $table->timestamps();
    });
}

/**
 * Indexer that stores through Eloquent on the in-memory database, exercising the real persistence path.
 */
function aiKnowledgeIndexer(): KnowledgeIndexer
{
    return new class(aiKnowledgeClassifier()) extends KnowledgeIndexer {
        protected function findDocument(string $url): ?AiKnowledgeDocument
        {
            return AiKnowledgeDocument::where('url', $url)->first();
        }

        protected function persist(AiKnowledgeDocument $document, array $chunks): void
        {
            $document->save();
            AiKnowledgeChunk::where('ai_knowledge_document_uuid', $document->uuid)->delete();
            foreach ($chunks as $chunk) {
                AiKnowledgeChunk::create(array_merge(['uuid' => (string) Illuminate\Support\Str::uuid()], $chunk, ['ai_knowledge_document_uuid' => $document->uuid]));
            }
        }

        protected function prune(string $sourceKey, array $keepUrls): int
        {
            $stale = AiKnowledgeDocument::where('source', $sourceKey)->whereNotIn('url', $keepUrls)->pluck('uuid');
            AiKnowledgeChunk::whereIn('ai_knowledge_document_uuid', $stale)->delete();

            return AiKnowledgeDocument::whereIn('uuid', $stale)->delete();
        }
    };
}

function aiKnowledgeSearch(): KnowledgeSearch
{
    return new class(aiKnowledgeClassifier()) extends KnowledgeSearch {
        protected function candidates(string $query, array $terms, array $allowedAudiences, ?string $module): Illuminate\Support\Collection
        {
            return AiKnowledgeChunk::with('document')
                ->whereIn('audience', $allowedAudiences)
                ->whereHas('document', function ($document) use ($allowedAudiences, $module) {
                    $document->whereIn('audience', $allowedAudiences);
                    if ($module) {
                        $document->where('module', $module);
                    }
                })
                ->where(function ($where) use ($terms) {
                    foreach ($terms as $term) {
                        $where->orWhere('content', 'like', '%' . $term . '%')->orWhere('heading_path', 'like', '%' . $term . '%');
                    }
                })
                ->get();
        }

        protected function chunkByUuid(string $uuid, array $allowed): ?AiKnowledgeChunk
        {
            return AiKnowledgeChunk::with('document')->where('uuid', $uuid)->whereIn('audience', $allowed)
                ->whereHas('document', fn ($document) => $document->whereIn('audience', $allowed))->first();
        }

        protected function documentByUrl(string $url, array $allowed): ?AiKnowledgeDocument
        {
            return AiKnowledgeDocument::whereIn('url', [$url, $url . '/'])->whereIn('audience', $allowed)->first();
        }

        protected function allowedChunks(AiKnowledgeDocument $document, array $allowed): Illuminate\Support\Collection
        {
            return $document->chunks()->whereIn('audience', $allowed)->get()->each(fn ($chunk) => $chunk->setRelation('document', $document));
        }
    };
}

function aiKnowledgeDocuments(): array
{
    return [
        [
            'url'         => 'https://fleetbase.io/docs/platform/identity-and-access/users',
            'path'        => 'platform/identity-and-access/users',
            'title'       => 'Users',
            'description' => 'Invite, create, and manage users.',
            'module'      => 'platform',
            'section'     => 'Platform › Identity & Access',
            'sections'    => [
                ['heading' => null, 'anchor' => 'users', 'level' => 1, 'content' => 'Navigate to **IAM → Users** to manage them.'],
                ['heading' => 'Creating a User Directly', 'anchor' => 'creating-a-user-directly', 'level' => 2, 'content' => "Click **New** to create a user account without sending an invite email.\n| **Email** | Login email address |"],
            ],
        ],
        [
            'url'         => 'https://fleetbase.io/docs/fleet-ops/settings/map',
            'path'        => 'fleet-ops/settings/map',
            'title'       => 'Map Settings',
            'description' => 'Choose the map tile provider.',
            'module'      => 'fleet-ops',
            'section'     => 'Fleet-Ops › Settings',
            'sections'    => [
                ['heading' => 'Map Provider', 'anchor' => 'map-provider', 'level' => 2, 'content' => "Navigate to **Fleet-Ops → Settings → Map**.\n| Provider | Value | Notes |\n| **Leaflet** | `leaflet` | Default. No API key required for OSM tiles. |\n| **Google Maps** | `google` | Switches to Google's tile server. Requires a Google Maps API key configured at the platform level. |"],
            ],
        ],
        [
            'url'         => 'https://fleetbase.io/docs/platform/system-setup/services',
            'path'        => 'platform/system-setup/services',
            'title'       => 'Services',
            'description' => 'Configure third-party service integrations.',
            'module'      => 'platform',
            'section'     => 'Platform › System Setup',
            'sections'    => [
                ['heading' => 'Google Maps', 'anchor' => 'google-maps', 'level' => 2, 'content' => 'Navigate to Admin → System Settings → Services and enter the Google Maps API Key for map tiles.'],
            ],
        ],
        [
            'url'         => 'https://fleetbase.io/docs/platform/identity-and-access/organizations',
            'path'        => 'platform/identity-and-access/organizations',
            'title'       => 'Organizations',
            'description' => 'Organization views.',
            'module'      => 'platform',
            'section'     => 'Platform › Identity & Access',
            'sections'    => [
                ['heading' => 'Admin View — All Organizations', 'anchor' => 'admin-view', 'level' => 2, 'content' => 'This section is only accessible to instance administrators. See every organization map.'],
                ['heading' => 'Switching Organizations', 'anchor' => 'switching', 'level' => 2, 'content' => 'Click **Switch** next to any organization to make it active.'],
            ],
        ],
    ];
}

function aiKnowledgeSource(array $documents, array $failures = []): KnowledgeSourceInterface
{
    return new class($documents, $failures) implements KnowledgeSourceInterface {
        public function __construct(private array $documents, private array $failures)
        {
        }

        public function key(): string
        {
            return 'fleetbase-docs';
        }

        public function documents(?callable $onProgress = null): iterable
        {
            foreach ($this->failures as $url) {
                $onProgress && $onProgress($url, 'failed');
            }

            foreach ($this->documents as $document) {
                $onProgress && $onProgress($document['url'], 'fetched');
                yield $document;
            }
        }
    };
}

test('docs parser extracts title, description, and heading sections with lists, tables, and code', function () {
    $html = <<<'HTML'
<html><body><nav>Sidebar</nav><article>
<h1 class="title">Users</h1><p>Invite &amp; manage users.</p>
<div>
  <h1 id="users"><a href="#users">Users</a><svg><path/></svg></h1>
  <p>Navigate to <strong>IAM → Users</strong>. <img alt="screenshot" src="x.png"/></p>
  <h2 id="inviting"><a href="#inviting">Inviting a User</a></h2>
  <ul><li>Click <code>Invite</code><ul><li>Enter email</li></ul></li><li>Send</li></ul>
  <ol><li>First</li><li>Second</li></ol>
  <h3 id="fields">Fields</h3>
  <div><table><thead><tr><th>Field</th><th>Description</th></tr></thead><tbody><tr><td><strong>Email</strong></td><td>Required | unique</td></tr><tr><td></td><td></td></tr></tbody></table></div>
  <h4>Example</h4>
  <pre><code>flb install fleetbase/ai</code></pre>
  <blockquote>Use roles.</blockquote>
  <div><div role="none"></div><div>You cannot delete yourself.</div></div>
  <script>ignored()</script>
</div>
</article><footer>Next page</footer></body></html>
HTML;

    $result = (new DocsHtmlParser())->parse($html);

    expect($result['title'])->toBe('Users')
        ->and($result['description'])->toBe('Invite & manage users.')
        ->and($result['sections'])->toHaveCount(3)
        ->and($result['sections'][0])->toBe(['heading' => null, 'anchor' => 'users', 'level' => 1, 'content' => 'Navigate to **IAM → Users**.'])
        ->and($result['sections'][1]['heading'])->toBe('Inviting a User')
        ->and($result['sections'][1]['anchor'])->toBe('inviting')
        ->and($result['sections'][1]['content'])->toBe("- Click `Invite`\n  - Enter email\n- Send\n1. First\n2. Second")
        ->and($result['sections'][2]['level'])->toBe(3)
        ->and($result['sections'][2]['content'])->toBe("| Field | Description |\n| **Email** | Required / unique |\nExample:\n```\nflb install fleetbase/ai\n```\n> Use roles.\nYou cannot delete yourself.")
        ->and(json_encode($result))->not->toContain('Sidebar')
        ->and(json_encode($result))->not->toContain('Next page')
        ->and(json_encode($result))->not->toContain('ignored');
});

test('docs parser returns null for pages without an article', function () {
    expect((new DocsHtmlParser())->parse('<html><body><p>Not found</p></body></html>'))->toBeNull();
});

test('docs parser ignores comments and list markup that is not a list item', function () {
    $html = <<<'HTML'
<html><body><article>
<h1>Map</h1><p>Pick a provider.</p>
<div>
  <!-- build note -->
  <h2 id="providers">Providers</h2>
  <ul><div>Not an item</div><li>Leaflet <!-- default --></li><li>Google</li></ul>
</div>
</article></body></html>
HTML;

    $result = (new DocsHtmlParser())->parse($html);

    expect($result['title'])->toBe('Map')
        ->and($result['sections'])->toHaveCount(1)
        ->and($result['sections'][0])->toMatchArray(['heading' => 'Providers', 'content' => "- Leaflet\n- Google"])
        ->and(json_encode($result))->not->toContain('build note')
        ->and(json_encode($result))->not->toContain('Not an item');
});

test('audience classifier tags pages by path and never loosens a section', function () {
    $classifier = aiKnowledgeClassifier();

    expect($classifier->documentAudience('platform/system-setup/services'))->toBe(AiAudience::SYSTEM_ADMIN)
        ->and($classifier->documentAudience('/cli/'))->toBe(AiAudience::SYSTEM_ADMIN)
        ->and($classifier->documentAudience('api/fleetbase/orders'))->toBe(AiAudience::DEVELOPER)
        ->and($classifier->documentAudience('fleet-ops/navigator-app/configuration'))->toBe(AiAudience::DEVELOPER)
        ->and($classifier->documentAudience('platform/system-setup-guide'))->toBe(AiAudience::END_USER)
        ->and($classifier->documentAudience('fleet-ops/settings/map'))->toBe(AiAudience::END_USER)
        ->and($classifier->sectionAudience(AiAudience::DEVELOPER, 'Admin View', 'anything'))->toBe(AiAudience::DEVELOPER)
        ->and($classifier->sectionAudience(AiAudience::END_USER, 'Admin View — All Organizations', 'List'))->toBe(AiAudience::SYSTEM_ADMIN)
        ->and($classifier->sectionAudience(AiAudience::END_USER, 'Overview', 'This section is only accessible to **instance administrators**.'))->toBe(AiAudience::SYSTEM_ADMIN)
        ->and($classifier->sectionAudience(AiAudience::END_USER, 'Via the CLI (Self-Hosted)', 'Run flb install.'))->toBe(AiAudience::SYSTEM_ADMIN)
        ->and($classifier->sectionAudience(AiAudience::END_USER, 'Switching Organizations', 'Click Switch.'))->toBe(AiAudience::END_USER)
        ->and(KnowledgeAudienceClassifier::fromConfig(['system_admin' => ['cli']])->documentAudience('cli/install'))->toBe(AiAudience::SYSTEM_ADMIN);
});

test('audience classifier redacts system administrator guidance for organization users only', function () {
    $classifier = aiKnowledgeClassifier();
    $content    = "Navigate to **Fleet-Ops → Settings → Map**.\n| **Google Maps** | `google` | Switches to Google's tile server. Requires a Google Maps API key configured at the platform level. |\nSet GOOGLE_MAPS_API_KEY in your .env file.\nOr open Admin → Config → Services.\nClick Save.";

    $redacted = $classifier->redact($content, AiAudience::END_USER, aiEndUser());

    expect($redacted)->toBe("Navigate to **Fleet-Ops → Settings → Map**.\n| **Google Maps** | `google` | Switches to Google's tile server. " . KnowledgeAudienceClassifier::ADMIN_NOTE . " |\n" . KnowledgeAudienceClassifier::ADMIN_NOTE . "\nClick Save.")
        ->and($classifier->redact($content, AiAudience::END_USER, new AiAudience(true)))->toBe($content)
        ->and($classifier->redact('FLEETBASE_KEY is set in .env', AiAudience::DEVELOPER, aiEndUser()))->toBe('FLEETBASE_KEY is set in .env')
        ->and($classifier->containsAdminGuidance('Admin → Services'))->toBeTrue()
        ->and($classifier->containsAdminGuidance('Fleet-Ops → Settings'))->toBeFalse();
});

test('indexer stores audience tagged chunks, skips unchanged pages, and splits long sections', function () {
    aiKnowledgeDatabase();
    $indexer   = aiKnowledgeIndexer();
    $documents = aiKnowledgeDocuments();

    $stats = $indexer->index(aiKnowledgeSource($documents));

    $organizations = AiKnowledgeDocument::where('path', 'platform/identity-and-access/organizations')->first();

    expect($stats)->toBe(['indexed' => 4, 'unchanged' => 0, 'failed' => 0, 'pruned' => 0])
        ->and(AiKnowledgeDocument::where('path', 'platform/system-setup/services')->value('audience'))->toBe(AiAudience::SYSTEM_ADMIN)
        ->and($organizations->audience)->toBe(AiAudience::END_USER)
        ->and($organizations->chunks->pluck('audience')->all())->toBe([AiAudience::SYSTEM_ADMIN, AiAudience::END_USER])
        ->and(AiKnowledgeChunk::where('anchor', 'creating-a-user-directly')->value('heading_path'))->toBe('Users › Creating a User Directly')
        ->and($indexer->index(aiKnowledgeSource($documents)))->toBe(['indexed' => 0, 'unchanged' => 4, 'failed' => 0, 'pruned' => 0]);

    $long   = ['title' => 'Long', 'sections' => [
        ['heading' => 'Parent', 'anchor' => 'parent', 'level' => 2, 'content' => 'Intro'],
        ['heading' => 'Child', 'anchor' => 'child', 'level' => 3, 'content' => implode("\n", array_fill(0, 80, str_repeat('word ', 20)))],
    ]];
    $chunks = $indexer->chunksFor($long, AiAudience::END_USER);

    expect(count($chunks))->toBeGreaterThan(2)
        ->and($chunks[1]['heading_path'])->toBe('Long › Parent › Child (part 1)')
        ->and(collect($chunks)->every(fn ($chunk) => mb_strlen($chunk['content']) <= KnowledgeIndexer::MAX_CHUNK_CHARACTERS))->toBeTrue()
        ->and(collect($chunks)->pluck('position')->all())->toBe(range(0, count($chunks) - 1));
});

test('indexer prunes removed pages only when the crawl mostly succeeded', function () {
    aiKnowledgeDatabase();
    $indexer   = aiKnowledgeIndexer();
    $documents = aiKnowledgeDocuments();
    $indexer->index(aiKnowledgeSource($documents));

    $outage = $indexer->index(aiKnowledgeSource([$documents[0]], ['https://fleetbase.io/docs/a', 'https://fleetbase.io/docs/b']));

    expect($outage['pruned'])->toBe(0)
        ->and(AiKnowledgeDocument::count())->toBe(4);

    $healthy = $indexer->index(aiKnowledgeSource([$documents[0], $documents[1]]));

    expect($healthy['pruned'])->toBe(2)
        ->and(AiKnowledgeDocument::count())->toBe(2)
        ->and(AiKnowledgeChunk::count())->toBe(3);
});

test('snapshots round trip through gzip and reject invalid files', function () {
    aiKnowledgeDatabase();
    $indexer   = aiKnowledgeIndexer();
    $directory = sys_get_temp_dir() . '/fleetbase-ai-snapshot-' . uniqid();
    $path      = $directory . '/docs.json.gz';

    $written  = $indexer->writeSnapshot(aiKnowledgeSource(aiKnowledgeDocuments(), ['https://fleetbase.io/docs/broken']), $path);
    $snapshot = json_decode(gzdecode(file_get_contents($path)), true);

    file_put_contents($directory . '/partial.json', json_encode(['documents' => [['url' => 'https://fleetbase.io/docs/empty', 'sections' => []], aiKnowledgeDocuments()[0]]]));
    file_put_contents($directory . '/invalid.json', '{"nope": true}');

    expect($written)->toBe(['written' => 4, 'failed' => 1])
        ->and($snapshot['source'])->toBe('fleetbase-docs')
        ->and($snapshot['documents'])->toHaveCount(4)
        ->and($indexer->importSnapshot($path))->toBe(['indexed' => 4, 'unchanged' => 0, 'failed' => 0, 'pruned' => 0])
        ->and($indexer->importSnapshot($directory . '/partial.json'))->toBe(['indexed' => 0, 'unchanged' => 1, 'failed' => 1, 'pruned' => 3])
        ->and(fn () => $indexer->importSnapshot($directory . '/invalid.json'))->toThrow(InvalidArgumentException::class, 'not valid')
        ->and(fn () => $indexer->importSnapshot($directory . '/missing.json'))->toThrow(InvalidArgumentException::class, 'not found');
});

test('search ranks by title and heading, filters system admin content, and redacts for organization users', function () {
    aiKnowledgeDatabase();
    aiKnowledgeIndexer()->index(aiKnowledgeSource(aiKnowledgeDocuments()));
    $search = aiKnowledgeSearch();

    $endUser = $search->search('google maps api key', aiEndUser());
    $admin   = $search->search('google maps api key', new AiAudience(true));

    expect(collect($endUser['results'])->pluck('title')->all())->toBe(['Map Settings'])
        ->and($endUser['results'][0]['url'])->toBe('https://fleetbase.io/docs/fleet-ops/settings/map#map-provider')
        ->and($endUser['results'][0]['content'])->toContain(KnowledgeAudienceClassifier::ADMIN_NOTE)
        ->and($endUser['results'][0]['content'])->not->toContain('platform level')
        ->and(json_encode($endUser))->not->toContain('Admin →')
        ->and(collect($admin['results'])->pluck('title')->all())->toContain('Services', 'Map Settings')
        ->and($admin['results'][0]['title'])->toBe('Services')
        ->and(json_encode($admin))->toContain('platform level')
        ->and($search->search('organization', aiEndUser())['results'])->toHaveCount(1)
        ->and($search->search('organization', new AiAudience(true))['results'])->toHaveCount(2)
        ->and($search->search('users', aiEndUser(), 'fleet-ops')['results'])->toBe([])
        ->and($search->allowedAudiences(aiEndUser()))->toBe([AiAudience::END_USER])
        ->and($search->allowedAudiences(aiEndUser(['developers see api-key'])))->toBe([AiAudience::END_USER, AiAudience::DEVELOPER])
        ->and($search->allowedAudiences(new AiAudience(true)))->toBe([AiAudience::END_USER, AiAudience::DEVELOPER, AiAudience::SYSTEM_ADMIN])
        ->and($search->search('the and', aiEndUser()))->toBe(['query' => 'the and', 'results' => []])
        ->and($search->terms('How do I add a User? add-user'))->toBe(['add', 'user', 'add-user']);
});

test('search limits results per page and overall', function () {
    aiKnowledgeDatabase();
    $sections = array_map(fn ($i) => ['heading' => "Driver topic {$i}", 'anchor' => "t{$i}", 'level' => 2, 'content' => "driver details {$i}"], range(1, 5));
    aiKnowledgeIndexer()->index(aiKnowledgeSource([
        ['url' => 'https://fleetbase.io/docs/fleet-ops/resources/drivers', 'path' => 'fleet-ops/resources/drivers', 'title' => 'Drivers', 'module' => 'fleet-ops', 'sections' => $sections],
        ['url' => 'https://fleetbase.io/docs/fleet-ops/resources/vehicles', 'path' => 'fleet-ops/resources/vehicles', 'title' => 'Vehicles', 'module' => 'fleet-ops', 'sections' => [['heading' => 'Assign driver', 'anchor' => 'assign', 'level' => 2, 'content' => 'Assign a driver to a vehicle.']]],
    ]));

    $results = aiKnowledgeSearch()->search('driver', aiEndUser(), null, 50)['results'];

    expect($results)->toHaveCount(3)
        ->and(collect($results)->where('title', 'Drivers'))->toHaveCount(KnowledgeSearch::MAX_CHUNKS_PER_DOCUMENT)
        ->and(aiKnowledgeSearch()->search('driver', aiEndUser(), null, 1)['results'])->toHaveCount(1);
});

test('read returns sections by id or anchor and whole pages by url, respecting audience', function () {
    aiKnowledgeDatabase();
    aiKnowledgeIndexer()->index(aiKnowledgeSource(aiKnowledgeDocuments()));
    $search    = aiKnowledgeSearch();
    $adminOnly = AiKnowledgeChunk::where('anchor', 'admin-view')->value('uuid');
    $switching = AiKnowledgeChunk::where('anchor', 'switching')->value('uuid');

    $page = $search->read('https://fleetbase.io/docs/platform/identity-and-access/users/', aiEndUser());

    expect($page['title'])->toBe('Users')
        ->and($page['content'])->toBe("Navigate to **IAM → Users** to manage them.\n\n## Creating a User Directly\nClick **New** to create a user account without sending an invite email.\n| **Email** | Login email address |")
        ->and($page['truncated'])->toBeFalse()
        ->and($search->read('https://fleetbase.io/docs/platform/identity-and-access/users#creating-a-user-directly', aiEndUser()))
        ->toMatchArray(['title' => 'Users', 'section' => 'Users › Creating a User Directly', 'page_sections' => ['Users', 'Creating a User Directly']])
        ->and($search->read($switching, aiEndUser())['page_sections'])->toBe(['Switching Organizations'])
        ->and($search->read($adminOnly, aiEndUser()))->toBeNull()
        ->and($search->read($adminOnly, new AiAudience(true))['page_sections'])->toBe(['Admin View — All Organizations', 'Switching Organizations'])
        ->and($search->read('https://fleetbase.io/docs/platform/identity-and-access/users#missing', aiEndUser()))->toBeNull()
        ->and($search->read('https://fleetbase.io/docs/platform/system-setup/services', aiEndUser()))->toBeNull()
        ->and($search->read('https://fleetbase.io/docs/platform/system-setup/services', new AiAudience(true))['title'])->toBe('Services');
});

test('docs site source discovers docs pages from the sitemap and parses them', function () {
    Http::swap(new HttpFactory());
    Http::fake([
        'https://docs.test/sitemap.xml'                             => Http::response('<urlset><url><loc>https://docs.test/docs</loc></url><url><loc>https://docs.test/pricing</loc></url><url><loc>https://docs.test/docs/platform/identity-and-access/users</loc></url><url><loc>https://docs.test/docs/ui/buttons</loc></url><url><loc>https://docs.test/docs/fleet-ops/settings/map</loc></url><url><loc>https://docs.test/docs/api/fleetbase/orders</loc></url><url><loc>https://docs.test/docs/ledger/overview</loc></url></urlset>'),
        'https://docs.test/docs/platform/identity-and-access/users' => Http::response('<article><h1>Users</h1><p>Manage users.</p><div><h2 id="invite">Invite</h2><p>Click Invite User.</p></div></article>'),
        'https://docs.test/docs/fleet-ops/settings/map'             => Http::response('Server error', 500),
        'https://docs.test/docs/api/fleetbase/orders'               => Http::response('<article><h1>Orders API</h1><div><p>Create orders.</p></div></article>'),
        'https://docs.test/docs'                                    => Http::response('<html><body>No article</body></html>'),
        'https://docs.test/docs/ledger/overview'                    => fn () => throw new Illuminate\Http\Client\ConnectionException('Connection timed out'),
    ]);

    $source   = new DocsSiteSource(['sitemap_url' => 'https://docs.test/sitemap.xml', 'exclude' => ['ui'], 'request_delay_ms' => 0]);
    $progress = [];

    $documents = iterator_to_array($source->documents(function ($url, $status) use (&$progress) {
        $progress[] = [$url, $status];
    }), false);

    expect($source->pageUrls())->toBe([
        'https://docs.test/docs',
        'https://docs.test/docs/platform/identity-and-access/users',
        'https://docs.test/docs/fleet-ops/settings/map',
        'https://docs.test/docs/api/fleetbase/orders',
        'https://docs.test/docs/ledger/overview',
    ])
        ->and($documents)->toHaveCount(2)
        ->and($documents[0])->toMatchArray([
            'url'         => 'https://docs.test/docs/platform/identity-and-access/users',
            'path'        => 'platform/identity-and-access/users',
            'title'       => 'Users',
            'description' => 'Manage users.',
            'module'      => 'platform',
            'section'     => 'Platform › Identity & Access',
        ])
        ->and($documents[1]['section'])->toBe('API › Fleetbase')
        ->and($source->failures)->toBe(['https://docs.test/docs', 'https://docs.test/docs/fleet-ops/settings/map', 'https://docs.test/docs/ledger/overview'])
        ->and($progress)->toContain(['https://docs.test/docs/fleet-ops/settings/map', 'failed'])
        ->and($source->key())->toBe('fleetbase-docs')
        ->and($source->moduleFor(''))->toBe('overview')
        ->and($source->sectionFor('fleet-ops'))->toBeNull()
        ->and($source->sectionFor('cli/account/login'))->toBe('CLI › Account');
});

test('docs site source fails clearly when the sitemap is unavailable', function () {
    Http::swap(new HttpFactory());
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => (new DocsSiteSource(['sitemap_url' => 'https://docs.test/sitemap.xml']))->pageUrls())->toThrow(RuntimeException::class, 'sitemap');
});

test('docs tools search and read for the current audience with validation', function () {
    aiKnowledgeDatabase();
    aiKnowledgeIndexer()->index(aiKnowledgeSource(aiKnowledgeDocuments()));
    $search  = aiKnowledgeSearch();
    $task    = new AiTask(['prompt' => 'where do I add a user']);
    $context = new AiToolContext($task, aiEndUser());
    $tool    = new SearchDocsTool($search);
    $read    = new ReadDocTool($search);

    $found = $tool->invoke($task, ['query' => 'create user'], $context);

    expect($tool->toolName())->toBe('search_docs')
        ->and($tool->key())->toBe('core.search_docs')
        ->and($tool->module())->toBe('core')
        ->and($tool->mode())->toBe('tool')
        ->and($tool->label())->toContain('documentation')
        ->and($tool->description())->toContain('documentation')
        ->and($tool->toolDescription())->toContain('in English')
        ->and($tool->toolParameters()['required'])->toBe(['query'])
        ->and($tool->availableFor($context))->toBeTrue()
        ->and($tool->toArray())->toHaveKeys(['tool_name', 'tool_parameters'])
        ->and($found['results'][0]['title'])->toBe('Users')
        ->and($tool->invoke($task, ['query' => '  '], $context))->toBe(['error' => 'A search query is required.'])
        ->and($tool->invoke($task, ['query' => 'warehouse robots'], $context)['message'])->toContain('No documentation matched')
        ->and($read->toolName())->toBe('read_doc')
        ->and($read->key())->toBe('core.read_doc')
        ->and($read->module())->toBe('core')
        ->and($read->label())->toContain('documentation')
        ->and($read->description())->toContain('documentation')
        ->and($read->toolDescription())->toContain('search_docs')
        ->and($read->toolParameters()['required'])->toBe(['reference'])
        ->and($read->invoke($task, ['reference' => $found['results'][0]['url']], $context)['title'])->toBe('Users')
        ->and($read->invoke($task, ['reference' => ''], $context))->toBe(['error' => 'A documentation reference is required.'])
        ->and($read->invoke($task, ['reference' => 'https://fleetbase.io/docs/platform/system-setup/services'], $context)['error'])->toContain('not available');
});

test('knowledge models generate a uuid when one is not supplied', function () {
    aiKnowledgeDatabase();
    EloquentModel::setEventDispatcher(new Illuminate\Events\Dispatcher());
    EloquentModel::clearBootedModels();

    $document = AiKnowledgeDocument::create(['source' => 'fleetbase-docs', 'url' => 'https://fleetbase.io/docs/platform', 'title' => 'Platform']);
    $chunk    = AiKnowledgeChunk::create(['ai_knowledge_document_uuid' => $document->uuid, 'heading' => 'Platform', 'content' => 'Body']);
    $given    = AiKnowledgeChunk::create(['uuid' => '6f9619ff-8b86-d011-b42d-00cf4fc964ff', 'ai_knowledge_document_uuid' => $document->uuid, 'content' => 'Body']);

    expect(Illuminate\Support\Str::isUuid((string) $document->uuid))->toBeTrue()
        ->and(Illuminate\Support\Str::isUuid((string) $chunk->uuid))->toBeTrue()
        ->and($given->uuid)->toBe('6f9619ff-8b86-d011-b42d-00cf4fc964ff')
        ->and($document->chunks()->count())->toBe(2);

    EloquentModel::unsetEventDispatcher();
    EloquentModel::clearBootedModels();
});
