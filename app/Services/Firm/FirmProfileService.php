<?php

namespace App\Services\Firm;

use App\Models\Firm;
use App\Models\User;
use App\Support\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;

class FirmProfileService
{
    public function __construct(private ActivityLogger $logger) {}

    /** @param  array<string, mixed>  $data */
    public function update(Firm $firm, array $data, User $actor): Firm
    {
        return DB::transaction(function () use ($firm, $data, $actor) {
            $firm->fill($data);
            $changes = array_keys($firm->getDirty());
            $firm->save();

            if ($changes) {
                $this->logger->log('firm.updated', 'Firm profile updated', $firm, ['fields' => $changes], $actor);
            }

            return $firm;
        });
    }
}
