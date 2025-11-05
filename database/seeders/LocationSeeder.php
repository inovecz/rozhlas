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
                'modbus_group_address' => '100',
                'is_hidden' => false,
                'subtone_type' => 'A16',
                'subtone_data' => '{"listen":[2160],"record":[2161]}',
                'timing' => '{"total":{"start":6000,"end":9000},"ptt":{"start":4000,"end":8900},"subtone":{"start":5000,"end":100},"file":{"start":null,"end":null},"output_1":{"start":null,"end":null},"output_2":{"start":null,"end":null},"output_3":{"start":null,"end":null},"output_4":{"start":null,"end":null},"output_5":{"start":null,"end":null},"output_6":{"start":null,"end":null},"output_7":{"start":null,"end":null},"output_8":{"start":null,"end":null},"output_9":{"start":null,"end":null},"output_10":{"start":null,"end":null},"output_11":{"start":null,"end":null},"relay_1":{"start":null,"end":null},"relay_2":{"start":null,"end":null},"relay_3":{"start":null,"end":null},"relay_4":{"start":null,"end":null},"relay_5":{"start":null,"end":null}}',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('locations')->insert([
            [
                'name' => 'Ustredna',
                'location_group_id' => 1, // General
                'type' => LocationTypeEnum::CENTRAL,
                'modbus_address' => null,
                'latitude' => 49.454027415362,
                'longitude' => 17.977854609489,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Hnizdo 1',
                'location_group_id' => 1, // General
                'type' => LocationTypeEnum::NEST,
                'modbus_address' => 101,
                'latitude' => 49.453821671151,
                'longitude' => 17.977629303932,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Hnizdo 2',
                'location_group_id' => 1, // General
                'type' => LocationTypeEnum::NEST,
                'modbus_address' => 102,
                'latitude' => 49.453518284045,
                'longitude' => 17.97831594944,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
