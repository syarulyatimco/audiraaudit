<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Holds the current tenant (firm) for this request / job and mirrors it into the PostgreSQL
 * session variables used by Row-Level Security policies.
 *
 * Fail-closed: with no firm and not in system mode, tenant-scoped queries return nothing.
 */
class TenantContext
{
    private ?int $firmId = null;

    private int $systemDepth = 0;

    public function id(): ?int
    {
        return $this->firmId;
    }

    public function hasTenant(): bool
    {
        return $this->firmId !== null;
    }

    public function isSystem(): bool
    {
        return $this->systemDepth > 0;
    }

    /** Set the active firm and leave system mode (used when a user's request begins). */
    public function set(?int $firmId): void
    {
        $this->firmId = $firmId;
        $this->systemDepth = 0;
        $this->sync();
    }

    public function clear(): void
    {
        $this->set(null);
    }

    /** @return array{0: ?int, 1: int} */
    public function snapshot(): array
    {
        return [$this->firmId, $this->systemDepth];
    }

    /** @param  array{0: ?int, 1: int}  $snapshot */
    public function restore(array $snapshot): void
    {
        [$this->firmId, $this->systemDepth] = $snapshot;
        $this->sync();
    }

    /**
     * Run trusted platform code that must cross tenant boundaries (registration, superadmin
     * platform screens, invitation lookup, seeders). Every call site is listed in docs/SECURITY.md.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAsSystem(callable $callback): mixed
    {
        $this->systemDepth++;
        $this->sync();

        try {
            return $callback();
        } finally {
            $this->systemDepth--;
            $this->sync();
        }
    }

    /**
     * Run code as a specific firm (e.g. queued jobs restoring their tenant).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runForFirm(int $firmId, callable $callback): mixed
    {
        $snapshot = $this->snapshot();
        $this->set($firmId);

        try {
            return $callback();
        } finally {
            $this->restore($snapshot);
        }
    }

    /** Mirror state into PostgreSQL so RLS enforces the same boundary as the application. */
    private function sync(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            "select set_config('app.firm_id', ?, false), set_config('app.bypass_rls', ?, false)",
            [$this->firmId === null ? '' : (string) $this->firmId, $this->isSystem() ? 'on' : 'off'],
        );
    }
}
