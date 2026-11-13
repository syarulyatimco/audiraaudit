<?php

namespace Database\Seeders;

use App\Enums\EngagementRole;
use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Approval;
use App\Models\Plan;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Clients\ClientService;
use App\Services\Engagements\EngagementService;
use App\Services\Firm\FirmRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Local demo workspace, built through the real services (so approvals, audit trail and
 * permissions behave exactly as in production). Refuses to run in production.
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (App::isProduction()) {
            $this->command?->error('DemoSeeder is disabled in production.');

            return;
        }

        $tenant = app(TenantContext::class);
        $tenant->runAsSystem(fn () => $this->call([PlanSeeder::class, FsliTemplateSeeder::class]));
        $password = 'Demo!Passw0rd#';

        if (! User::where('email', 'admin@audira.test')->exists()) {
            $tenant->runAsSystem(fn () => User::create(['type' => UserType::Platform, 'name' => 'Platform Admin', 'email' => 'admin@audira.test', 'password' => $password, 'status' => UserStatus::Active]));
        }
        if (User::where('email', 'farid@lim-farid.test')->exists()) {
            return;
        }

        $partner = app(FirmRegistrationService::class)->register([
            'firm_name' => 'Lim, Farid & Co. PLT', 'registration_no' => 'LLP0023456-LCA', 'audit_firm_no' => 'AF 002345',
            'name' => 'Farid Hassan', 'email' => 'farid@lim-farid.test', 'password' => $password,
            'plan_id' => Plan::where('code', 'standard')->value('id'),
        ]);

        $tenant->runForFirm($partner->firm_id, function () use ($partner, $password) {
            $mk = fn (string $name, string $email, FirmRole $role, bool $admin = false) => User::create([
                'firm_id' => $partner->firm_id, 'type' => UserType::Firm, 'firm_role' => $role, 'is_firm_admin' => $admin,
                'name' => $name, 'email' => $email, 'password' => $password, 'status' => UserStatus::Active,
            ]);
            $lim = $mk('Lim Wei Jie', 'weijie@lim-farid.test', FirmRole::Partner);
            $manager = $mk('Nurul Aina', 'aina@lim-farid.test', FirmRole::Manager);
            $senior = $mk('Rajesh Kumar', 'rajesh@lim-farid.test', FirmRole::Senior);
            $mk('Chloe Tan', 'chloe@lim-farid.test', FirmRole::Associate);

            $clients = app(ClientService::class);
            $approvals = app(ApprovalService::class);
            $engagements = app(EngagementService::class);

            $data = [
                ['Sinar Maju Manufacturing Sdn. Bhd.', '201501012345', 'private_company', 'mpers', 'Manufacturing', 6, 30, true],
                ['Taman Seri Residensi JMB', 'JMB/0123/2016', 'jmb_mc', 'mpers', 'Strata management', 12, 31, true],
                ['Koperasi Warga Pendidik Berhad', 'KOP-W-1234', 'cooperative', 'mfrs', 'Cooperative', 12, 31, true],
                ['Harbour Logistics Bhd.', '200801045678', 'public_company', 'mfrs', 'Logistics', 3, 31, false],
                ['Bayu Hijau Agro Sdn. Bhd.', '201901098765', 'private_company', 'mpers', 'Agriculture', 9, 30, null],
            ];

            foreach ($data as [$name, $reg, $type, $fw, $industry, $m, $d, $accept]) {
                $client = $clients->create(['name' => $name, 'registration_no' => $reg, 'entity_type' => $type, 'reporting_framework' => $fw,
                    'industry' => $industry, 'fye_month' => $m, 'fye_day' => $d, 'email' => null, 'phone' => null, 'address' => null], $manager);
                if ($accept === null) {
                    continue;
                }
                $clients->submitForAcceptance($client, ['acceptance_risk' => $accept ? 'low' : 'medium', 'independence_confirmed' => true,
                    'acceptance_notes' => 'Management integrity assessed with no adverse findings; firm has the competence and capacity; no independence threats identified.'], $manager);
                if ($accept) {
                    $approvals->decide(Approval::pending()->latest('id')->first(), $lim, true, 'Accepted.');
                    $client->refresh();
                    $year = $m >= 6 ? 2025 : 2026;
                    $end = sprintf('%d-%02d-%02d', $year, $m, $d);
                    $start = date('Y-m-d', strtotime($end.' -1 year +1 day'));
                    $e = $engagements->create($client, ['name' => 'Statutory audit FYE '.$year, 'engagement_type' => 'statutory_audit',
                        'reporting_framework' => $fw, 'period_start' => $start, 'period_end' => $end,
                        'reporting_deadline' => date('Y-m-d', strtotime($end.' +6 months')), 'engagement_partner_id' => $partner->id, 'manager_id' => $manager->id], $manager);
                    $engagements->addMember($e, $senior, EngagementRole::Senior, $manager);
                    if ($m === 6) {
                        $this->workingPaperDemo($e, $senior, $manager);
                    }
                }
            }
        });

        $tenant->runAsSystem(fn () => $this->call(LibrarySeeder::class));
        $this->command?->info("Demo ready. Firm partner: farid@lim-farid.test · Superadmin: admin@audira.test · Password: {$password}");
    }

    private function workingPaperDemo(\App\Models\Engagement $e, User $senior, User $manager): void
    {
        app(\App\Services\WorkingPapers\EntityUnderstandingService::class)->save($e, [
            'nature_of_entity' => "Private company manufacturing flexible plastic packaging in Shah Alam, Selangor. Owned 60/40 by the two founding directors; ~140 employees. Financed by a revolving credit facility and hire purchase.",
            'industry_regulatory' => "Demand driven by F&B customers. Resin prices are volatile and USD-linked. Subject to Companies Act 2016 and local environmental permits.",
            'framework_policies' => "MPERS. Inventories at lower of cost (weighted average) and net realisable value; revenue on delivery.",
        ], $senior);

        $docs = app(\App\Services\Documents\DocumentService::class);
        $tmp = tempnam(sys_get_temp_dir(), 'demo').'.pdf';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $doc = $docs->upload($e, new \Illuminate\Http\UploadedFile($tmp, 'maybank-confirmation-jun2025.pdf', 'application/pdf', null, true), 'Maybank bank confirmation (30 Jun 2025)', \App\Enums\DocumentCategory::Evidence, null, $senior);

        $papers = app(\App\Services\WorkingPapers\WorkingPaperService::class);
        $wp = $papers->create($e, ['reference' => 'F1', 'title' => 'Bank and cash', 'section' => 'fieldwork',
            'objective' => 'Bank balances exist, are complete and are correctly stated at year end.'], $senior);
        $papers->update($wp, ['work_performed' => "Obtained bank confirmation directly from Maybank. Agreed confirmed balance of RM 1,284,551.20 to bank reconciliation and general ledger. Vouched 3 reconciling items over RM 10,000 to post year-end statements.",
            'conclusion' => 'Bank balances are fairly stated.'], $senior);
        $papers->linkDocument($wp, $doc, $senior);
        $papers->signAsPreparer($wp->fresh(), $senior);
        $papers->raiseNote($wp->fresh(), 'Please confirm the confirmation request was sent by us, not the client, and attach the request letter.', $manager);
        $papers->create($e, ['reference' => 'A1', 'title' => 'Planning memorandum', 'section' => 'planning'], $manager);

        $this->trialBalanceDemo($e, $senior);
    }

    /** Demo TB built through the real import service (retained earnings is the balancing figure). */
    private function trialBalanceDemo(\App\Models\Engagement $e, User $senior): void
    {
        $accounts = [
            ['1000', 'Cash at bank - Maybank', 128455120, 98211040], ['1010', 'Petty cash', 250000, 250000],
            ['1100', 'Trade debtors control', 342118050, 301772600], ['1110', 'Allowance for expected credit losses', -1850000, -1500000],
            ['1150', 'Deposits and prepayments', 12560000, 11800000], ['1200', 'Stock - raw materials', 98760000, 85400000],
            ['1210', 'Stock - finished goods', 154320000, 131250000], ['1500', 'Plant and machinery - cost', 612000000, 548000000],
            ['1510', 'Plant and machinery - accumulated depreciation', -287400000, -243800000], ['1520', 'Motor vehicles - cost', 68500000, 68500000],
            ['1530', 'Motor vehicles - accumulated depreciation', -41100000, -27400000], ['2000', 'Trade creditors control', -186420000, -171930000],
            ['2100', 'Accruals', -24310000, -21870000], ['2200', 'Amount due to director - Encik Rahman', -35000000, -50000000],
            ['2300', 'Hire purchase payables', -42800000, -58300000], ['2400', 'Term loan - CIMB', -150000000, -180000000],
            ['2500', 'Provision for taxation', -18600000, -15200000], ['3000', 'Share capital', -100000000, -100000000],
            ['4000', 'Sales - local', -1480250000, -1352100000], ['4010', 'Sales - export', -312480000, -268900000],
            ['4100', 'Rental income', -4800000, -4800000], ['5000', 'Purchases - resin', 902100000, 815300000],
            ['5010', 'Direct labour', 214800000, 198400000], ['5020', 'Factory overheads', 128900000, 117200000],
            ['6000', 'Salaries and EPF - office', 96800000, 89500000], ['6010', 'Depreciation', 57300000, 52600000],
            ['6020', 'Audit fee', 2800000, 2600000], ['6030', 'Upkeep of premises', 8450000, 7900000],
            ['6100', 'Advertisement and promotion', 11200000, 9850000], ['6200', 'Term loan interest', 11850000, 13400000],
            ['6210', 'Hire purchase interest', 3150000, 4100000], ['7000', 'Income tax expense', 31200000, 26500000],
        ];
        $svc = app(\App\Services\TrialBalance\TrialBalanceImportService::class);
        foreach (['prior' => 3, 'current' => 2] as $period => $col) {
            $rows = [['Account Code', 'Account Name', 'Debit', 'Credit']];
            $net = 0;
            foreach ($accounts as $a) {
                $bal = $a[$col];
                $net += $bal;
                $rows[] = [$a[0], $a[1], $bal > 0 ? number_format($bal / 100, 2) : '', $bal < 0 ? number_format(-$bal / 100, 2) : ''];
            }
            $rows[] = ['3100', 'Retained earnings', $net < 0 ? number_format(-$net / 100, 2) : '', $net > 0 ? number_format($net / 100, 2) : ''];
            $path = tempnam(sys_get_temp_dir(), 'tb').'.csv';
            $h = fopen($path, 'w');
            foreach ($rows as $r) {
                fputcsv($h, $r);
            }
            fclose($h);
            $import = $svc->upload($e, new \Illuminate\Http\UploadedFile($path, "sinar-maju-tb-{$period}.csv", 'text/csv', null, true), \App\Enums\TbPeriod::from($period), $senior);
            $svc->activate($import, $import->column_map, $senior);
        }

        $mapping = app(\App\Services\TrialBalance\MappingService::class);
        $mapping->acceptSuggestions($e, array_keys($mapping->suggestions($e)), $senior);
        $ids = $e->fslis()->pluck('id', 'code');
        $mapping->apply($e, ['2300' => $ids['BORR-C'], '2400' => $ids['BORR-NC'], '1110' => $ids['TR'], '6010' => $ids['ADMIN']], $senior);
    }
}
