<?php

namespace App\Services\Billing;

use App\Enums\FirmStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Gateways\GatewayManager;
use App\Support\Audit\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly per-user invoices in arrears of the plan snapshot on the subscription: billable seats =
 * max(active paid users, plan minimum) at issue time; client portal users are free. Tax is applied
 * only when configured (no tax position is assumed). Overdue invoices beyond the grace period put
 * the subscription in past_due; payment restores it.
 */
class InvoiceService
{
    public function __construct(private BillingService $billing, private ActivityLogger $logger, private GatewayManager $gateways, private TenantContext $tenant) {}

    /** Issue the invoice for one subscription period (idempotent per period). */
    public function issue(Subscription $sub, Carbon $start, Carbon $end, ?User $actor = null): Invoice
    {
        return $this->tenant->runAsSystem(function () use ($sub, $start, $end, $actor) {
            if ($existing = Invoice::where('subscription_id', $sub->id)->whereDate('period_start', $start->toDateString())->first()) {
                return $existing;
            }
            $firm = Firm::findOrFail($sub->firm_id);
            $est = $this->billing->estimate($firm);
            $plan = $sub->plan;
            $users = max($est['active_users'], (int) $sub->min_paid_users);
            $lines = [[
                'description' => "{$plan->name} plan — {$users} user(s) for ".$start->format('j M Y').' – '.$end->format('j M Y')
                    .($users > $est['active_users'] ? " (minimum {$sub->min_paid_users} paid users; {$est['active_users']} active)" : ''),
                'quantity' => $users, 'unit_price_sen' => (int) $sub->price_per_user_sen, 'amount_sen' => $users * (int) $sub->price_per_user_sen,
            ]];
            $subtotal = array_sum(array_column($lines, 'amount_sen'));
            [$taxName, $rate] = [config('audira.billing.tax.name'), config('audira.billing.tax.rate')];
            $taxed = filled($taxName) && $rate !== null && $rate > 0;
            $tax = $taxed ? (int) round($subtotal * $rate / 100) : 0;

            return DB::transaction(function () use ($sub, $firm, $start, $end, $lines, $subtotal, $taxed, $taxName, $rate, $tax, $actor) {
                DB::select("SELECT pg_advisory_xact_lock(hashtext('audira_invoice_number'))");
                $prefix = config('audira.billing.invoice_prefix').'-'.now()->format('Y').'-';
                $last = Invoice::where('number', 'like', $prefix.'%')->max('number');
                $seq = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
                $inv = new Invoice([
                    'subscription_id' => $sub->id, 'number' => $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                    'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'currency' => 'MYR', 'lines' => $lines,
                    'subtotal_sen' => $subtotal, 'tax_name' => $taxed ? $taxName : null, 'tax_rate' => $taxed ? $rate : 0, 'tax_sen' => $tax,
                    'total_sen' => $subtotal + $tax, 'due_on' => now()->addDays((int) config('audira.billing.payment_terms_days'))->toDateString(),
                    'issued_at' => now(), 'gateway' => $this->gateways->driver()->name(),
                ]);
                $inv->firm_id = $firm->id;
                $inv->save();
                $this->logger->log('invoice.issued', "Invoice {$inv->number} issued (RM ".amount($inv->total_sen, false).')', $inv, ['total_sen' => $inv->total_sen], $actor, $firm->id);

                return $inv;
            });
        });
    }

    public function markPaid(Invoice $inv, string $reference, ?string $method, User $actor): void
    {
        if ($inv->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'Only an issued invoice can be marked paid.']);
        }
        if (blank($reference)) {
            throw ValidationException::withMessages(['payment_reference' => 'Enter the payment reference.']);
        }
        $this->tenant->runAsSystem(function () use ($inv, $reference, $method, $actor) {
            DB::transaction(function () use ($inv, $reference, $method, $actor) {
                $inv->forceFill(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => trim($reference), 'payment_method' => $method ?: 'bank_transfer', 'recorded_by' => $actor->id])->save();
                $sub = $inv->subscription;
                if ($sub && $sub->status === SubscriptionStatus::PastDue && ! $this->hasOverdue($sub)) {
                    $sub->forceFill(['status' => SubscriptionStatus::Active])->save();
                }
                $this->logger->log('invoice.paid', "Invoice {$inv->number} paid ({$reference})", $inv, [], $actor, $inv->firm_id);
            });
        });
    }

    public function void(Invoice $inv, string $reason, User $actor): void
    {
        if ($inv->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'Only an issued invoice can be voided.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['void_reason' => 'Give the reason for voiding.']);
        }
        $this->tenant->runAsSystem(function () use ($inv, $reason, $actor) {
            $inv->forceFill(['status' => 'void', 'void_reason' => trim($reason), 'recorded_by' => $actor->id])->save();
            $this->logger->log('invoice.voided', "Invoice {$inv->number} voided", $inv, ['reason' => trim($reason)], $actor, $inv->firm_id);
        });
    }

    /**
     * Daily billing run: invoice every active subscription whose period has ended (and roll the
     * period forward), then mark subscriptions with invoices overdue beyond the grace period as past_due.
     *
     * @return array{issued: int, past_due: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();

        return $this->tenant->runAsSystem(function () use ($now) {
            $issued = 0;
            $subs = Subscription::with('plan')->where('status', SubscriptionStatus::Active->value)->where('current_period_end', '<=', $now)->get();
            foreach ($subs as $sub) {
                if (Firm::find($sub->firm_id)?->status !== FirmStatus::Active) {
                    continue;
                }
                $start = Carbon::parse($sub->current_period_start)->startOfDay();
                $end = Carbon::parse($sub->current_period_end)->startOfDay();
                $this->issue($sub, $start, $end->copy()->subDay());
                $sub->forceFill(['current_period_start' => $end, 'current_period_end' => $end->copy()->addMonth()])->save();
                $issued++;
            }
            $pastDue = 0;
            $grace = (int) config('audira.billing.grace_days');
            $late = Invoice::where('status', 'issued')->whereDate('due_on', '<', $now->copy()->subDays($grace)->toDateString())->pluck('subscription_id')->filter()->unique();
            foreach (Subscription::whereIn('id', $late)->where('status', SubscriptionStatus::Active->value)->get() as $sub) {
                $sub->forceFill(['status' => SubscriptionStatus::PastDue])->save();
                $this->logger->log('subscription.past_due', 'Subscription past due: invoice unpaid beyond the grace period', Firm::find($sub->firm_id), [], null, $sub->firm_id);
                $pastDue++;
            }

            return ['issued' => $issued, 'past_due' => $pastDue];
        });
    }

    private function hasOverdue(Subscription $sub): bool
    {
        $grace = (int) config('audira.billing.grace_days');

        return Invoice::where('subscription_id', $sub->id)->where('status', 'issued')->whereDate('due_on', '<', now()->subDays($grace)->toDateString())->exists();
    }
}
