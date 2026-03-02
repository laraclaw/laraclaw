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
