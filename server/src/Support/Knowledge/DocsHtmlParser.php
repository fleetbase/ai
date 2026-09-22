<?php

namespace Fleetbase\Ai\Support\Knowledge;

/**
 * Converts a rendered Fleetbase documentation page into a title, description, and heading-delimited
 * plain-text sections suitable for indexing.
 *
 * The docs site renders the page inside a single `<article>`: an `h1` title, a lead paragraph, then the
 * body with `h2`/`h3` headings carrying anchor ids. Images, icons, and page navigation are dropped;
 * lists and tables are kept as readable text because they hold most field and action references.
 */
class DocsHtmlParser
{
    protected const SKIP_ELEMENTS = ['img', 'svg', 'script', 'style', 'button', 'nav', 'noscript', 'iframe', 'video', 'picture', 'source'];

    /**
     * @return array{title: ?string, description: ?string, sections: array<int, array{heading: ?string, anchor: ?string, level: int, content: string}>}|null
     */
    public function parse(string $html): ?array
    {
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $article = $dom->getElementsByTagName('article')->item(0);
        if (!$article) {
            return null;
        }

        $title       = null;
        $description = null;
        $sections    = [['heading' => null, 'anchor' => null, 'level' => 1, 'lines' => []]];

        foreach ($this->elementChildren($article) as $child) {
            $name = strtolower($child->nodeName);

            if ($title === null && $name === 'h1') {
                $title = $this->inline($child);
                continue;
            }

            if ($title !== null && $description === null && $name === 'p' && empty($sections[0]['lines'])) {
                $description = $this->inline($child);
                continue;
            }

            $this->renderBlock($child, $sections, $title);
        }

        $sections = array_values(array_filter(array_map(fn ($section) => [
            'heading' => $section['heading'],
            'anchor'  => $section['anchor'],
            'level'   => $section['level'],
            'content' => trim(implode("\n", $section['lines'])),
        ], $sections), fn ($section) => $section['content'] !== ''));

        return [
            'title'       => $title,
            'description' => $description,
            'sections'    => $sections,
        ];
    }

    protected function renderBlock(\DOMNode $node, array &$sections, ?string $title, int $depth = 0): void
    {
        if ($node instanceof \DOMText) {
            $text = $this->normalize($node->textContent);
            if ($text !== '') {
                $this->appendLine($sections, $text);
            }

            return;
        }

        if (!$node instanceof \DOMElement) {
            return;
        }

        $name = strtolower($node->nodeName);

        if (in_array($name, static::SKIP_ELEMENTS, true)) {
            return;
        }

        switch ($name) {
            case 'h1':
                // The body repeats the page title as an anchored h1; it opens the introduction section.
                if ($this->inline($node) === $title) {
                    $sections[count($sections) - 1]['anchor'] ??= $node->getAttribute('id') ?: null;

                    return;
                }
                // no break
            case 'h2':
            case 'h3':
                $sections[] = [
                    'heading' => $this->inline($node),
                    'anchor'  => $node->getAttribute('id') ?: null,
                    'level'   => (int) substr($name, 1),
                    'lines'   => [],
                ];

                return;

            case 'h4':
            case 'h5':
            case 'h6':
                $this->appendLine($sections, $this->inline($node) . ':');

                return;

            case 'p':
                $this->appendLine($sections, $this->inline($node));

                return;

            case 'ul':
            case 'ol':
                $this->renderList($node, $sections, $name === 'ol', 0);

                return;

            case 'table':
                $this->renderTable($node, $sections);

                return;

            case 'pre':
                $code = trim($node->textContent);
                if ($code !== '') {
                    $this->appendLine($sections, "```\n" . $code . "\n```");
                }

                return;

            case 'blockquote':
                $this->appendLine($sections, '> ' . $this->inline($node));

                return;
        }

        // Generic containers (div, section, callouts): recurse into their children.
        foreach ($node->childNodes as $child) {
            $this->renderBlock($child, $sections, $title, $depth + 1);
        }
    }

    protected function renderList(\DOMElement $list, array &$sections, bool $ordered, int $indent): void
    {
        $index = 1;

        foreach ($this->elementChildren($list) as $item) {
            if (strtolower($item->nodeName) !== 'li') {
                continue;
            }

            $nested = [];
            $text   = $this->inline($item, $nested);
            $prefix = str_repeat('  ', $indent) . ($ordered ? $index . '. ' : '- ');

            if ($text !== '') {
                $this->appendLine($sections, $prefix . $text);
            }

            foreach ($nested as $nestedList) {
                $this->renderList($nestedList, $sections, strtolower($nestedList->nodeName) === 'ol', $indent + 1);
            }

            $index++;
        }
    }

    protected function renderTable(\DOMElement $table, array &$sections): void
    {
        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($this->elementChildren($row) as $cell) {
                if (in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '/', $this->inline($cell));
                }
            }

            if (!empty(array_filter($cells, fn ($cell) => $cell !== ''))) {
                $this->appendLine($sections, '| ' . implode(' | ', $cells) . ' |');
            }
        }
    }

    /**
     * Flatten an element to inline text. Nested lists are collected separately when a collector is given.
     */
    protected function inline(\DOMNode $node, ?array &$nestedLists = null): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= $child->textContent;
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, static::SKIP_ELEMENTS, true)) {
                continue;
            }

            if (in_array($name, ['ul', 'ol'], true) && $nestedLists !== null) {
                $nestedLists[] = $child;
                continue;
            }

            $inner = $this->inline($child, $nestedLists);

            $text .= match ($name) {
                'code'           => $inner !== '' ? '`' . $inner . '`' : '',
                'strong', 'b'    => $inner !== '' ? '**' . $inner . '**' : '',
                'br'             => ' ',
                'p', 'div', 'li' => ' ' . $inner . ' ',
                default          => $inner,
            };
        }

        return $this->normalize($text);
    }

    protected function appendLine(array &$sections, string $line): void
    {
        // Leading whitespace is kept so nested list items stay indented.
        $line = rtrim($line);
        if (trim($line) !== '') {
            $sections[count($sections) - 1]['lines'][] = $line;
        }
    }

    /**
     * @return \DOMElement[]
     */
    protected function elementChildren(\DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    protected function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
