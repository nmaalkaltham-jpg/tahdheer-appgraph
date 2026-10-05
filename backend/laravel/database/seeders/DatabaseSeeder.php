<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CurriculumSeeder::class);
        app()->environment(['local', 'testing']) && $this->call(DevCommerceSeeder::class);
        $this->call(SynonymSeeder::class);
        app()->environment(['local', 'testing']) && $this->call(DevAssetSeeder::class);
        app()->environment('local') && $this->call(DesignPreviewSeeder::class); // DEV design-preview fixtures only
    }
}
