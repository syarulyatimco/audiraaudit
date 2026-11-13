<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Engagement;
use App\Models\Firm;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(\App\Services\Documents\Scanning\MalwareScanner::class, fn () => match (config('audira.scanner.driver')) {
            'clamd' => new \App\Services\Documents\Scanning\ClamdScanner(config('audira.scanner.clamd_host'), config('audira.scanner.clamd_port'), config('audira.scanner.timeout')),
            default => new \App\Services\Documents\Scanning\BasicSignatureScanner,
        });
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'firm' => Firm::class,
            'user' => User::class,
            'client' => Client::class,
            'client_contact' => ClientContact::class,
            'engagement' => Engagement::class,
            'invitation' => Invitation::class,
            'plan' => \App\Models\Plan::class,
            'document' => \App\Models\Document::class,
            'document_version' => \App\Models\DocumentVersion::class,
            'working_paper' => \App\Models\WorkingPaper::class,
            'review_note' => \App\Models\ReviewNote::class,
            'library_framework' => \App\Models\LibraryFramework::class,
            'library_standard' => \App\Models\LibraryStandard::class,
            'library_version' => \App\Models\LibraryVersion::class,
            'tb_import' => \App\Models\TbImport::class,
            'ai_run' => \App\Models\AiRun::class,
            'materiality' => \App\Models\MaterialityAssessment::class,
            'risk' => \App\Models\Risk::class,
            'audit_strategy' => \App\Models\AuditStrategy::class,
            'procedure' => \App\Models\Procedure::class,
            'sampling_plan' => \App\Models\SamplingPlan::class,
            'pbc_item' => \App\Models\PbcItem::class,
            'engagement_message' => \App\Models\EngagementMessage::class,
            'portal_invitation' => \App\Models\PortalInvitation::class,
            'adjustment' => \App\Models\Adjustment::class,
            'engagement_fsli' => \App\Models\EngagementFsli::class,
            'finding' => \App\Models\Finding::class,
            'completion_item' => \App\Models\CompletionItem::class,
            'engagement_completion' => \App\Models\EngagementCompletion::class,
            'audit_report' => \App\Models\AuditReport::class,
            'report_template' => \App\Models\ReportTemplate::class,
            'invoice' => \App\Models\Invoice::class,
            'support_access_grant' => \App\Models\SupportAccessGrant::class,
        ]);

        // Every firm-level permission in config/audira.php is a Gate ability.
        foreach (array_keys(config('audira.permissions')) as $permission) {
            Gate::define($permission, fn (User $user) => Permissions::allows($user, $permission));
        }

        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('registration', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
    }
}
