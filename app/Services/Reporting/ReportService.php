<?php

namespace App\Services\Reporting;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\AuditOpinion;
use App\Enums\EngagementStatus;
use App\Enums\SignificanceStatus;
use App\Models\Approval;
use App\Models\AuditReport;
use App\Models\Engagement;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Completion\CompletionService;
use App\Support\Audit\ActivityLogger;
use App\Support\Authorization\Permissions;
use App\Support\EngagementGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Auditor's report (ISA 700 series). The team proposes the opinion; the engagement partner decides
 * it (audit_opinion approval, re-authenticated) and then signs the report (report_signoff approval,
 * re-authenticated). Sign-off freezes the report and an FS snapshot and moves the engagement to
 * "signed off". Report wording comes from the FIRM's templates — AUDIRA ships no standard text.
 */
class ReportService
{
    public const PLACEHOLDERS = ['entity_name', 'registration_no', 'period_end', 'period_start', 'framework', 'firm_name', 'firm_number', 'partner_name', 'report_date',
        'opinion', 'basis_for_modification', 'going_concern_paragraph', 'emphasis_of_matter', 'other_matter'];

    public function __construct(private ApprovalService $approvals, private ActivityLogger $logger, private CompletionService $completion, private FinancialStatements $fs) {}

    public function report(Engagement $engagement): AuditReport
    {
        return $engagement->auditReport ?? AuditReport::firstOrCreate(['engagement_id' => $engagement->id]);
    }

