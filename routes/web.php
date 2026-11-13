<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\MfaController;
use App\Http\Controllers\Admin\LibraryAdminController;
use App\Http\Controllers\Ai\AiRunController;
use App\Http\Controllers\Clients\ClientPortalController;
use App\Http\Controllers\Engagements\ClientRequestController;
use App\Http\Controllers\Fieldwork\ProgrammeController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalInvitationController;
use App\Http\Controllers\Fieldwork\SamplingController;
use App\Http\Controllers\Fieldwork\FindingController;
use App\Http\Controllers\Completion\CompletionController;
use App\Http\Controllers\Completion\ReportingController;
use App\Http\Controllers\Completion\ReportTemplateController;
use App\Http\Controllers\Planning\MaterialityController;
use App\Http\Controllers\Planning\RiskController;
use App\Http\Controllers\Planning\StrategyController;
use App\Http\Controllers\Ai\AiSettingsController;
use App\Http\Controllers\Documents\DocumentController;
use App\Http\Controllers\Library\LibraryController;
use App\Http\Controllers\WorkingPapers\EntityUnderstandingController;
use App\Http\Controllers\WorkingPapers\ReviewNoteController;
use App\Http\Controllers\WorkingPapers\WorkingPaperController;
use App\Http\Controllers\WorkingPapers\WorkingPaperLinkController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\FirmAdminController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Approvals\ApprovalController;
use App\Http\Controllers\Auth\InvitationAcceptController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Clients\ClientAcceptanceController;
use App\Http\Controllers\Clients\ClientContactController;
use App\Http\Controllers\Clients\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Engagements\EngagementController;
use App\Http\Controllers\Engagements\EngagementTeamController;
use App\Http\Controllers\Firm\ActivityLogController;
use App\Http\Controllers\Firm\BillingController;
use App\Http\Controllers\Firm\FirmProfileController;
use App\Http\Controllers\TrialBalance\AdjustmentController;
use App\Http\Controllers\TrialBalance\LeadScheduleController;
use App\Http\Controllers\TrialBalance\MappingController;
use App\Http\Controllers\TrialBalance\TrialBalanceController;
use App\Http\Controllers\Users\UserController;
use Illuminate\Support\Facades\Route;

// ---- Guest ------------------------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:registration');
    Route::get('/invitations/{token}', [InvitationAcceptController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationAcceptController::class, 'store'])->middleware('throttle:login')->name('invitations.accept');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [MfaController::class, 'verify'])->middleware('throttle:mfa')->name('mfa.verify');
});

// Account pages: MFA must be verified if enabled, but setup itself happens here.
Route::middleware(['auth', 'mfa:challenge'])->group(function () {
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::get('/account/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/account/mfa/enable', [MfaController::class, 'enable'])->middleware('throttle:mfa')->name('mfa.enable');
    Route::post('/account/mfa/recovery-codes', [MfaController::class, 'regenerate'])->name('mfa.recovery');
    Route::delete('/account/mfa', [MfaController::class, 'disable'])->name('mfa.disable');
});

