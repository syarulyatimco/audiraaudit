<?php

namespace Tests;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Enums\SubscriptionStatus;
use App\Models\Client;
use App\Models\Engagement;
use App\Models\Firm;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // AI providers are never configured by the surrounding environment in tests: suites that
        // need one set a fake key explicitly and Http::fake the provider endpoint.
        config([
            'ai.providers.anthropic.api_key' => null,
            'ai.providers.anthropic.base_url' => 'https://api.anthropic.com',
            'ai.providers.openai.api_key' => null,
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'ai.rag.driver' => 'fulltext',
            'ai.rag.api_key' => null,
        ]);
        // Test fixtures are created as trusted system code; HTTP requests switch to the user's
        // tenant via SetTenantContext and restore system mode afterwards.
        $this->tenant()->set(null);
        $this->enterSystem();
    }

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Enter system mode for fixtures without a closure (left on for the test's lifetime). */
    protected function enterSystem(): void
    {
        $ref = new \ReflectionProperty(TenantContext::class, 'systemDepth');
        $ref->setValue($this->tenant(), 1);
        $sync = new \ReflectionMethod(TenantContext::class, 'sync');
        $sync->invoke($this->tenant());
    }

    protected function plan(array $attrs = []): Plan
    {
        return Plan::factory()->create($attrs);
    }

    /** A firm with an active subscription and a partner who is firm admin. */
    protected function makeFirm(array $planAttrs = []): array
    {
        $firm = Firm::factory()->create();
        $plan = $this->plan($planAttrs);
        Subscription::create([
            'firm_id' => $firm->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
            'price_per_user_sen' => $plan->price_per_user_sen, 'min_paid_users' => $plan->min_paid_users,
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        $admin = User::factory()->partner()->admin()->create(['firm_id' => $firm->id]);

        return [$firm, $admin];
    }

    protected function staff(Firm $firm, FirmRole $role = FirmRole::Associate, array $attrs = []): User
    {
        return User::factory()->role($role)->create(['firm_id' => $firm->id, ...$attrs]);
    }

    protected function client(User $creator, bool $accepted = true, array $attrs = []): Client
    {
        $factory = Client::factory()->forUser($creator);

        return ($accepted ? $factory->accepted() : $factory)->create($attrs);
    }

    protected function engagement(Client $client, User $partner, array $members = []): Engagement
    {
        $engagement = Engagement::factory()->make(['client_id' => $client->id, 'created_by' => $partner->id]);
        $engagement->firm_id = $client->firm_id;
        $engagement->save();
        $engagement->members()->forceCreate(['firm_id' => $client->firm_id, 'user_id' => $partner->id, 'role' => EngagementRole::EngagementPartner]);
        foreach ($members as [$user, $role]) {
            $engagement->members()->forceCreate(['firm_id' => $client->firm_id, 'user_id' => $user->id, 'role' => $role]);
        }

        return $engagement->fresh();
    }
}
