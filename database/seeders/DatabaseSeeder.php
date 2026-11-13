<?php

namespace Database\Seeders;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(TenantContext::class)->runAsSystem(function () {
            $this->call([PlanSeeder::class, LibrarySeeder::class, FsliTemplateSeeder::class]);
        });
    }
}
