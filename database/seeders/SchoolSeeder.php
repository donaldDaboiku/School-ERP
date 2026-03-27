<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::create([
            'name' => 'Demo School',
            'code' => 'DEMO001',
            'country' => 'Nigeria',
            'status' => 'active',
        ]);
    }
}
