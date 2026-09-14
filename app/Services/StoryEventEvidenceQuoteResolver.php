<?php

namespace App\Services;

final class StoryEventEvidenceQuoteResolver
{
    public function resolve(string $content, string $quote): string
    {
        $quote = trim($quote, " \n\r\t\v\0\"'“”‘’");

        if ($quote === '' || str_contains($content, $quote)) {
            return $quote;
        }

        $whitespacePattern = preg_replace('/\\s+/u', '\\s+', preg_quote($quote, '/'));

        if (is_string($whitespacePattern)
            && preg_match('/'.$whitespacePattern.'/u', $content, $match) === 1) {
            return $match[0];
        }

        $fragments = preg_split('/(?:…+|\.{3,})/u', $quote);

        if (is_array($fragments) && count($fragments) > 1) {
            $fragments = array_values(array_filter(array_map('trim', $fragments), fn (string $fragment): bool => mb_strlen($fragment) >= 4));
            $start = null;
            $end = 0;

            foreach ($fragments as $fragment) {
                $position = mb_strpos($content, $fragment, $end);

                if ($position === false) {
                    $start = null;
                    break;
                }

                $start ??= $position;
                $end = $position + mb_strlen($fragment);
            }

            if ($start !== null) {
                return mb_substr($content, $start, $end - $start);
            }
        }

        return $this->highConfidenceOverlap($content, $quote) ?? $quote;
    }

    private function highConfidenceOverlap(string $content, string $quote): ?string
    {
        $contentCharacters = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $quoteCharacters = preg_split('//u', $quote, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($contentCharacters) || ! is_array($quoteCharacters) || $quoteCharacters === []) {
            return null;
        }

        $previous = array_fill(0, count($quoteCharacters) + 1, 0);
        $bestLength = 0;
        $bestEnd = 0;

        foreach ($contentCharacters as $contentIndex => $contentCharacter) {
            $current = array_fill(0, count($quoteCharacters) + 1, 0);

            foreach ($quoteCharacters as $quoteIndex => $quoteCharacter) {
                if ($contentCharacter !== $quoteCharacter) {
                    continue;
                }

                $current[$quoteIndex + 1] = $previous[$quoteIndex] + 1;

                if ($current[$quoteIndex + 1] > $bestLength) {
                    $bestLength = $current[$quoteIndex + 1];
                    $bestEnd = $contentIndex + 1;
                }
            }

            $previous = $current;
        }

        if ($bestLength < 8 || $bestLength / count($quoteCharacters) < .8) {
            return null;
        }

        return implode('', array_slice($contentCharacters, $bestEnd - $bestLength, $bestLength));
    }
}
