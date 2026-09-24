<?php

namespace App\AI;

class JsonSchemaValidator
{
    /** @param array<string, mixed> $schema */
    public function matches(mixed $value, array $schema): bool
    {
        $types = (array) ($schema['type'] ?? []);

        if ($types !== [] && ! collect($types)->contains(fn (mixed $type): bool => $this->matchesType($value, $type))) {
            return false;
        }

        if (array_key_exists('enum', $schema) && ! in_array($value, (array) $schema['enum'], true)) {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return false;
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return false;
            }
        }

        if (is_string($value)) {
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                return false;
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                return false;
            }
            if (isset($schema['pattern']) && preg_match('/'.$schema['pattern'].'/u', $value) !== 1) {
                return false;
            }
        }

        if (is_array($value) && array_is_list($value)) {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                return false;
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                return false;
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $item) {
                    if (! $this->matches($item, $schema['items'])) {
                        return false;
                    }
                }
            }
        }

        if ($value instanceof \stdClass || (is_array($value) && ! array_is_list($value))) {
            $object = $value instanceof \stdClass ? get_object_vars($value) : $value;

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $object)) {
                    return false;
                }
            }

            $properties = (array) ($schema['properties'] ?? []);
            foreach ($object as $key => $item) {
                if (isset($properties[$key]) && is_array($properties[$key]) && ! $this->matches($item, $properties[$key])) {
                    return false;
                }
                if (! array_key_exists($key, $properties) && ($schema['additionalProperties'] ?? true) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'object' => $value instanceof \stdClass || (is_array($value) && ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };
    }
}
