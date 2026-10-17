<?php

namespace App\Support;

/** 诊断进入数据库、模型或页面前统一脱敏；正文不通过该类改写。 */
final class GenerationDiagnosticRedactor
{
    public function clean(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 12) {
            return '[内容过深，已省略]';
        }
        // 已知值对象只导出稳定值，避免高级诊断丢失阶段、状态和时间。
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = is_string($key) && preg_match('/password|secret|authorization|api[_-]?key|cookie|access[_-]?token|lease_token/i', $key)
                    ? '[已脱敏]' : $this->clean($item, $depth + 1);
            }

            return $result;
        }
        if (! is_string($value)) {
            return is_scalar($value) || $value === null ? $value : '[非文本诊断]';
        }
        // 原始响应可能是 JSON 字符串，先递归处理敏感字段而非仅匹配 Bearer。
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return json_encode($this->clean($decoded, $depth + 1), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        foreach ((array) config('ai.credentials', []) as $secret) {
            if (is_string($secret) && $secret !== '') {
                $value = str_replace($secret, '[已脱敏]', $value);
            }
        }
        $value = preg_replace('/\bBearer\s+[^\s,;"<>]+/i', 'Bearer [已脱敏]', $value) ?? '';
        $value = preg_replace('/\bsk-[a-zA-Z0-9_-]+/', '[已脱敏]', $value) ?? '';
        $value = preg_replace('/((?:api[_-]?key|password|secret|access_token)\s*[=:]\s*)[^\s&;,"<>]+/i', '$1[已脱敏]', $value) ?? '';
        $value = preg_replace('~(https?://)[^/@\s]+:[^/@\s]+@~i', '$1[已脱敏]@', $value) ?? '';

        return mb_substr($value, 0, 12000);
    }
}
