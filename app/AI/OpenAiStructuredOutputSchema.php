<?php

namespace App\AI;

use App\AI\Exceptions\AiProviderException;

final class OpenAiStructuredOutputSchema
{
    /**
     * OpenAI strict Structured Outputs only accepts closed objects whose declared
     * properties are all required. Validate this locally so a schema defect does
     * not consume a remote request and surface later as an opaque HTTP 400.
     *
     * @param  array<string, mixed>  $schema
     */
    public function assertValid(array $schema): void
    {
        $errors = $this->errors($schema);

        if ($errors === []) {
            return;
        }

        throw new AiProviderException(
            errorCode: 'provider_invalid_response_schema',
            message: 'OpenAI 结构化输出 Schema 无效：'.implode('；', $errors),
            retryable: false,
        );
    }

    /** @param array<string, mixed> $schema @return array<int, string> */
    public function errors(array $schema): array
    {
        $errors = [];

        if (($schema['type'] ?? null) !== 'object') {
            $errors[] = '$：根节点 type 必须是 object';
        }

        if (array_key_exists('anyOf', $schema)) {
            $errors[] = '$：根节点不能使用 anyOf';
        }

        $this->inspect($schema, '$', $errors);

        return $errors;
    }

    /** @param array<string, mixed> $schema @param array<int, string> $errors */
    private function inspect(array $schema, string $path, array &$errors): void
    {
        foreach (['allOf', 'oneOf', 'not', 'dependentRequired', 'dependentSchemas', 'if', 'then', 'else', 'uniqueItems'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $errors[] = "{$path}：不支持关键字 {$keyword}";
            }
        }

        $types = (array) ($schema['type'] ?? []);
        if (in_array('object', $types, true)) {
            $properties = $schema['properties'] ?? null;
            $required = $schema['required'] ?? null;

            if (($schema['additionalProperties'] ?? null) !== false) {
                $errors[] = "{$path}：object 必须设置 additionalProperties=false";
            }

            if (! is_array($properties)) {
                $errors[] = "{$path}：object 必须声明 properties";
                $properties = [];
            }

            if (! is_array($required)) {
                $errors[] = "{$path}：object 必须声明 required";
                $required = [];
            }

            $propertyNames = array_keys($properties);
            $requiredNames = array_values(array_filter($required, 'is_string'));
            sort($propertyNames);
            sort($requiredNames);

            if ($propertyNames !== $requiredNames) {
                $errors[] = "{$path}：required 必须完整覆盖 properties";
            }

            foreach ($properties as $name => $propertySchema) {
                if (is_array($propertySchema)) {
                    $this->inspect($propertySchema, $path.'.properties.'.$name, $errors);
                }
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $this->inspect($schema['items'], $path.'.items', $errors);
        }

        foreach (['anyOf', 'oneOf'] as $composition) {
            foreach ((array) ($schema[$composition] ?? []) as $index => $nestedSchema) {
                if (is_array($nestedSchema)) {
                    $this->inspect($nestedSchema, "{$path}.{$composition}.{$index}", $errors);
                }
            }
        }

        foreach (['$defs', 'definitions'] as $definitionsKey) {
            foreach ((array) ($schema[$definitionsKey] ?? []) as $name => $nestedSchema) {
                if (is_array($nestedSchema)) {
                    $this->inspect($nestedSchema, "{$path}.{$definitionsKey}.{$name}", $errors);
                }
            }
        }
    }
}
