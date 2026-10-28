<?php

namespace App\Support;

/**
 * Conditional questions: a field whose settings carry
 * show_if = {field: <source field id>, equals: <answer>} is only asked when the
 * attendee gave that answer. Mirrors frontend/src/utils/formConditions.js so the
 * form, its preview and server validation always agree on what was asked.
 */
class FormConditions
{
    /** @param array<string, object> $fieldsById fields keyed by (string) id */
    public static function visible(object $field, array $fieldsById, array $formData, int $depth = 0): bool
    {
        $condition = ($field->settings ?? [])['show_if'] ?? null;
        if (! is_array($condition) || ($condition['field'] ?? '') === '' || ($condition['field'] ?? null) === null || $depth > 10) return true;

        // A condition on a question that no longer exists never hides anything.
        $source = $fieldsById[(string) $condition['field']] ?? null;
        if (! $source) return true;
        if (! self::visible($source, $fieldsById, $formData, $depth + 1)) return false;

        $answer = $formData[(string) $source->id] ?? null;
        $expected = (string) ($condition['equals'] ?? '');

        return is_array($answer)
            ? in_array($expected, array_map(fn ($item) => is_scalar($item) ? (string) $item : '', $answer), true)
            : is_scalar($answer) && (string) $answer === $expected;
    }

    /** @return array<string, object> */
    public static function index(iterable $fields): array
    {
        $byId = [];
        foreach ($fields as $field) $byId[(string) $field->id] = $field;
        return $byId;
    }
}
