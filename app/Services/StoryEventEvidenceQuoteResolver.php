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

        // Provider 有时把跨段引用中的换行写成字面 \n；只从原文恢复连续片段，不拼造证据。
        $quote = str_replace(['\\r\\n', '\\n', '\\r', '\\t'], ["\n", "\n", "\r", "\t"], $quote);
        if (str_contains($content, $quote)) {
            return $quote;
        }

        $whitespacePattern = preg_replace('/\\s+/u', '\\s+', preg_quote($quote, '/'));

        if (is_string($whitespacePattern)
            && preg_match('/'.$whitespacePattern.'/u', $content, $match) === 1) {
            return $match[0];
        }

        $ignoringWhitespace = $this->uniqueMatchIgnoringWhitespace($content, $quote);
        if ($ignoringWhitespace !== null) {
            return $ignoringWhitespace;
        }

        $fragments = preg_split('/(?:…+|\.{3,}|\n\s*\n)/u', $quote);

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
                $continuousQuote = mb_substr($content, $start, $end - $start);
                $quotedLength = array_sum(array_map(
                    fn (string $fragment): int => mb_strlen((string) preg_replace('/\s+/u', '', $fragment)),
                    $fragments,
                ));
                $continuousLength = mb_strlen((string) preg_replace('/\s+/u', '', $continuousQuote));
                if ($continuousLength > 0 && $quotedLength / $continuousLength >= .8) {
                    return $continuousQuote;
                }
            }
        }

        $withOmittedAttribution = $this->uniqueMatchWithOmittedAttribution($content, $quote);
        if ($withOmittedAttribution !== null) {
            return $withOmittedAttribution;
        }

        return $this->highConfidenceOverlap($content, $quote) ?? $quote;
    }

    private function uniqueMatchWithOmittedAttribution(string $content, string $quote): ?string
    {
        // 引文可能省略两句对白之间的短叙述。只从唯一的前后原文锚点恢复连续正文。
        $fragments = preg_split('/(?<=[”’。！？!?])\s+(?=\S)/u', $quote);
        if (! is_array($fragments) || count($fragments) !== 2) {
            return null;
        }

        [$first, $second] = array_map('trim', $fragments);
        if (mb_strlen($first) < 12 || mb_strlen($second) < 12) {
            return null;
        }

        $start = mb_strpos($content, $first);
        $secondStart = mb_strpos($content, $second);
        if ($start === false || $secondStart === false
            || mb_strpos($content, $first, $start + 1) !== false
            || mb_strpos($content, $second, $secondStart + 1) !== false) {
            return null;
        }

        $firstEnd = $start + mb_strlen($first);
        if ($secondStart <= $firstEnd) {
            return null;
        }

        $gap = mb_substr($content, $firstEnd, $secondStart - $firstEnd);
        if (mb_strlen((string) preg_replace('/\s+/u', '', $gap)) > 24) {
            return null;
        }

        $continuousQuote = mb_substr($content, $start, $secondStart + mb_strlen($second) - $start);
        $quotedLength = mb_strlen((string) preg_replace('/\s+/u', '', $first.$second));
        $continuousLength = mb_strlen((string) preg_replace('/\s+/u', '', $continuousQuote));

        return $continuousLength > 0 && $quotedLength / $continuousLength >= .8
            ? $continuousQuote
            : null;
    }

    private function uniqueMatchIgnoringWhitespace(string $content, string $quote): ?string
    {
        $contentCharacters = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $quoteWithoutWhitespace = preg_replace('/\s+/u', '', $quote);
        if (! is_array($contentCharacters)
            || ! is_string($quoteWithoutWhitespace)
            || mb_strlen($quoteWithoutWhitespace) < 8) {
            return null;
        }

        $normalizedContent = '';
        $sourceIndexes = [];
        foreach ($contentCharacters as $sourceIndex => $character) {
            if (preg_match('/\s/u', $character) === 1) {
                continue;
            }

            $normalizedContent .= $character;
            $sourceIndexes[] = $sourceIndex;
        }

        $start = mb_strpos($normalizedContent, $quoteWithoutWhitespace);
        if ($start === false
            || mb_strpos($normalizedContent, $quoteWithoutWhitespace, $start + 1) !== false) {
            return null;
        }

        $end = $start + mb_strlen($quoteWithoutWhitespace) - 1;
        if (! isset($sourceIndexes[$start], $sourceIndexes[$end])) {
            return null;
        }

        return mb_substr(
            $content,
            $sourceIndexes[$start],
            $sourceIndexes[$end] - $sourceIndexes[$start] + 1,
        );
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
