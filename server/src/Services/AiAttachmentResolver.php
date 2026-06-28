<?php

namespace Fleetbase\Ai\Services;

use Fleetbase\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiAttachmentResolver
{
    public function resolveFromRequest(Request $request): array
    {
        $ids = collect($request->input('attachments', []))
            ->filter(fn ($id) => is_string($id) || is_numeric($id))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $userUuid = optional($request->user())->uuid;

        return File::where('company_uuid', session('company'))
            ->where('uploader_uuid', $userUuid)
            ->where(function ($query) use ($ids) {
                foreach ($ids as $id) {
                    $query->orWhere('uuid', $id)->orWhere('public_id', $id);

                    if (is_numeric($id)) {
                        $query->orWhere('id', (int) $id);
                    }
                }
            })
            ->get()
            ->map(fn (File $file) => $this->normalizeFile($file))
            ->values()
            ->all();
    }

    public function contextFor(array $attachments): ?array
    {
        if (empty($attachments)) {
            return null;
        }

        return [
            'capability'  => 'fleetbase.ai.attachments',
            'type'        => 'file_attachments',
            'instruction' => 'The user attached these Fleetbase files to this prompt. Use only the provided metadata and bounded previews. Capability handlers may inspect file IDs for structured parsing.',
            'data'        => [
                'files' => $attachments,
            ],
        ];
    }

    protected function normalizeFile(File $file): array
    {
        $contentType = (string) $file->content_type;

        return array_filter([
            'id'                => $file->public_id ?: $file->id,
            'uuid'              => $file->uuid,
            'public_id'         => $file->public_id,
            'original_filename' => $file->original_filename,
            'content_type'      => $contentType,
            'file_size'         => $file->file_size,
            'type'              => $file->type,
            'url'               => $file->url,
            'preview'           => $this->previewFor($file, $contentType),
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function previewFor(File $file, string $contentType): ?string
    {
        if (!$this->isPreviewable($file, $contentType)) {
            return null;
        }

        try {
            $contents = $file->getFilesystem()->get($file->path);
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        return Str::limit($this->sanitizePreview($contents), 4000, '');
    }

    protected function isPreviewable(File $file, string $contentType): bool
    {
        $filename  = strtolower((string) $file->original_filename);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return Str::startsWith($contentType, 'text/')
            || in_array($contentType, ['application/json', 'application/csv'], true)
            || in_array($extension, ['csv', 'txt', 'json', 'md', 'log'], true);
    }

    protected function sanitizePreview(string $contents): string
    {
        $sanitized = preg_replace('/[^\P{C}\t\r\n]+/u', '', $contents);

        return trim(is_string($sanitized) ? $sanitized : $contents);
    }
}
