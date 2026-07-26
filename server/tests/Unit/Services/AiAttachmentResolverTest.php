<?php

use Fleetbase\Ai\Services\AiAttachmentResolver;
use Fleetbase\Models\File;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function aiAttachmentFile(array $attributes = [], string|Throwable|null $contents = null): File
{
    return new class($attributes, $contents) extends File {
        public function __construct(array $attributes = [], private string|Throwable|null $contents = null)
        {
            parent::__construct();
            $this->setRawAttributes($attributes, true);
        }

        public function getAttribute($key)
        {
            if ($key === 'url') {
                return $this->attributes[$key] ?? null;
            }

            return parent::getAttribute($key);
        }

        public function getFilesystem(?string $disk = null): Filesystem
        {
            return new class($this->contents) implements Filesystem {
                public function __construct(private string|Throwable|null $contents)
                {
                }

                public function exists($path)
                {
                    return true;
                }

                public function get($path)
                {
                    if ($this->contents instanceof Throwable) {
                        throw $this->contents;
                    }

                    return $this->contents;
                }

                public function readStream($path)
                {
                    return null;
                }

                public function put($path, $contents, $options = [])
                {
                    return true;
                }

                public function writeStream($path, $resource, array $options = [])
                {
                    return true;
                }

                public function getVisibility($path)
                {
                    return 'public';
                }

                public function setVisibility($path, $visibility)
                {
                    return true;
                }

                public function prepend($path, $data)
                {
                    return true;
                }

                public function append($path, $data)
                {
                    return true;
                }

                public function delete($paths)
                {
                    return true;
                }

                public function copy($from, $to)
                {
                    return true;
                }

                public function move($from, $to)
                {
                    return true;
                }

                public function size($path)
                {
                    return 0;
                }

                public function lastModified($path)
                {
                    return 0;
                }

                public function files($directory = null, $recursive = false)
                {
                    return [];
                }

                public function allFiles($directory = null)
                {
                    return [];
                }

                public function directories($directory = null, $recursive = false)
                {
                    return [];
                }

                public function allDirectories($directory = null)
                {
                    return [];
                }

                public function makeDirectory($path)
                {
                    return true;
                }

                public function deleteDirectory($directory)
                {
                    return true;
                }
            };
        }
    };
}

function aiAttachmentQueryBuilder(array $files): Builder
{
    return new class($files) extends Builder {
        public array $calls = [];

        public function __construct(private array $files)
        {
        }

        public function __clone()
        {
        }

        public function where($column, $operator = null, $value = null, $boolean = 'and')
        {
            if (is_callable($column)) {
                $nested = aiAttachmentQueryBuilder([]);
                $column($nested);
                $this->calls[] = ['where_nested', $nested->calls];

                return $this;
            }

            $this->calls[] = ['where', $column, $operator, $value, $boolean];

            return $this;
        }

        public function orWhere($column, $operator = null, $value = null)
        {
            $this->calls[] = ['orWhere', $column, $operator, $value];

            return $this;
        }

        public function get($columns = ['*'])
        {
            $this->calls[] = ['get', $columns];

            return collect($this->files);
        }
    };
}

function aiAttachmentRequest(array $input): Request
{
    $request = Request::create('/ai/tasks', 'POST', $input);
    $request->setUserResolver(fn () => new class {
        public string $uuid = 'user-uuid';
    });

    return $request;
}

test('attachment resolver returns null context for empty attachments and wraps file metadata otherwise', function () {
    $resolver = new AiAttachmentResolver();

    expect($resolver->contextFor([]))->toBeNull();

    $context = $resolver->contextFor([
        ['id' => 'file_1', 'original_filename' => 'manifest.csv'],
    ]);

    expect($context['capability'])->toBe('fleetbase.ai.attachments')
        ->and($context['type'])->toBe('file_attachments')
        ->and($context['data']['files'])->toBe([
            ['id' => 'file_1', 'original_filename' => 'manifest.csv'],
        ]);
});

test('attachment resolver skips empty request attachments before querying files', function () {
    $resolver = new class extends AiAttachmentResolver {
        protected function filesForCurrentCompany(): Builder
        {
            throw new RuntimeException('The file query should not be reached for empty attachments.');
        }
    };

    expect($resolver->resolveFromRequest(aiAttachmentRequest(['attachments' => []])))->toBe([]);
});

