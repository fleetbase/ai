<?php

namespace Fleetbase\Ai\Support\Knowledge;

use Fleetbase\Ai\Support\AiAudience;
use Illuminate\Support\Str;

/**
 * Decides which audience may see a documentation page or section, and removes system-administrator
 * guidance from content shown to organization users.
 */
class KnowledgeAudienceClassifier
{
    /**
     * Replacement shown to organization users in place of system-level setup instructions.
     */
    public const ADMIN_NOTE = '(This requires configuration by your Fleetbase system administrator.)';

    /**
     * Lines matching this pattern describe system-level configuration: admin panels, platform-level
     * credentials, environment variables, or instance administration.
     */
    protected const ADMIN_LINE_PATTERN = '/\bAdmin\s*(?:→|›|->|>)|\bSystem Settings\b|\binstance administrators?\b|\bsystem[- ]level admin|\bplatform level\b|\benvironment variables?\b|\benv[- ]vars?\b|(?:^|\s|`)\.env\b|\b[A-Z][A-Z0-9]*_[A-Z0-9_]*(?:KEY|SECRET|TOKEN|PASSWORD)\b/u';

    /**
     * Sections whose heading or opening sentence marks them as instance-administrator only.
     */
    protected const ADMIN_SECTION_PATTERN = '/\badmin(?:istrator)?\s+(?:view|panel|only|settings)\b|\bself[- ]hosted\b|only accessible to\s+(?:\*\*)?\s*(?:instance|system)[- ]?(?:level\s+)?admin/iu';

    public function __construct(protected array $systemAdminPaths = [], protected array $developerPaths = [])
    {
    }

    public static function fromConfig(array $config): static
    {
        return new static((array) ($config['system_admin'] ?? []), (array) ($config['developer'] ?? []));
    }

    /**
     * Audience for a documentation path relative to /docs, e.g. `platform/system-setup/services`.
     */
    public function documentAudience(string $path): string
    {
        $path = trim($path, '/');

        if ($this->matchesPrefix($path, $this->systemAdminPaths)) {
            return AiAudience::SYSTEM_ADMIN;
        }

        if ($this->matchesPrefix($path, $this->developerPaths)) {
            return AiAudience::DEVELOPER;
        }

        return AiAudience::END_USER;
    }

    /**
     * Audience for a section of a page. A section can be stricter than its page, never looser.
     */
    public function sectionAudience(string $documentAudience, ?string $heading, string $content): string
    {
        if ($documentAudience !== AiAudience::END_USER) {
            return $documentAudience;
        }

        $opening = Str::limit($content, 300, '');

        if (preg_match(static::ADMIN_SECTION_PATTERN, (string) $heading) || preg_match(static::ADMIN_SECTION_PATTERN, $opening)) {
            return AiAudience::SYSTEM_ADMIN;
        }

        return $documentAudience;
    }

    /**
     * Replace system-administrator guidance in end-user content for anyone who is not a system admin.
     */
    public function redact(string $content, string $audience, AiAudience $viewer): string
    {
        if ($viewer->isSystemAdmin || $audience !== AiAudience::END_USER) {
            return $content;
        }

        $lines    = [];
        $previous = null;

        foreach (explode("\n", $content) as $line) {
            $line = preg_match(static::ADMIN_LINE_PATTERN, $line) ? $this->redactLine($line) : $line;

            if ($line === static::ADMIN_NOTE && $previous === static::ADMIN_NOTE) {
                continue;
            }

            $lines[]  = $line;
            $previous = $line;
        }

        return implode("\n", $lines);
    }

    public function containsAdminGuidance(string $content): bool
    {
        return (bool) preg_match(static::ADMIN_LINE_PATTERN, $content);
    }

    /**
     * In table rows, only the matching cells are replaced so the row still names the option it describes.
     */
    protected function redactLine(string $line): string
    {
        if (!str_starts_with(trim($line), '|')) {
            return static::ADMIN_NOTE;
        }

        $cells = explode('|', trim(trim($line), '|'));
        $cells = array_map(function ($cell) {
            if (!preg_match(static::ADMIN_LINE_PATTERN, $cell)) {
                return trim($cell);
            }

            // Keep the leading sentences of a cell that do not mention system configuration.
            $sentences = preg_split('/(?<=[.!?])\s+/u', trim($cell)) ?: [];
            $kept      = array_filter($sentences, fn ($sentence) => !preg_match(static::ADMIN_LINE_PATTERN, $sentence));

            return trim(implode(' ', $kept) . ' ' . static::ADMIN_NOTE);
        }, $cells);

        return '| ' . implode(' | ', $cells) . ' |';
    }

    protected function matchesPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix, '/');
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }

        return false;
    }
}
