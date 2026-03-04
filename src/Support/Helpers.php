<?php

namespace LaraClaw\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Convert Markdown to Slack mrkdwn format.
 * Delegates parsing to CommonMark so nesting, escaping, and code spans are handled correctly.
 */
function markdownToMrkdwn(string $text): string
{
    $environment = new Environment;
    $environment->addExtension(new CommonMarkCoreExtension);
    $environment->addExtension(new StrikethroughExtension);

    $html = (new MarkdownConverter($environment))->convert($text)->getContent();

    // Handle fenced code blocks first so their content is not touched by the rules below.
    $html = preg_replace_callback(
        '/<pre><code[^>]*>(.*?)<\/code><\/pre>/s',
        fn ($m) => "\n```\n" . html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') . "```\n",
        $html,
    );

    $html = preg_replace('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/s', "*$1*\n", $html);
    $html = preg_replace('/<strong>(.*?)<\/strong>/s', '*$1*', $html);
    $html = preg_replace('/<em>(.*?)<\/em>/s', '_$1_', $html);
    $html = preg_replace('/<del>(.*?)<\/del>/s', '~$1~', $html);
    $html = preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', '<$1|$2>', $html);
    $html = preg_replace('/<code>(.*?)<\/code>/s', '`$1`', $html);
    $html = preg_replace('/<li[^>]*>(.*?)<\/li>/s', '• $1', $html);
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = strip_tags($html);

    return trim(html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
}

/**
 * Render a Markdown string to the terminal using ANSI escape codes.
 *
 * Handles headings, bold, italic, inline code, fenced code blocks,
 * ordered and unordered lists, blockquotes, and horizontal rules.
 */
function markdownToAnsi(string $markdown): string
{
    $lines = explode("\n", $markdown);
    $output = "\n";
    $inCodeBlock = false;
    $codeLines = [];

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '```')) {
            if (! $inCodeBlock) {
                $inCodeBlock = true;

                continue;
            }

            $inCodeBlock = false;

            foreach ($codeLines as $codeLine) {
                $output .= "  \033[90m{$codeLine}\033[39m\n";
            }

            $codeLines = [];
            $output .= "\n";

            continue;
        }

        if ($inCodeBlock) {
            $codeLines[] = $line;

            continue;
        }

        if (preg_match('/^(?:#{1,6})\s+(.+)$/', $line, $m)) {
            $output .= "\n\033[1m" . ansiInline($m[1]) . "\033[22m\n\n";

            continue;
        }

        if (preg_match('/^(\s*)[*\-+]\s+(.+)$/', $line, $m)) {
            $indent = str_repeat('  ', (int) (strlen($m[1]) / 2));
            $output .= "{$indent}• " . ansiInline($m[2]) . "\n";

            continue;
        }

        if (preg_match('/^(\s*)(\d+)\.\s+(.+)$/', $line, $m)) {
            $indent = str_repeat('  ', (int) (strlen($m[1]) / 2));
            $output .= "{$indent}{$m[2]}. " . ansiInline($m[3]) . "\n";

            continue;
        }

        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $output .= "\033[90m│ " . ansiInline($m[1]) . "\033[39m\n";

            continue;
        }

        if (preg_match('/^[-*_]{3,}$/', trim($line))) {
            $output .= str_repeat('─', 60) . "\n";

            continue;
        }

        $output .= ansiInline($line) . "\n";
    }

    return $output;
}

/**
 * Apply inline Markdown formatting (bold, italic, code) as ANSI escape codes.
 */
function ansiInline(string $text): string
{
    // Extract links before any other processing so URLs are not touched by bold/italic regexes.
    // Underscores in URLs (e.g. utm_source) would otherwise trigger the italic pattern.
    $links = [];
    // Pass by reference is necessary here because preg_replace_callback fires per match
    // and we need to accumulate all links into one array across multiple calls.
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($m) use (&$links) {
        $token = "\u{E000}" . count($links) . "\u{E000}";
        $links[$token] = $m;

        return $token;
    }, $text);

    // Inline code next so bold/italic patterns inside backticks are left alone
    $text = preg_replace('/`([^`]+)`/', "\033[7m \$1 \033[27m", $text);
    $text = preg_replace(['/\*\*(.+?)\*\*/', '/__(.+?)__/'], "\033[1m\$1\033[22m", $text);
    $text = preg_replace(['/\*([^*\n]+)\*/', '/_([^_\n]+)_/'], "\033[3m\$1\033[23m", $text);

    // Catch bare https:// URLs before restoring link tokens so the regex cannot
    // match URLs that are already embedded inside OSC 8 escape sequences.
    $text = preg_replace_callback(
        '/https?:\/\/[^\s\)\]]+/',
        fn ($m) => "\033]8;;{$m[0]}\007\033[4m{$m[0]}\033[24m\033]8;;\007",
        $text,
    );

    // Restore extracted [text](url) links as OSC 8 hyperlinks
    $text = str_replace(
        array_keys($links),
        array_map(fn ($m) => "\033]8;;{$m[2]}\007\033[4m{$m[1]}\033[24m\033]8;;\007", $links),
        $text,
    );

    return $text;
}

/**
 * Interpolate {placeholder} tokens in a template string using values from an array.
 *
 * Array values are joined with a comma. Tokens with no matching key are left as-is.
 *
 * @param  array<string, mixed>  $values
 */
function interpolate(string $template, array $values): string
{
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($values) {
        $value = $values[$m[1]] ?? $m[0];

        return is_array($value) ? implode(', ', $value) : $value;
    }, $template);
}

/**
 * Strip HTML tags and decode entities from a string, returning null if input is null.
 */
function stripHtml(?string $html): ?string
{
    if ($html === null) {
        return null;
    }

    return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8'))));
}
