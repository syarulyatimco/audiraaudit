<?php

namespace Tests\Support;

use App\Enums\UserType;
use App\Models\ClientFsVersion;
use App\Models\User;

/** Management (a client portal user) prepares and submits financial statements; the manager accepts them. */
trait BuildsClientFs
{
    protected function portalUser(): User
    {
        return User::where('email', 'fd@client.test')->first() ?? User::forceCreate([
            'firm_id' => $this->ctx['firm']->id, 'client_id' => $this->ctx['client']->id, 'type' => UserType::Client,
            'name' => 'Farah (Finance Director)', 'email' => 'fd@client.test', 'password' => 'x', 'status' => 'active',
        ]);
    }

    protected function fsUrl(): string
    {
        return '/portal/engagements/'.$this->ctx['engagement']->uuid.'/financial-statements';
    }

    /** Starts the company layout and fills a balanced SOFP that agrees to the test TB's audited figures. */
    protected function preparedClientFs(): void
    {
        $client = $this->portalUser();
        $this->actingAs($client)->post($this->fsUrl(), ['template' => 'company'])->assertSessionHasNoErrors();
        $this->actingAs($client)->putJson($this->fsUrl().'/statements/sofp', ['rows' => [
            ['kind' => 'line', 'side' => 'asset', 'caption' => 'Property, plant and equipment', 'cy' => '120,000.00'],
            ['kind' => 'line', 'side' => 'asset', 'caption' => 'Inventories', 'cy' => '60,000.00'],
            ['kind' => 'line', 'side' => 'asset', 'caption' => 'Trade receivables', 'cy' => '80,000.00'],
            ['kind' => 'line', 'side' => 'asset', 'caption' => 'Cash and bank balances', 'cy' => '45,000.00'],
            ['kind' => 'line', 'side' => 'el', 'caption' => 'Share capital', 'cy' => '100,000.00'],
            ['kind' => 'line', 'side' => 'el', 'caption' => 'Retained earnings', 'cy' => '200,000.00'],
            ['kind' => 'line', 'side' => 'el', 'caption' => 'Borrowings (current)', 'cy' => '5,000.00'],
        ]])->assertOk();
    }

    protected function acceptedClientFs(): ClientFsVersion
    {
        $this->preparedClientFs();
        $this->actingAs($this->portalUser())->post($this->fsUrl().'/submit', ['directors_approved' => '1', 'approved_by_names' => 'Tan Sri A, Datin B', 'approved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $v = ClientFsVersion::latest('id')->first();
        $this->actingAs($this->ctx['manager'])->post($this->base()."/management-fs/versions/{$v->uuid}/review", ['decision' => 'accept', 'comment' => 'Agreed to audited figures; P&L lines not tied in this test.'])->assertSessionHasNoErrors();

        return $v->fresh();
    }
}
