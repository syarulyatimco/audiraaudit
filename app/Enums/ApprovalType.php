<?php

namespace App\Enums;

enum ApprovalType: string
{
    use EnumHelpers;

    case ClientAcceptance = 'client_acceptance';
    case Materiality = 'materiality';
    case SignificantRisk = 'significant_risk';
    case SamplingPlan = 'sampling_plan';
    case MaterialAdjustment = 'material_adjustment';
    case SignificantFinding = 'significant_finding';
    case FinalConclusions = 'final_conclusions';
    case Completion = 'completion';
    case AuditOpinion = 'audit_opinion';
    case ReportSignoff = 'report_signoff';

    public function label(): string
    {
        return match ($this) {
            self::ClientAcceptance => 'Client acceptance',
            self::Materiality => 'Materiality',
            self::SignificantRisk => 'Significant risk',
            self::SamplingPlan => 'Sampling plan',
            self::MaterialAdjustment => 'Material adjustment',
            self::SignificantFinding => 'Significant finding',
            self::FinalConclusions => 'Final audit conclusions',
            self::Completion => 'Completion',
            self::AuditOpinion => 'Audit opinion',
            self::ReportSignoff => 'Audit report sign-off',
        };
    }
}
