<?php

namespace LaraClaw\Support;

function stripHtml(?string $html): ?string
{
    if ($html === null) {
        return null;
    }

    return trim(strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8')));
}