// ---- Audit firm workspace ---------------------------------------------------------------------
Route::middleware(['auth', 'mfa', 'firm.active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/firm', [FirmProfileController::class, 'edit'])->name('firm.edit');
    Route::put('/firm', [FirmProfileController::class, 'update'])->name('firm.update');
    Route::get('/firm/billing', BillingController::class)->name('firm.billing');
    Route::get('/firm/activity', ActivityLogController::class)->name('firm.activity');
    Route::get('/firm/ai', [AiSettingsController::class, 'edit'])->name('firm.ai');
    Route::put('/firm/ai', [AiSettingsController::class, 'update'])->name('firm.ai.update');
    Route::get('/firm/invoices/{invoice}', [\App\Http\Controllers\Firm\InvoiceController::class, 'show'])->name('firm.invoices.show');
    Route::get('/firm/support', [\App\Http\Controllers\Firm\SupportAccessController::class, 'index'])->name('firm.support');
    Route::post('/firm/support', [\App\Http\Controllers\Firm\SupportAccessController::class, 'store'])->name('firm.support.store');
    Route::post('/firm/support/{grant}/revoke', [\App\Http\Controllers\Firm\SupportAccessController::class, 'revoke'])->name('firm.support.revoke');
    Route::get('/firm/report-templates', [ReportTemplateController::class, 'index'])->name('report-templates.index');
    Route::put('/firm/report-templates/{opinion}', [ReportTemplateController::class, 'update'])->name('report-templates.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/invitations', [UserController::class, 'invite'])->name('users.invite');
    Route::delete('/users/invitations/{invitation}', [UserController::class, 'revokeInvitation'])->name('users.invitations.revoke');
    Route::put('/users/{user:uuid}/role', [UserController::class, 'updateRole'])->name('users.role');
    Route::post('/users/{user:uuid}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user:uuid}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');

    Route::resource('clients', ClientController::class);
    Route::post('/clients/{client}/acceptance', [ClientAcceptanceController::class, 'store'])->name('clients.acceptance');
    Route::post('/clients/{client}/contacts', [ClientContactController::class, 'store'])->name('clients.contacts.store');
    Route::delete('/clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy'])->scopeBindings()->name('clients.contacts.destroy');

    Route::resource('engagements', EngagementController::class)->except('destroy');
    Route::put('/engagements/{engagement}/status', [EngagementController::class, 'changeStatus'])->name('engagements.status');
    Route::post('/engagements/{engagement}/members', [EngagementTeamController::class, 'store'])->name('engagements.members.store');
    Route::delete('/engagements/{engagement}/members/{member}', [EngagementTeamController::class, 'destroy'])->scopeBindings()->name('engagements.members.destroy');
    Route::put('/engagements/{engagement}/partner', [EngagementTeamController::class, 'replacePartner'])->name('engagements.partner');

    // Entity understanding, working papers, documents (Phase 2)
    Route::prefix('/engagements/{engagement}')->scopeBindings()->group(function () {
        Route::get('/understanding', [EntityUnderstandingController::class, 'show'])->name('entity-understanding.show');
        Route::put('/understanding', [EntityUnderstandingController::class, 'update'])->name('entity-understanding.update');

        Route::get('/working-papers', [WorkingPaperController::class, 'index'])->name('working-papers.index');
        Route::get('/working-papers/create', [WorkingPaperController::class, 'create'])->name('working-papers.create');
        Route::post('/working-papers', [WorkingPaperController::class, 'store'])->name('working-papers.store');
        Route::get('/working-papers/{workingPaper}', [WorkingPaperController::class, 'show'])->name('working-papers.show');
        Route::put('/working-papers/{workingPaper}', [WorkingPaperController::class, 'update'])->name('working-papers.update');
        Route::post('/working-papers/{workingPaper}/signoff', [WorkingPaperController::class, 'signoff'])->name('working-papers.signoff');
        Route::post('/working-papers/{workingPaper}/notes', [ReviewNoteController::class, 'store'])->name('working-papers.notes.store');
        Route::post('/working-papers/{workingPaper}/notes/{reviewNote}/respond', [ReviewNoteController::class, 'respond'])->name('working-papers.notes.respond');
        Route::post('/working-papers/{workingPaper}/notes/{reviewNote}/clear', [ReviewNoteController::class, 'clear'])->name('working-papers.notes.clear');
        Route::post('/working-papers/{workingPaper}/documents', [WorkingPaperLinkController::class, 'linkDocument'])->name('working-papers.documents.link');
        Route::delete('/working-papers/{workingPaper}/documents/{document}', [WorkingPaperLinkController::class, 'unlinkDocument'])->name('working-papers.documents.unlink');
        Route::post('/working-papers/{workingPaper}/citations', [WorkingPaperLinkController::class, 'addCitation'])->name('working-papers.citations.store');
        Route::delete('/working-papers/{workingPaper}/citations/{citation}', [WorkingPaperLinkController::class, 'removeCitation'])->name('working-papers.citations.destroy');

        // Trial balance, mapping, lead schedules, adjustments (Phase 3)
        Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->name('trial-balance.index');
        Route::post('/trial-balance', [TrialBalanceController::class, 'upload'])->middleware('throttle:uploads')->name('trial-balance.upload');
        Route::get('/trial-balance/export', [TrialBalanceController::class, 'export'])->name('trial-balance.export');
        Route::get('/trial-balance/imports/{tbImport}', [TrialBalanceController::class, 'configure'])->name('trial-balance.configure');
        Route::post('/trial-balance/imports/{tbImport}', [TrialBalanceController::class, 'activate'])->name('trial-balance.activate');
        Route::post('/trial-balance/imports/{tbImport}/discard', [TrialBalanceController::class, 'discard'])->name('trial-balance.discard');
        Route::get('/mapping', [MappingController::class, 'index'])->name('trial-balance.mapping');
        Route::put('/mapping', [MappingController::class, 'update'])->name('trial-balance.mapping.update');
        Route::post('/mapping/accept', [MappingController::class, 'accept'])->name('trial-balance.mapping.accept');
        Route::post('/fslis', [MappingController::class, 'storeFsli'])->name('fslis.store');
        Route::put('/fslis/{fsli}', [MappingController::class, 'updateFsli'])->name('fslis.update');
        Route::delete('/fslis/{fsli}', [MappingController::class, 'destroyFsli'])->name('fslis.destroy');
        Route::get('/lead-schedules', [LeadScheduleController::class, 'index'])->name('lead-schedules.index');
        Route::get('/lead-schedules/{fsli}', [LeadScheduleController::class, 'show'])->name('lead-schedules.show');
        Route::post('/lead-schedules/{fsli}/paper', [LeadScheduleController::class, 'createPaper'])->name('lead-schedules.paper');
        Route::get('/adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');
        Route::get('/adjustments/create', [AdjustmentController::class, 'create'])->name('adjustments.create');
        Route::post('/adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store');
        Route::get('/adjustments/{adjustment}', [AdjustmentController::class, 'show'])->name('adjustments.show');
        Route::get('/adjustments/{adjustment}/edit', [AdjustmentController::class, 'edit'])->name('adjustments.edit');
        Route::put('/adjustments/{adjustment}', [AdjustmentController::class, 'update'])->name('adjustments.update');
        Route::post('/adjustments/{adjustment}/post', [AdjustmentController::class, 'post'])->name('adjustments.post');
        Route::post('/adjustments/{adjustment}/void', [AdjustmentController::class, 'void'])->name('adjustments.void');
        Route::post('/adjustments/{adjustment}/approval', [AdjustmentController::class, 'requestApproval'])->name('adjustments.approval');

        // Planning: materiality, risk assessment, audit strategy (Phase 5)
        Route::get('/planning', fn (\App\Models\Engagement $engagement) => redirect()->route('materiality.index', $engagement))->name('planning.index');
        Route::get('/planning/materiality', [MaterialityController::class, 'index'])->name('materiality.index');
        Route::put('/planning/materiality', [MaterialityController::class, 'save'])->name('materiality.save');
        Route::post('/planning/materiality/revise', [MaterialityController::class, 'revise'])->name('materiality.revise');
        Route::post('/planning/materiality/{materiality}/submit', [MaterialityController::class, 'submit'])->name('materiality.submit');
        Route::delete('/planning/materiality/{materiality}', [MaterialityController::class, 'discard'])->name('materiality.discard');
        Route::get('/planning/risks', [RiskController::class, 'index'])->name('risks.index');
        Route::get('/planning/risks/create', [RiskController::class, 'create'])->name('risks.create');
        Route::post('/planning/risks', [RiskController::class, 'store'])->name('risks.store');
        Route::post('/planning/risks/presumed', [RiskController::class, 'presumed'])->name('risks.presumed');
        Route::get('/planning/risks/{risk}', [RiskController::class, 'show'])->name('risks.show');
        Route::get('/planning/risks/{risk}/edit', [RiskController::class, 'edit'])->name('risks.edit');
        Route::put('/planning/risks/{risk}', [RiskController::class, 'update'])->name('risks.update');
        Route::post('/planning/risks/{risk}/submit', [RiskController::class, 'submit'])->name('risks.submit');
        Route::post('/planning/risks/{risk}/withdraw', [RiskController::class, 'withdraw'])->name('risks.withdraw');
        Route::post('/planning/risks/{risk}/rebut', [RiskController::class, 'rebut'])->name('risks.rebut');
        Route::get('/planning/strategy', [StrategyController::class, 'show'])->name('strategy.show');
        Route::put('/planning/strategy', [StrategyController::class, 'update'])->name('strategy.update');

        // Fieldwork: audit programme and sampling (Phase 6)
        Route::get('/programme', [ProgrammeController::class, 'index'])->name('programme.index');
        Route::get('/programme/create', [ProgrammeController::class, 'create'])->name('programme.create');
        Route::post('/programme', [ProgrammeController::class, 'store'])->name('programme.store');
        Route::get('/programme/{procedure}', [ProgrammeController::class, 'show'])->name('programme.show');
        Route::get('/programme/{procedure}/edit', [ProgrammeController::class, 'edit'])->name('programme.edit');
        Route::put('/programme/{procedure}', [ProgrammeController::class, 'update'])->name('programme.update');
        Route::post('/programme/{procedure}/progress', [ProgrammeController::class, 'progress'])->name('programme.progress');
        Route::post('/programme/{procedure}/withdraw', [ProgrammeController::class, 'withdraw'])->name('programme.withdraw');
        Route::get('/sampling', [SamplingController::class, 'index'])->name('sampling.index');
        Route::post('/sampling', [SamplingController::class, 'store'])->name('sampling.store');
        Route::get('/sampling/{plan}', [SamplingController::class, 'show'])->name('sampling.show');
        Route::post('/sampling/{plan}/population', [SamplingController::class, 'population'])->middleware('throttle:uploads')->name('sampling.population');
        Route::put('/sampling/{plan}/parameters', [SamplingController::class, 'parameters'])->name('sampling.parameters');
        Route::post('/sampling/{plan}/submit', [SamplingController::class, 'submit'])->name('sampling.submit');
        Route::post('/sampling/{plan}/select', [SamplingController::class, 'select'])->name('sampling.select');
        Route::post('/sampling/{plan}/items/{item}', [SamplingController::class, 'result'])->name('sampling.result');
        Route::post('/sampling/{plan}/evaluate', [SamplingController::class, 'evaluate'])->name('sampling.evaluate');
        Route::post('/programme/{procedure}/paper', [ProgrammeController::class, 'paper'])->name('programme.paper');

        // Testing, findings and audit differences (Phase 8)
        Route::post('/sampling/{plan}/findings', [FindingController::class, 'fromSample'])->name('findings.from-sample');
        Route::get('/findings', [FindingController::class, 'index'])->name('findings.index');
        Route::get('/findings/create', [FindingController::class, 'create'])->name('findings.create');
        Route::post('/findings', [FindingController::class, 'store'])->name('findings.store');
        Route::get('/findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
        Route::get('/findings/{finding}/edit', [FindingController::class, 'edit'])->name('findings.edit');
        Route::put('/findings/{finding}', [FindingController::class, 'update'])->name('findings.update');
        Route::post('/findings/{finding}/correction', [FindingController::class, 'correction'])->name('findings.correction');
        Route::post('/findings/{finding}/submit', [FindingController::class, 'submit'])->name('findings.submit');
        Route::post('/findings/{finding}/resolve', [FindingController::class, 'resolve'])->name('findings.resolve');
        Route::post('/findings/{finding}/withdraw', [FindingController::class, 'withdraw'])->name('findings.withdraw');
        Route::get('/audit-differences', [FindingController::class, 'differences'])->name('findings.differences');

        // Completion, financial statements and reporting (Phase 9)
        Route::get('/completion', [CompletionController::class, 'index'])->name('completion.index');
        Route::post('/completion/items/{completionItem}', [CompletionController::class, 'item'])->name('completion.item');
        Route::put('/completion/conclusions', [CompletionController::class, 'conclusions'])->name('completion.conclusions');
        Route::post('/completion/submit/{stage}', [CompletionController::class, 'submit'])->whereIn('stage', ['conclusions', 'completion'])->name('completion.submit');
        Route::post('/completion/eqr', [CompletionController::class, 'eqr'])->name('completion.eqr');
        Route::get('/financial-statements', [ReportingController::class, 'statements'])->name('reporting.statements');
        Route::get('/audit-report', [ReportingController::class, 'report'])->name('reporting.report');
        Route::put('/audit-report', [ReportingController::class, 'save'])->name('reporting.save');
        Route::post('/audit-report/submit/{stage}', [ReportingController::class, 'submit'])->whereIn('stage', ['opinion', 'signoff'])->name('reporting.submit');

        // Client requests (PBC) and correspondence (Phase 7)
        Route::get('/client-requests', [ClientRequestController::class, 'index'])->name('client-requests.index');
        Route::post('/client-requests', [ClientRequestController::class, 'store'])->name('client-requests.store');
        Route::post('/client-requests/send', [ClientRequestController::class, 'send'])->name('client-requests.send');
        Route::post('/client-requests/{item}/review', [ClientRequestController::class, 'review'])->name('client-requests.review');
        Route::post('/messages', [ClientRequestController::class, 'draftMessage'])->name('messages.store');
        Route::put('/messages/{message}', [ClientRequestController::class, 'updateMessage'])->name('messages.update');

        // AI agent runs (Phase 4)
        Route::get('/ai-runs', [AiRunController::class, 'index'])->name('ai-runs.index');
        Route::post('/ai-runs', [AiRunController::class, 'store'])->middleware('throttle:ai')->name('ai-runs.store');
        Route::get('/ai-runs/{aiRun}', [AiRunController::class, 'show'])->name('ai-runs.show');
        Route::post('/ai-runs/{aiRun}/accept-mappings', [AiRunController::class, 'acceptMappings'])->name('ai-runs.accept-mappings');
        Route::post('/ai-runs/{aiRun}/accept-materiality', [AiRunController::class, 'acceptMateriality'])->name('ai-runs.accept-materiality');
        Route::post('/ai-runs/{aiRun}/accept-risks', [AiRunController::class, 'acceptRisks'])->name('ai-runs.accept-risks');
        Route::post('/ai-runs/{aiRun}/accept-procedures', [AiRunController::class, 'acceptProcedures'])->name('ai-runs.accept-procedures');
        Route::post('/ai-runs/{aiRun}/accept-sampling', [AiRunController::class, 'acceptSampling'])->name('ai-runs.accept-sampling');
        Route::post('/ai-runs/{aiRun}/accept-message', [AiRunController::class, 'acceptMessage'])->name('ai-runs.accept-message');
        Route::post('/ai-runs/{aiRun}/accept-extraction', [AiRunController::class, 'acceptExtraction'])->name('ai-runs.accept-extraction');
        Route::post('/ai-runs/{aiRun}/accept-test-results', [AiRunController::class, 'acceptTestResults'])->name('ai-runs.accept-test-results');
        Route::post('/ai-runs/{aiRun}/accept-findings', [AiRunController::class, 'acceptFindings'])->name('ai-runs.accept-findings');
        Route::post('/ai-runs/{aiRun}/accept-checklist', [AiRunController::class, 'acceptChecklist'])->name('ai-runs.accept-checklist');
        Route::post('/ai-runs/{aiRun}/accept-review-notes', [AiRunController::class, 'acceptReviewNotes'])->name('ai-runs.accept-review-notes');

        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])->middleware('throttle:uploads')->name('documents.store');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::post('/documents/{document}/versions', [DocumentController::class, 'addVersion'])->middleware('throttle:uploads')->name('documents.versions.store');
        Route::get('/documents/{document}/versions/{version:uuid}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::post('/documents/{document}/archive', [DocumentController::class, 'archive'])->name('documents.archive');
    });

    Route::post('/clients/{client}/portal', [ClientPortalController::class, 'invite'])->name('clients.portal.invite');
    Route::post('/clients/{client}/portal/invitations/{invitation}/revoke', [ClientPortalController::class, 'revoke'])->name('clients.portal.revoke');
    Route::post('/clients/{client}/portal/users/{user}/toggle', [ClientPortalController::class, 'toggle'])->name('clients.portal.toggle');

    Route::get('/library', [LibraryController::class, 'index'])->name('library.index');
    Route::get('/library/search', [LibraryController::class, 'search'])->name('library.search');
    Route::get('/library/standards/{standard}', [LibraryController::class, 'standard'])->name('library.standard');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/{approval}/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
    Route::post('/approvals/{approval}/withdraw', [ApprovalController::class, 'withdraw'])->name('approvals.withdraw');
});