    public function save(AuditReport $r, array $data, User $actor): void
    {
        $e = $r->engagement;
        $this->assertDraftEditable($r, $actor);
        $opinion = filled($data['opinion'] ?? null) ? (AuditOpinion::tryFrom($data['opinion']) ?? throw ValidationException::withMessages(['opinion' => 'Unknown opinion.'])) : null;
        $errors = [];
        $basis = $this->clean($data['basis_for_modification'] ?? null);
        if ($opinion && $opinion !== AuditOpinion::Unmodified && ! $basis) {
            $errors['basis_for_modification'] = 'A modified opinion needs the basis for modification.';
        }
        $gc = filter_var($data['going_concern_uncertainty'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $gcPara = $this->clean($data['going_concern_paragraph'] ?? null);
        if ($gc && ! $gcPara) {
            $errors['going_concern_paragraph'] = 'Describe the material uncertainty related to going concern.';
        }
        $date = null;
        if (filled($data['report_date'] ?? null)) {
            try {
                $date = Carbon::parse($data['report_date'])->startOfDay();
            } catch (\Throwable) {
                $errors['report_date'] = 'Enter a valid date.';
            }
            if ($date && $e->period_end && $date->lte($e->period_end)) {
                $errors['report_date'] = 'The report date must be after the period end.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $before = $r->opinionHash();
        $bodyBefore = $r->bodyHash();
        $r->fill([
            'opinion' => $opinion, 'basis_for_modification' => $opinion === AuditOpinion::Unmodified ? null : $basis,
            'going_concern_uncertainty' => $gc, 'going_concern_paragraph' => $gc ? $gcPara : null,
            'emphasis_of_matter' => $this->clean($data['emphasis_of_matter'] ?? null), 'other_matter' => $this->clean($data['other_matter'] ?? null),
            'report_date' => $date, 'updated_by' => $actor->id,
        ]);
        if (array_key_exists('body', $data)) {
            $r->body = $this->clean($data['body']);
        }
        if ($r->opinionHash() !== $before && $r->opinion_status !== null) {
            $r->opinion_status = null; // the opinion changed after the partner's decision
            $r->signoff_status = null;
        }
        if ($r->bodyHash() !== $bodyBefore && $r->signoff_status !== null) {
            $r->signoff_status = null;
        }
        $r->save();
        $this->logger->log('report.saved', 'Audit report draft saved', $r, ['opinion' => $opinion?->value], $actor);
    }

    /** Fill the firm's template for the chosen opinion (or a headings-only skeleton) into the body. */
    public function generate(AuditReport $r, User $actor): void
    {
        $this->assertDraftEditable($r, $actor);
        if (! $r->opinion) {
            throw ValidationException::withMessages(['opinion' => 'Choose the proposed opinion first.']);
        }
        $template = ReportTemplate::where('firm_id', $r->firm_id)->where('opinion', $r->opinion->value)->value('body') ?? $this->skeleton($r->opinion);
        $this->save($r, ['body' => $this->fill($template, $r)] + $this->current($r), $actor);
    }

    public function fill(string $template, AuditReport $r): string
    {
        $e = $r->engagement->loadMissing(['client', 'firm']);
        $values = [
            'entity_name' => $e->client->name, 'registration_no' => $e->client->registration_no ?? '',
            'period_end' => $e->period_end?->format('j F Y') ?? '', 'period_start' => $e->period_start?->format('j F Y') ?? '',
            'framework' => $e->reporting_framework?->label() ?? '', 'firm_name' => $e->firm->name, 'firm_number' => $e->firm->audit_firm_no ?? '',
            'partner_name' => $e->partner()?->name ?? '', 'report_date' => $r->report_date?->format('j F Y') ?? '[report date]',
            'opinion' => $r->opinion?->label() ?? '', 'basis_for_modification' => $r->basis_for_modification ?? '',
            'going_concern_paragraph' => $r->going_concern_paragraph ?? '', 'emphasis_of_matter' => $r->emphasis_of_matter ?? '', 'other_matter' => $r->other_matter ?? '',
        ];

        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => array_key_exists($m[1], $values) ? $values[$m[1]] : $m[0], $template);

        return trim(preg_replace("/\n{3,}/", "\n\n", $out));
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    public function opinionGates(AuditReport $r): array
    {
        $c = $r->engagement->completion;

        return [
            'completion' => ['ok' => $c?->completion_status === SignificanceStatus::Approved, 'detail' => 'Completion approved by the engagement partner'],
            'opinion' => ['ok' => $r->opinion !== null, 'detail' => 'Proposed opinion recorded'],
        ];
    }

    /** @return array<string, array{ok: bool, detail: string}> */
    public function signoffGates(AuditReport $r): array
    {
        $c = $r->engagement->completion;
        $eqrDate = $c?->eqr_completed_at?->copy()->startOfDay();
        $fs = $this->fs->build($r->engagement);

        return [
            'opinion' => ['ok' => $r->opinion_status === SignificanceStatus::Approved, 'detail' => 'Opinion decided by the engagement partner'],
            'body' => ['ok' => filled($r->body) && ! preg_match('/\{\{\s*[a-z_]+\s*\}\}|\[(insert|report date)/i', $r->body), 'detail' => 'Report text complete (no unfilled placeholders)'],
            'report_date' => ['ok' => $r->report_date !== null && (! $eqrDate || $r->report_date->gte($eqrDate)), 'detail' => 'Report dated, not before the engagement quality review was completed'],
            'fs' => ['ok' => $fs['balanced'], 'detail' => 'Financial statements balance and are fully mapped'],
        ];
    }

    public function requestOpinion(AuditReport $r, User $actor, ?string $comment): Approval
    {
        return $this->request($r, ApprovalType::AuditOpinion, 'opinion_status', $this->opinionGates($r), $actor, $comment, [
            'opinion' => $r->opinion?->value, 'basis_for_modification' => $r->basis_for_modification, 'going_concern_uncertainty' => $r->going_concern_uncertainty,
            'emphasis_of_matter' => $r->emphasis_of_matter, 'other_matter' => $r->other_matter, 'opinion_sha256' => $r->opinionHash(),
        ]);
    }

    public function requestSignoff(AuditReport $r, User $actor, ?string $comment): Approval
    {
        return $this->request($r, ApprovalType::ReportSignoff, 'signoff_status', $this->signoffGates($r), $actor, $comment, [
            'opinion' => $r->opinion?->value, 'report_date' => $r->report_date?->toDateString(), 'body_sha256' => $r->bodyHash(), 'opinion_sha256' => $r->opinionHash(),
        ]);
    }

    public function applyDecision(AuditReport $r, Approval $approval): void
    {
        $approved = $approval->status === ApprovalStatus::Approved;
        $status = match ($approval->status) {
            ApprovalStatus::Approved => SignificanceStatus::Approved,
            ApprovalStatus::Rejected => SignificanceStatus::Rejected,
            default => null,
        };
        if ($approval->type === ApprovalType::AuditOpinion) {
            if ($approved && ($approval->snapshot['opinion_sha256'] ?? null) !== $r->opinionHash()) {
                throw ValidationException::withMessages(['approval' => 'The proposed opinion changed after this request was made.']);
            }
            $r->forceFill(['opinion_status' => $status])->save();

            return;
        }
        if (! $approved) {
            $r->forceFill(['signoff_status' => $status])->save();

            return;
        }
        if (($approval->snapshot['body_sha256'] ?? null) !== $r->bodyHash() || $r->opinion_status !== SignificanceStatus::Approved) {
            throw ValidationException::withMessages(['approval' => 'The report changed after sign-off was requested.']);
        }
        $e = $r->engagement;
        $fs = $this->fs->build($e);
        $r->forceFill([
            'signoff_status' => SignificanceStatus::Approved, 'status' => 'signed', 'signed_by' => $approval->decided_by, 'signed_at' => now(),
            'body_sha256' => $r->bodyHash(), 'fs_snapshot' => $fs,
        ])->save();
        $from = $e->status;
        $e->forceFill(['status' => EngagementStatus::SignedOff])->save();
        $this->logger->log('engagement.signed_off', "Audit report signed ({$r->opinion->label()}); engagement locked", $e, ['from' => $from->value, 'report' => $r->uuid, 'body_sha256' => $r->body_sha256], User::find($approval->decided_by));
    }

    private function request(AuditReport $r, ApprovalType $type, string $col, array $gates, User $actor, ?string $comment, array $snapshot): Approval
    {
        $e = $r->engagement;
        EngagementGuard::assertEditable($e);
        $this->authorise($actor, $e);
        if (in_array($r->{$col}, [SignificanceStatus::Pending, SignificanceStatus::Approved], true)) {
            throw ValidationException::withMessages(['approval' => 'Already approved or awaiting approval.']);
        }
        if ($type === ApprovalType::ReportSignoff && $r->opinion_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['approval' => 'The opinion is awaiting a decision.']);
        }
        $failed = collect($gates)->reject(fn ($g) => $g['ok'])->pluck('detail')->all();
        if ($failed) {
            throw ValidationException::withMessages(['approval' => 'Not ready: '.implode('; ', $failed).'.']);
        }

        return DB::transaction(function () use ($r, $type, $col, $actor, $comment, $snapshot) {
            $approval = $this->approvals->request($r, $type, $actor, $comment, $snapshot);
            $r->forceFill([$col => SignificanceStatus::Pending])->save();

            return $approval;
        });
    }

    private function assertDraftEditable(AuditReport $r, User $actor): void
    {
        EngagementGuard::assertEditable($r->engagement);
        $this->authorise($actor, $r->engagement);
        if ($r->isSigned()) {
            throw ValidationException::withMessages(['report' => 'The report is signed.']);
        }
        if ($r->opinion_status === SignificanceStatus::Pending || $r->signoff_status === SignificanceStatus::Pending) {
            throw ValidationException::withMessages(['report' => 'The report is awaiting the engagement partner.']);
        }
    }

    /** @return array<string, mixed> */
    private function current(AuditReport $r): array
    {
        return ['opinion' => $r->opinion?->value, 'basis_for_modification' => $r->basis_for_modification, 'going_concern_uncertainty' => $r->going_concern_uncertainty,
            'going_concern_paragraph' => $r->going_concern_paragraph, 'emphasis_of_matter' => $r->emphasis_of_matter, 'other_matter' => $r->other_matter,
            'report_date' => $r->report_date?->toDateString()];
    }

    /** Headings and placeholders only — the firm supplies the approved wording (no standard text is shipped). */
    private function skeleton(AuditOpinion $opinion): string
    {
        $sections = ["INDEPENDENT AUDITORS' REPORT TO THE MEMBERS OF {{entity_name}} ({{registration_no}})", 'Report on the Audit of the Financial Statements', $opinion->label(),
            "[Insert your firm's approved {$opinion->label()} wording for the financial statements for the period ended {{period_end}} prepared under {{framework}}.]"];
        if ($opinion !== AuditOpinion::Unmodified) {
            $sections[] = 'Basis for '.mb_strtolower($opinion->label());
            $sections[] = '{{basis_for_modification}}';
        }
        array_push($sections, 'Basis for opinion', "[Insert your firm's approved wording.]");
        $sections[] = '{{going_concern_paragraph}}';
        $sections[] = '{{emphasis_of_matter}}';
        $sections[] = '{{other_matter}}';
        array_push($sections, 'Responsibilities of the directors for the financial statements', "[Insert your firm's approved wording.]",
            "Auditors' responsibilities for the audit of the financial statements", "[Insert your firm's approved wording.]",
            'Report on other legal and regulatory requirements', "[Insert your firm's approved wording.]",
            '{{firm_name}} ({{firm_number}})', 'Chartered Accountants', '{{partner_name}}', '{{report_date}}');

        return implode("\n\n", $sections);
    }

    private function clean(?string $v): ?string
    {
        return filled($v) ? trim($v) : null;
    }

    private function authorise(User $actor, Engagement $e): void
    {
        if (! Permissions::allowsOnEngagement($actor, $e, 'engagement.prepare_report')) {
            throw new AuthorizationException('Your role on this engagement does not allow preparing the report.');
        }
    }
}
