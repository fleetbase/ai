<?php

use Fleetbase\Ai\Services\AiAttachmentResolver;
use Fleetbase\Models\File;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

if (!function_exists('aiInvokeProtected')) {
    function aiInvokeProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}

function aiAttachmentFile(array $attributes = [], string|Throwable|null $contents = null): File
{
    return new class($attributes, $contents) extends File {
        public function __construct(array $attributes = [], private string|Throwable|null $contents = null)
        {
            parent::__construct($attributes);
        }

        public function getAttribute($key)
        {
            if ($key === 'url') {
                return $this->attributes['url'] ?? null;
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
        ->and($normalized['uuid'])->toBe('file-uuid')
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
