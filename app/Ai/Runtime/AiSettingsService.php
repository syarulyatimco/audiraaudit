<?php

namespace App\Ai\Runtime;

use App\Ai\Agents\AgentRegistry;
use App\Models\Firm;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;

/** Firm-level AI opt-in. Off by default: client data is only sent to an AI provider after a firm admin enables it. */
class AiSettingsService
{
    public function __construct(private ActivityLogger $logger, private AgentRegistry $agents) {}

    /** @param list<string> $disabledAgents */
    public function update(Firm $firm, bool $enabled, array $disabledAgents, User $actor): void
    {
        $disabledAgents = array_values(array_intersect($disabledAgents, array_keys($this->agents->all())));

        DB::transaction(function () use ($firm, $enabled, $disabledAgents, $actor) {
            $was = $firm->ai_enabled;
            $firm->forceFill([
                'ai_enabled' => $enabled,
                'ai_enabled_at' => $enabled ? ($was ? $firm->ai_enabled_at : now()) : null,
                'ai_enabled_by' => $enabled ? ($was ? $firm->ai_enabled_by : $actor->id) : null,
                'ai_disabled_agents' => $disabledAgents ?: null,
            ])->save();

            $this->logger->log($enabled ? ($was ? 'ai.settings_updated' : 'ai.enabled') : 'ai.disabled',
                $enabled ? ($was ? 'AI agent settings updated' : 'AI assistance enabled for the firm') : 'AI assistance disabled for the firm',
                $firm, ['disabled_agents' => $disabledAgents], $actor, $firm->id);
        });
    }
}
