<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Models\GenerationArtifact;

/** 正文只有 content 一份权威文本；段落索引、证据和局部替换均从它确定性生成。 */
final class ChapterDraftService
{
    /** Unicode 码点位置与版本化字数口径一致，避免中文 byte offset 误定位。 */
    public function index(string $body): array
    {
        $paragraphs = [];
        preg_match_all('/[^\r\n]+(?:\n(?!\n)[^\r\n]+)*/u', $body, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$text, $offset]) {
            if (trim($text) === '') {
                continue;
            }
            $paragraphs[] = ['key' => 'p'.(count($paragraphs) + 1), 'offset' => mb_strlen(substr($body, 0, $offset)), 'length' => mb_strlen($text), 'hash' => hash('sha256', $text)];
        }

        return $paragraphs;
    }

    /** 逐字定位且限定段落，重复句子不会被默认匹配到第一处。 */
    public function evidence(GenerationArtifact $draft, array $reference): array
    {
        $paragraph = collect($draft->data['paragraph_index'] ?? [])->firstWhere('key', $reference['paragraph_key'] ?? null);
        $quote = $reference['quote'] ?? '';
        $offset = $reference['offset'] ?? -1;
        if (! $paragraph || ! is_int($offset) || $offset < 0 || trim($quote) === '' || $offset + mb_strlen($quote) > $paragraph['length']
            || mb_substr($draft->content, $paragraph['offset'] + $offset, mb_strlen($quote)) !== $quote) {
            throw new AiProviderException('book_evidence_invalid', '证据未在指定段落和位置逐字命中，请仅修复证据，保留正文。', false);
        }

        return ['artifact_id' => $draft->id, 'scene_id' => null, 'quote' => $quote, 'start_offset' => $paragraph['offset'] + $offset, 'end_offset' => $paragraph['offset'] + $offset + mb_strlen($quote)];
    }

    /** 只保留可以确定闭合的正文边界，不从损坏 JSON 猜出小说正文。 */
    public function recoverTruncated(array $data, string $raw): array
    {
        $body = $data['body'] ?? null;
        if (! is_string($body)) {
            throw new AiProviderException('book_truncation_unrecoverable', '截断响应无法可靠分离正文，原响应已保留，请修复当前写作片段。', false);
        }
        preg_match_all('/[。！？」”]/u', $body, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === []) {
            throw new AiProviderException('book_truncation_unrecoverable', '截断正文没有可确认的完整句边界，请人工核对保留响应。', false);
        }
        [$end, $position] = end($matches[0]);

        return ['body' => substr($body, 0, $position + strlen($end)), 'complete' => false, 'temporary_state' => $data['temporary_state'] ?? []];
    }

    /** 顺序及前驱身份均来自冻结合同；不使用语义相似度吞掉重复文字。 */
    public function assemble(array $fragments): array
    {
        $body = '';
        $previous = null;
        $sources = [];
        foreach ($fragments as $i => $fragment) {
            if (($fragment->data['sequence'] ?? -1) !== $i + 1 || ($fragment->data['previous_fragment_id'] ?? null) !== $previous?->id
                || ($previous && ($fragment->data['previous_fragment_hash'] ?? '') !== $previous->checksum)) {
                throw new AiProviderException('book_fragment_source_invalid', '片段顺序或前驱来源不一致，保留片段并重建受影响后继。', false);
            }
            $text = trim($fragment->content);
            if ($body !== '' && $text !== '') {
                $last = trim((string) preg_replace('/^.*\n\n/s', '', $body));
                if (mb_strlen($last) > 10 && str_starts_with($text, $last)) {
                    throw new AiProviderException('book_boundary_duplicate', '片段接续重复了前文边界，已保留两份文本，请局部修复而非自动删段。', false);
                }
            }
            $body .= ($body === '' ? '' : "\n\n").$text;
            $sources[] = ['artifact_id' => $fragment->id, 'checksum' => $fragment->checksum];
            $previous = $fragment;
        }
        if (! $previous || ! ($previous->data['complete'] ?? false)) {
            throw new AiProviderException('book_draft_incomplete', '最后片段尚未完整，不能提取、审校或提交。', false);
        }

        return ['body' => $body, 'paragraph_index' => $this->index($body), 'sources' => $sources];
    }

    /** 只应用已授权段落，并从后向前替换以保持原始偏移；未修改段落逐字保留。 */
    public function replace(GenerationArtifact $draft, array $replacements, array $allowedKeys): string
    {
        $changes = [];
        foreach ($replacements as $replacement) {
            $key = $replacement['paragraph_key'];
            $paragraph = collect($draft->data['paragraph_index'])->firstWhere('key', $key);
            if (! $paragraph || ! in_array($key, $allowedKeys, true) || isset($changes[$key]) || ! hash_equals($paragraph['hash'], $replacement['expected_hash'])) {
                throw new AiProviderException('book_rewrite_scope_invalid', '段落范围或原文 hash 已变化，旧修复不能覆盖当前正文。', false);
            }
            $changes[$key] = [...$paragraph, 'text' => $replacement['text']];
        }
        uasort($changes, fn ($a, $b) => $b['offset'] <=> $a['offset']);
        $body = $draft->content;
        foreach ($changes as $change) {
            $body = mb_substr($body, 0, $change['offset']).$change['text'].mb_substr($body, $change['offset'] + $change['length']);
        }
        if ($body === $draft->content) {
            throw new AiProviderException('book_repair_no_progress', '修复没有改变正文，停止相同方案重复调用。', false);
        }

        return $body;
    }
}
