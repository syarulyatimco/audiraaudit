<?php

namespace App\Ai\Agents;

use InvalidArgumentException;

class AgentRegistry
{
    public function get(string $key): Agent
    {
        $class = config("ai.agents.{$key}.class") ?? throw new InvalidArgumentException("Unknown agent {$key}");

        return app($class);
    }

    /** @return array<string, Agent> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(config('ai.agents')) as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }
}
