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
        DB::table('locations')->truncate();
        DB::table('location_groups')->truncate();
        DB::table('location_groups')->insert([
            [
                'name' => 'General',
                'is_hidden' => false,
                'subtone_data' => '{"listen":[6200],"record":[6201]}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('locations')->insert([
            [
                'name' => 'Ústředna',
                'location_group_id' => 1, // General
                'type' => LocationTypeEnum::CENTRAL,
                'latitude' => 49.454027415362,
                'longitude' => 17.977854609489,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Hnízdo',
                'location_group_id' => 1, // General
                'type' => LocationTypeEnum::NEST,
                'latitude' => 49.453821671151,
                'longitude' => 17.977629303932,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Hnízdo 2',
                'location_group_id' => null,
                'type' => LocationTypeEnum::NEST,
                'latitude' => 49.453518284045,
                'longitude' => 17.97831594944,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Hnízdo 3',
                'location_group_id' => null,
                'type' => LocationTypeEnum::NEST,
                'latitude' => 49.454526079949,
                'longitude' => 17.976454496384,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Hnízdo 4',
                'location_group_id' => null,
                'type' => LocationTypeEnum::NEST,
                'latitude' => 49.454553977119,
                'longitude' => 17.9787504673,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
