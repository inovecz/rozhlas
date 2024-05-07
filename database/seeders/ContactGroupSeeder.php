<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('contact_groups')->insert([
            [
                'name' => 'Obec',
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Občané',
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Servis',
                'created_at' => now(),
                'updated_at' => now(),
            ], [
                'name' => 'Zastupitelé',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
