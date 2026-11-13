<?php

namespace Tests\Unit;

use App\Ai\Guardrails\JsonSchema;
use PHPUnit\Framework\TestCase;

class JsonSchemaTest extends TestCase
{
    private array $schema = ['type' => 'object', 'required' => ['items'], 'additionalProperties' => false, 'properties' => [
        'items' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'object', 'required' => ['c'], 'properties' => [
            'c' => ['type' => 'string', 'enum' => ['high', 'low']], 'n' => ['type' => ['string', 'null'], 'maxLength' => 3],
        ]]],
    ]];

    public function test_valid_and_invalid_documents(): void
    {
        $this->assertSame([], JsonSchema::validate(['items' => [['c' => 'high', 'n' => null]]], $this->schema));
        $errors = JsonSchema::validate(['items' => [['c' => 'mid', 'n' => 'toolong'], ['n' => 'x'], ['c' => 'low']], 'extra' => 1], $this->schema);
        $joined = implode(' | ', $errors);
        $this->assertStringContainsString('$.extra: not allowed', $joined);
        $this->assertStringContainsString('more than 2 items', $joined);
        $this->assertStringContainsString('$.items[0].c: must be one of', $joined);
        $this->assertStringContainsString('$.items[0].n: longer than 3', $joined);
        $this->assertStringContainsString('$.items[1].c: required', $joined);
        $this->assertSame(['$: expected object, got string'], JsonSchema::validate('x', $this->schema));
    }

    public function test_extracts_json_from_fenced_or_prose_text(): void
    {
        $this->assertSame(['a' => 1], JsonSchema::extractJson("Here you go:\n```json\n{\"a\": 1}\n```"));
        $this->assertSame(['a' => 1], JsonSchema::extractJson('Result: {"a": 1} done'));
        $this->assertNull(JsonSchema::extractJson('no json here'));
    }
}
