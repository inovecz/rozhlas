<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LocationTypeEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('locations')->insert([
            [
                'name' => 'Ústředna',
                'type' => LocationTypeEnum::CENTRAL,
                'latitude' => 49.454027415362,
                'longitude' => 17.977854609489,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Hnízdo',
                'type' => LocationTypeEnum::NEST,
                'latitude' => 49.453821671151,
                'longitude' => 17.977629303932,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
