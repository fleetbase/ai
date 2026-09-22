import { htmlSafe } from '@ember/template';

const INLINE_PLACEHOLDERS = [];
// Private-use sentinels, so inline code survives the emphasis and link passes untouched.
const PLACEHOLDER_OPEN = '\uE000';
const PLACEHOLDER_CLOSE = '\uE001';
const placeholderMatcher = new RegExp(`${PLACEHOLDER_OPEN}(\\d+)${PLACEHOLDER_CLOSE}`, 'g');

export default function formatAiResponse(response) {
    const markdown = typeof response === 'string' ? response.trim() : '';

    if (!markdown) {
        return htmlSafe('');
    }

    INLINE_PLACEHOLDERS.length = 0;

    try {
        return htmlSafe(renderBlocks(markdown));
    } catch {
        return htmlSafe(`<p>${escapeHtml(markdown)}</p>`);
    }
}

function renderBlocks(markdown) {
    const lines = markdown.replace(/\r\n/g, '\n').split('\n');
    const blocks = [];
    let index = 0;

    while (index < lines.length) {
        if (isBlank(lines[index])) {
            index++;
            continue;
        }

        if (isTableStart(lines, index)) {
            const table = collectTable(lines, index);
            blocks.push(renderTable(table.rows, table.alignments));
            index = table.nextIndex;
            continue;
        }

        if (isUnorderedListItem(lines[index])) {
            const list = collectList(lines, index, 'ul');
            blocks.push(renderList(list.items, 'ul'));
            index = list.nextIndex;
            continue;
        }

        if (isOrderedListItem(lines[index])) {
            const list = collectList(lines, index, 'ol');
            blocks.push(renderList(list.items, 'ol'));
            index = list.nextIndex;
            continue;
        }

        const paragraph = collectParagraph(lines, index);
        blocks.push(`<p>${renderInline(paragraph.text)}</p>`);
        index = paragraph.nextIndex;
    }

    return blocks.join('');
}

function collectParagraph(lines, startIndex) {
    const parts = [];
    let index = startIndex;

    while (index < lines.length && !isBlank(lines[index]) && !isTableStart(lines, index) && !isUnorderedListItem(lines[index]) && !isOrderedListItem(lines[index])) {
        parts.push(lines[index].trim());
        index++;
    }

    return {
        text: parts.join('\n'),
        nextIndex: index,
    };
}

function collectList(lines, startIndex, type) {
    const items = [];
    let index = startIndex;
    const matcher = type === 'ol' ? orderedListMatcher : unorderedListMatcher;

    while (index < lines.length) {
        const match = lines[index].match(matcher);
        if (!match) {
            break;
        }

        items.push(match[1].trim());
        index++;
    }

    return { items, nextIndex: index };
}

function renderList(items, type) {
    const body = items.map((item) => `<li>${renderInline(item)}</li>`).join('');

    return `<${type}>${body}</${type}>`;
}

function isTableStart(lines, index) {
    return isPipeRow(lines[index]) && isTableSeparator(lines[index + 1]);
}

function collectTable(lines, startIndex) {
    const header = parseTableRow(lines[startIndex]);
    const alignments = parseTableAlignments(lines[startIndex + 1]);
    const rows = [header];
    let index = startIndex + 2;

    while (index < lines.length && isPipeRow(lines[index])) {
        rows.push(parseTableRow(lines[index]));
        index++;
    }

    return {
        rows,
        alignments,
        nextIndex: index,
    };
}

function renderTable(rows, alignments) {
    const [header, ...bodyRows] = rows;
    const headerHtml = header.map((cell, index) => `<th${alignmentAttribute(alignments[index])}>${renderInline(cell)}</th>`).join('');
    const bodyHtml = bodyRows.map((row) => `<tr>${row.map((cell, index) => `<td${alignmentAttribute(alignments[index])}>${renderInline(cell)}</td>`).join('')}</tr>`).join('');

    return `<div class="fleetbase-ai-table-wrapper"><table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table></div>`;
}

function parseTableRow(line) {
    return trimOuterPipes(line)
        .split('|')
        .map((cell) => cell.trim());
}

function parseTableAlignments(line) {
    return parseTableRow(line).map((cell) => {
        const value = cell.trim();
        if (/^:-+:$/.test(value)) {
            return 'center';
        }
        if (/^-+:$/.test(value)) {
            return 'right';
        }
        if (/^:-+$/.test(value)) {
            return 'left';
        }

        return 'left';
    });
}

function alignmentAttribute(alignment) {
    return alignment ? ` class="fleetbase-ai-table-align-${alignment}"` : '';
}

function renderInline(text) {
    let html = escapeHtml(text);

    html = stashInlineCode(html);
    html = stashLinks(html);
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    html = html.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    html = html.replace(/(^|[^_])_([^_\n]+)_/g, '$1<em>$2</em>');
    html = html.replace(/\n/g, '<br>');
    html = restorePlaceholders(html);

    return html;
}

/**
 * Park raw HTML behind a token the markdown passes below cannot rewrite. The token must not contain
 * characters the emphasis rules treat as markdown (`_` and `*`), or those rules corrupt it.
 */
function stash(replacement) {
    const token = `${PLACEHOLDER_OPEN}${INLINE_PLACEHOLDERS.length}${PLACEHOLDER_CLOSE}`;
    INLINE_PLACEHOLDERS.push(replacement);

    return token;
}

function stashInlineCode(html) {
    return html.replace(/`([^`]+)`/g, (_, code) => stash(`<code>${code}</code>`));
}

function anchor(href, label) {
    return `<a href="${href}" target="_blank" rel="noopener noreferrer">${label}</a>`;
}

function stashLinks(html) {
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, (_, label, href) => stash(anchor(href, label)));

    // Answers cite documentation as a bare URL, so link those too rather than leaving dead text.
    return html.replace(/(^|[\s(])(https?:\/\/[^\s<]+)/g, (_, prefix, url) => {
        const trailing = url.match(/[.,;:!?)\]]+$/)?.[0] ?? '';
        const href = url.slice(0, url.length - trailing.length);

        return href ? `${prefix}${stash(anchor(href, href))}${trailing}` : `${prefix}${url}`;
    });
}

function restorePlaceholders(html) {
    return html.replace(placeholderMatcher, (_, index) => INLINE_PLACEHOLDERS[Number(index)] ?? '');
}

function isPipeRow(line) {
    return typeof line === 'string' && /^\s*\|?.+\|.+\|?\s*$/.test(line);
}

function isTableSeparator(line) {
    return typeof line === 'string' && /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line);
}

function trimOuterPipes(line) {
    return line.trim().replace(/^\|/, '').replace(/\|$/, '');
}

function isUnorderedListItem(line) {
    return unorderedListMatcher.test(line);
}

function isOrderedListItem(line) {
    return orderedListMatcher.test(line);
}

function isBlank(line) {
    return !line || line.trim() === '';
}

function escapeHtml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

const unorderedListMatcher = /^\s*[-*]\s+(.+)$/;
const orderedListMatcher = /^\s*\d+\.\s+(.+)$/;
