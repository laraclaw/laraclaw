<?php

namespace LaraClaw\Support;

/**
 * Strip HTML tags and decode entities from a string, returning null if input is null.
 */
function stripHtml(?string $html): ?string
{
    if ($html === null) {
        return null;
    }

    return trim(strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8')));
}