test('attachment resolver resolves request attachments through normalized scoped file query', function () {
    session(['company' => 'company-uuid']);

    $file = aiAttachmentFile([
        'id'                => 99,
        'uuid'              => 'file-uuid',
        'public_id'         => 'file_public',
        'original_filename' => 'notes.txt',
        'content_type'      => 'text/plain',
        'path'              => 'notes.txt',
    ], " Route notes\n");
    $query = aiAttachmentQueryBuilder([$file]);

    $resolver = new class($query) extends AiAttachmentResolver {
        public function __construct(public Builder $query)
        {
        }

        protected function filesForCurrentCompany(): Builder
        {
            return $this->query;
        }
    };

    $attachments = $resolver->resolveFromRequest(aiAttachmentRequest([
        'attachments' => [' file-uuid ', '99', 'file-uuid', '', null, ['bad' => true]],
    ]));

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0])->toMatchArray([
            'id'                => 'file_public',
            'uuid'              => 'file-uuid',
            'public_id'         => 'file_public',
            'original_filename' => 'notes.txt',
            'content_type'      => 'text/plain',
            'preview'           => 'Route notes',
        ])
        ->and($query->calls[0])->toBe(['where', 'uploader_uuid', 'user-uuid', null, 'and'])
        ->and($query->calls[1][0])->toBe('where_nested')
        ->and($query->calls[1][1])->toBe([
            ['orWhere', 'uuid', 'file-uuid', null],
            ['orWhere', 'public_id', 'file-uuid', null],
            ['orWhere', 'uuid', '99', null],
            ['orWhere', 'public_id', '99', null],
            ['orWhere', 'id', 99, null],
        ]);
});

test('attachment resolver normalizes previewable files with sanitized bounded previews', function () {
    $resolver = new AiAttachmentResolver();
    $file     = aiAttachmentFile([
        'id'                => 99,
        'uuid'              => 'file-uuid',
        'public_id'         => 'file_public',
        'original_filename' => 'manifest.csv',
        'content_type'      => 'text/csv',
        'file_size'         => 123,
        'type'              => 'upload',
        'url'               => 'https://files.example.test/manifest.csv',
        'path'              => 'uploads/manifest.csv',
    ], " order,city \0\n ORD-1,Singapore \n");

    $normalized = aiInvokeProtected($resolver, 'normalizeFile', $file);

    expect($normalized['id'])->toBe('file_public')
        ->and($normalized['original_filename'])->toBe('manifest.csv')
        ->and($normalized['content_type'])->toBe('text/csv')
        ->and($normalized['preview'])->toBe("order,city \n ORD-1,Singapore");
});

test('attachment resolver previews json and extension based text files', function () {
    $resolver = new AiAttachmentResolver();

    $jsonFile = aiAttachmentFile([
        'original_filename' => 'payload.bin',
        'content_type'      => 'application/json',
        'path'              => 'payload.bin',
    ], '{"ok":true}');
    $markdownFile = aiAttachmentFile([
        'original_filename' => 'notes.md',
        'content_type'      => 'application/octet-stream',
        'path'              => 'notes.md',
    ], '# Notes');

    expect(aiInvokeProtected($resolver, 'previewFor', $jsonFile, 'application/json'))->toBe('{"ok":true}')
        ->and(aiInvokeProtected($resolver, 'previewFor', $markdownFile, 'application/octet-stream'))->toBe('# Notes')
        ->and(aiInvokeProtected($resolver, 'isPreviewable', $markdownFile, 'application/octet-stream'))->toBeTrue();
});

test('attachment resolver skips non-previewable failed empty and oversized previews', function () {
    $resolver       = new AiAttachmentResolver();
    $binaryFile     = aiAttachmentFile([
        'original_filename' => 'photo.png',
        'content_type'      => 'image/png',
        'path'              => 'photo.png',
    ], 'binary');
    $failingFile    = aiAttachmentFile([
        'original_filename' => 'notes.txt',
        'content_type'      => 'text/plain',
        'path'              => 'notes.txt',
    ], new RuntimeException('Missing file.'));
    $emptyFile      = aiAttachmentFile([
        'original_filename' => 'empty.txt',
        'content_type'      => 'text/plain',
        'path'              => 'empty.txt',
    ], '');
    $largeTextFile  = aiAttachmentFile([
        'original_filename' => 'large.log',
        'content_type'      => 'text/plain',
        'path'              => 'large.log',
    ], str_repeat('A', 4100));

    expect(aiInvokeProtected($resolver, 'previewFor', $binaryFile, 'image/png'))->toBeNull()
        ->and(aiInvokeProtected($resolver, 'previewFor', $failingFile, 'text/plain'))->toBeNull()
        ->and(aiInvokeProtected($resolver, 'previewFor', $emptyFile, 'text/plain'))->toBeNull()
        ->and(Str::length(aiInvokeProtected($resolver, 'previewFor', $largeTextFile, 'text/plain')))->toBe(4000);
});
