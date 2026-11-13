<?php

namespace App\Ai\Guardrails;

/**
 * Minimal JSON Schema validator for agent outputs: type (object/array/string/integer/number/
 * boolean/null), required, properties, additionalProperties=false, items, enum, maxLength,
 * minimum/maximum, maxItems. Returns a list of error strings (empty = valid).
 */
final class JsonSchema
{
    /** @return list<string> */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type = $schema['type'] ?? null;
        $types = (array) $type;

        if ($type !== null && ! self::matchesAny($value, $types)) {
            return ["{$path}: expected ".implode('|', $types).', got '.get_debug_type($value)];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: must be one of ".implode(', ', array_map('json_encode', $schema['enum']));
        }
        if (is_string($value) && isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $errors[] = "{$path}: longer than {$schema['maxLength']} characters";
        }
        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$path}: below minimum {$schema['minimum']}";
        }
        if ((is_int($value) || is_float($value)) && isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "{$path}: above maximum {$schema['maximum']}";
        }

        if (in_array('object', $types, true) && is_array($value)) {
            foreach ($schema['required'] ?? [] as $req) {
                if (! array_key_exists($req, $value)) {
                    $errors[] = "{$path}.{$req}: required";
                }
            }
            $props = (array) ($schema['properties'] ?? []);
            foreach ($value as $k => $v) {
                if (isset($props[$k])) {
                    array_push($errors, ...self::validate($v, $props[$k], "{$path}.{$k}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$k}: not allowed";
                }
            }
        }
        if (in_array('array', $types, true) && is_array($value)) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: more than {$schema['maxItems']} items";
            }
            foreach ($value as $i => $item) {
                if (isset($schema['items'])) {
                    array_push($errors, ...self::validate($item, $schema['items'], "{$path}[{$i}]"));
                }
            }
        }

        return array_slice($errors, 0, 50);
    }

    private static function matchesAny(mixed $v, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => is_array($v) && ($v === [] || ! array_is_list($v)),
                'array' => is_array($v) && array_is_list($v),
                'string' => is_string($v),
                'integer' => is_int($v),
                'number' => is_int($v) || is_float($v),
                'boolean' => is_bool($v),
                'null' => $v === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /** Extract a JSON object from model text (tolerates ```json fences and surrounding prose). */
    public static function extractJson(string $text): ?array
    {
        $t = trim($text);
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $t, $m)) {
            $t = $m[1];
        } elseif (($s = strpos($t, '{')) !== false && ($e = strrpos($t, '}')) !== false) {
            $t = substr($t, $s, $e - $s + 1);
        }
        $d = json_decode($t, true);

        return is_array($d) ? $d : null;
    }
}
