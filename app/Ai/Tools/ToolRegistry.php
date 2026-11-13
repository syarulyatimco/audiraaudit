<?php

namespace App\Ai\Tools;

use InvalidArgumentException;

class ToolRegistry
{
    /** @var array<string, class-string<Tool>> */
    private const TOOLS = [
        'tb_list_accounts' => TbListAccountsTool::class,
        'fsli_list' => FsliListTool::class,
        'mapping_history' => MappingHistoryTool::class,
        'tb_analytics' => TbAnalyticsTool::class,
        'fs_benchmarks' => FsBenchmarksTool::class,
        'entity_profile' => EntityProfileTool::class,
        'materiality_current' => MaterialityCurrentTool::class,
        'risks_list' => RisksListTool::class,
        'library_search' => LibrarySearchTool::class,
        'procedures_list' => ProceduresListTool::class,
        'population_summary' => PopulationSummaryTool::class,
        'pbc_list' => PbcListTool::class,
        'engagement_overview' => EngagementOverviewTool::class,
        'document_text' => DocumentTextTool::class,
        'sample_evidence' => SampleEvidenceTool::class,
        'sample_exceptions' => SampleExceptionsTool::class,
        'findings_list' => FindingsListTool::class,
        'completion_checklist' => CompletionChecklistTool::class,
        'review_status' => ReviewStatusTool::class,
        'engagement_health' => EngagementHealthTool::class,
    ];

    public function get(string $name): Tool
    {
        $class = self::TOOLS[$name] ?? throw new InvalidArgumentException("Unknown tool {$name}");

        return app($class);
    }

    /** @param list<string> $names @return list<Tool> */
    public function many(array $names): array
    {
        return array_map(fn ($n) => $this->get($n), $names);
    }
}
