<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Enums\JsvvAudioTypeEnum;
use App\Enums\JsvvAudioGroupEnum;
use Illuminate\Support\Facades\DB;
use App\Enums\JsvvAudioSourceEnum;

class JsvvSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            JsvvAudioFilesSeeder::class,
        ]);
        DB::table('jsvv_alarms')->truncate();
        DB::table('jsvv_audio')->truncate();
        DB::table('jsvv_audio')->insert([
            [
                'symbol' => '1',
                'name' => 'Kolísavý tón',
                'group' => JsvvAudioGroupEnum::SIREN,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => '2',
                'name' => 'Trvalý tón',
                'group' => JsvvAudioGroupEnum::SIREN,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => '3',
                'name' => 'Rezerva',
                'group' => JsvvAudioGroupEnum::SIREN,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => '4',
                'name' => 'Požární poplach',
                'group' => JsvvAudioGroupEnum::SIREN,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => '8',
                'name' => 'Gong č.1',
                'group' => JsvvAudioGroupEnum::GONG,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => '9',
                'name' => 'Gong č.2',
                'group' => JsvvAudioGroupEnum::GONG,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'A',
                'name' => 'Zkouška sirén',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'B',
                'name' => 'Všeobecná výstraha',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'C',
                'name' => 'Zátopová vlna',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'D',
                'name' => 'Chemická havárie',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'E',
                'name' => 'Radiační havárie',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'F',
                'name' => 'Konec poplachu',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'G',
                'name' => 'Požární poplach',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'I',
                'name' => 'Externí audio',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::SOURCE,
                'source' => JsvvAudioSourceEnum::FM,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'J',
                'name' => 'Externí modulace',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::SOURCE,
                'source' => JsvvAudioSourceEnum::INPUT_4,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'K',
                'name' => 'Rezerva 1',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::SOURCE,
                'source' => JsvvAudioSourceEnum::INPUT_2,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'L',
                'name' => 'Rezerva 2',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::SOURCE,
                'source' => JsvvAudioSourceEnum::INPUT_3,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'M',
                'name' => 'Mikrofon',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::SOURCE,
                'source' => JsvvAudioSourceEnum::INPUT_1,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'P',
                'name' => 'Ticho pro P',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'Q',
                'name' => 'Ticho pro Q',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'R',
                'name' => 'Ticho pro R',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'S',
                'name' => 'Ticho pro S',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'T',
                'name' => 'Ticho pro T',
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'U',
                'name' => 'Proběhne zkouška',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'V',
                'name' => 'Proběhne zkouška (A)',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'X',
                'name' => 'Proběhne zkouška (N)',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'symbol' => 'Y',
                'name' => 'Proběhne zkouška (R)',
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('jsvv_alarms')->insert([
            [
                'name' => 'Zkouška sirén',
                'sequence_1' => '2',
                'sequence_2' => '8',
                'sequence_3' => 'A',
                'sequence_4' => '9',
                'button' => 1, 'mobile_button' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Všeobecná výstraha',
                'sequence_1' => '1',
                'sequence_2' => '8',
                'sequence_3' => 'B',
                'sequence_4' => '9',
                'button' => 2, 'mobile_button' => 2,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Požární poplach',
                'sequence_1' => '4',
                'sequence_2' => '8',
                'sequence_3' => 'G',
                'sequence_4' => '9',
                'button' => 3, 'mobile_button' => 3,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Zátopová vlna',
                'sequence_1' => '1',
                'sequence_2' => '8',
                'sequence_3' => 'C',
                'sequence_4' => '9',
                'button' => 4, 'mobile_button' => 4,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Chemická havárie',
                'sequence_1' => '1',
                'sequence_2' => '8',
                'sequence_3' => 'D',
                'sequence_4' => '9',
                'button' => 5, 'mobile_button' => 5,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Radiační poplach',
                'sequence_1' => '1',
                'sequence_2' => '8',
                'sequence_3' => 'E',
                'sequence_4' => '9',
                'button' => 6, 'mobile_button' => 6,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Konec poplachu',
                'sequence_1' => '8',
                'sequence_2' => 'F',
                'sequence_3' => '9',
                'sequence_4' => null,
                'button' => 7, 'mobile_button' => 7,
                'created_at' => now(), 'updated_at' => now(),
            ], [
                'name' => 'Mikrofon',
                'sequence_1' => '8',
                'sequence_2' => 'M',
                'sequence_3' => null,
                'sequence_4' => null,
                'button' => 8, 'mobile_button' => 8,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }
}
