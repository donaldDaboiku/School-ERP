<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::updateOrCreate(
            ['code' => 'DEMO001'],
            [
                'name' => 'Demo School',
                'country' => 'Nigeria',
                'status' => 'active',
            ]
        );
    }
}
