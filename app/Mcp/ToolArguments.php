<?php

namespace App\Mcp;

use Illuminate\Validation\ValidationException;

/** Validate the advertised top-level contract before selectors reach the dispatcher. */
class ToolArguments
{
    public static function validate(array $arguments, array $schema): void
    {
        $errors = [];
        foreach ($schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $arguments)) {
                $errors[$field] = ['The '.$field.' field is required.'];
            }
        }
        foreach ((array) ($schema['properties'] ?? []) as $field => $property) {
            if (! array_key_exists($field, $arguments) || ! isset($property['type'])) {
                continue;
            }
            $types = (array) $property['type'];
            $value = $arguments[$field];
            $matches = false;
            foreach ($types as $type) {
                $matches = $matches || match ($type) {
                    'integer' => is_int($value),
                    'number' => is_int($value) || is_float($value),
                    'string' => is_string($value),
                    'boolean' => is_bool($value),
                    'null' => $value === null,
                    'array' => is_array($value) && array_is_list($value),
                    // PHP decodes JSON objects to associative arrays; an empty object also becomes [].
                    'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                    default => false,
                };
            }
            if (! $matches) {
                $errors[$field] = ['The '.$field.' field must be '.implode(' or ', $types).'.'];
                continue;
            }
            if (isset($property['enum']) && ! in_array($value, $property['enum'], true)) {
                $errors[$field] = ['The selected '.$field.' is invalid.'];
            }
            if (is_int($value) || is_float($value)) {
                if (isset($property['minimum']) && $value < $property['minimum']) {
                    $errors[$field] = ['The '.$field.' field must be at least '.$property['minimum'].'.'];
                }
                if (isset($property['maximum']) && $value > $property['maximum']) {
                    $errors[$field] = ['The '.$field.' field must not exceed '.$property['maximum'].'.'];
                }
            }
            if (is_string($value)) {
                if (isset($property['minLength']) && mb_strlen($value) < $property['minLength']) {
                    $errors[$field] = ['The '.$field.' field must contain at least '.$property['minLength'].' characters.'];
                }
                if (isset($property['maxLength']) && mb_strlen($value) > $property['maxLength']) {
                    $errors[$field] = ['The '.$field.' field must not exceed '.$property['maxLength'].' characters.'];
                }
            }
            if (is_array($value) && isset($property['maxItems']) && count($value) > $property['maxItems']) {
                $errors[$field] = ['The '.$field.' field must not exceed '.$property['maxItems'].' items.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