// ---- Superadmin (platform) --------------------------------------------------------------------
Route::middleware(['auth', 'mfa', 'superadmin', 'platform.system'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AdminDashboardController::class)->name('dashboard');
    Route::get('/plans', [PlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [PlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [PlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::get('/firms', [FirmAdminController::class, 'index'])->name('firms.index');
    Route::get('/firms/{firm:uuid}', [FirmAdminController::class, 'show'])->name('firms.show');
    Route::post('/firms/{firm:uuid}/suspend', [FirmAdminController::class, 'suspend'])->name('firms.suspend');
    Route::post('/firms/{firm:uuid}/reactivate', [FirmAdminController::class, 'reactivate'])->name('firms.reactivate');
    Route::put('/firms/{firm:uuid}/plan', [FirmAdminController::class, 'changePlan'])->name('firms.plan');
    Route::post('/firms/{firm:uuid}/invoices', [\App\Http\Controllers\Admin\BillingAdminController::class, 'issue'])->name('firms.invoices.issue');
    Route::get('/invoices', [\App\Http\Controllers\Admin\BillingAdminController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [\App\Http\Controllers\Admin\BillingAdminController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/paid', [\App\Http\Controllers\Admin\BillingAdminController::class, 'paid'])->name('invoices.paid');
    Route::post('/invoices/{invoice}/void', [\App\Http\Controllers\Admin\BillingAdminController::class, 'void'])->name('invoices.void');
    Route::get('/support', [\App\Http\Controllers\Admin\SupportViewController::class, 'index'])->name('support.index');
    Route::get('/support/{grant}', [\App\Http\Controllers\Admin\SupportViewController::class, 'show'])->name('support.show');

    Route::get('/library', [LibraryAdminController::class, 'index'])->name('library.index');
    Route::post('/library/frameworks', [LibraryAdminController::class, 'storeFramework'])->name('library.frameworks.store');
    Route::post('/library/frameworks/{framework}/standards', [LibraryAdminController::class, 'storeStandard'])->name('library.standards.store');
    Route::post('/library/standards/{standard}/versions', [LibraryAdminController::class, 'storeVersion'])->name('library.versions.store');
    Route::get('/library/versions/{version}', [LibraryAdminController::class, 'showVersion'])->name('library.versions.show');
    Route::post('/library/versions/{version}/paragraphs', [LibraryAdminController::class, 'storeParagraph'])->name('library.paragraphs.store');
    Route::put('/library/versions/{version}/paragraphs/{paragraph}', [LibraryAdminController::class, 'updateParagraph'])->name('library.paragraphs.update');
    Route::delete('/library/versions/{version}/paragraphs/{paragraph}', [LibraryAdminController::class, 'destroyParagraph'])->name('library.paragraphs.destroy');
    Route::post('/library/versions/{version}/import', [LibraryAdminController::class, 'import'])->name('library.versions.import');
    Route::post('/library/versions/{version}/source', [LibraryAdminController::class, 'attachSource'])->name('library.versions.source.store');
    Route::get('/library/versions/{version}/source', [LibraryAdminController::class, 'source'])->name('library.versions.source');
    Route::post('/library/versions/{version}/publish', [LibraryAdminController::class, 'publish'])->name('library.versions.publish');
});

// Client portal (Phase 7): free client users, scoped to their own client.
Route::middleware('guest')->group(function () {
    Route::get('/portal/invitation/{token}', [PortalInvitationController::class, 'show'])->name('portal.invitation');
    Route::post('/portal/invitation/{token}', [PortalInvitationController::class, 'accept'])->middleware('throttle:login')->name('portal.invitation.accept');
});
Route::middleware(['auth', 'portal'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/engagements/{engagement}', [PortalController::class, 'engagement'])->name('engagement');
    Route::post('/engagements/{engagement}/requests/{item}', [PortalController::class, 'upload'])->middleware('throttle:uploads')->scopeBindings()->name('upload');
    Route::post('/engagements/{engagement}/messages', [PortalController::class, 'reply'])->middleware('throttle:uploads')->name('reply');
});
