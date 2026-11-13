<?php

namespace App\Support\Audit;

use App\Models\ActivityLog;
use App\Models\Firm;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes append-only audit-trail entries. Call inside the same DB transaction as the change.
 */
class ActivityLogger
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $actor = null,
        ?int $firmId = null,
        string $actorType = 'user',
        ?string $actorLabel = null,
    ): ActivityLog {
        $actor ??= Auth::user();
        $firmId ??= $this->firmOf($subject) ?? $actor?->firm_id ?? $this->tenant->id();

        $attributes = [
            'firm_id' => $firmId,
            'user_id' => $actor?->id,
            'actor_type' => $actor ? $actorType : ($actorType === 'agent' ? 'agent' : 'system'),
            'actor_label' => $actorLabel ?? $actor?->name ?? 'System',
            'event' => $event,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500) ?: null,
        ];

        // Platform-level events (no firm) and events about another tenant are written in
        // system mode; RLS would otherwise (correctly) reject them.
        if ($firmId === null || $firmId !== $this->tenant->id()) {
            return $this->tenant->runAsSystem(fn () => ActivityLog::create($attributes));
        }

        return ActivityLog::create($attributes);
    }

    private function firmOf(?Model $subject): ?int
    {
        if ($subject === null) {
            return null;
        }
        if ($subject instanceof Firm) {
            return $subject->getKey();
        }

        return array_key_exists('firm_id', $subject->getAttributes()) ? $subject->getAttributes()['firm_id'] : null;
    }
}
