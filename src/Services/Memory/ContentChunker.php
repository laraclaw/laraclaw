<?php

namespace Laraclaw\Services\Memory;

class ContentChunker
{
    private const int SIZE = 1000;

    private const int OVERLAP = 200;

    /**
     * Split content into overlapping chunks, breaking at paragraph or sentence boundaries.
     *
     * @return list<string>
     */
    public function chunk(string $content): array
    {
        $content = trim($content);

        if ($content === '' || mb_strlen($content) <= self::SIZE) {
            return $content !== '' ? [$content] : [];
        }

        $chunks = [];
        $offset = 0;
        $length = mb_strlen($content);

        while ($offset < $length) {
            $slice = mb_substr($content, $offset, self::SIZE);

            // Last slice: append and stop
            if ($offset + self::SIZE >= $length) {
                $chunks[] = trim($slice);

                break;
            }

            $slice = $this->breakAtBoundary($slice);
            $chunks[] = trim($slice);
            $offset += mb_strlen($slice) - self::OVERLAP;
        }

        return array_values(array_filter($chunks));
    }

    /**
     * Trim a chunk back to the nearest paragraph or sentence boundary so embeddings stay coherent.
     */
    private function breakAtBoundary(string $text): string
    {
        $minPosition = (int) (self::SIZE * 0.3);

        $paragraph = mb_strrpos($text, "\n\n");
        if ($paragraph !== false && $paragraph > $minPosition) {
            return mb_substr($text, 0, $paragraph);
        }

        $sentence = mb_strrpos($text, '. ');
        if ($sentence !== false && $sentence > $minPosition) {
            return mb_substr($text, 0, $sentence + 1);
        }

        return $text;
    }
}
