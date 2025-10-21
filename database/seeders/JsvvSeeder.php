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

        $timestamp = now();

        $sirens = [
            ['symbol' => '1', 'name' => 'Kolísavý tón'],
            ['symbol' => '2', 'name' => 'Trvalý tón'],
            ['symbol' => '3', 'name' => 'Rezerva'],
            ['symbol' => '4', 'name' => 'Požární poplach'],
        ];

        $gongs = [
            ['symbol' => '8', 'name' => 'Gong č. 1'],
            ['symbol' => '9', 'name' => 'Gong č. 2'],
        ];

        $verbals = [
            ['symbol' => 'A', 'name' => 'Zkouška sirén'],
            ['symbol' => 'B', 'name' => 'Všeobecná výstraha'],
            ['symbol' => 'C', 'name' => 'Zátopová vlna'],
            ['symbol' => 'D', 'name' => 'Chemická havárie'],
            ['symbol' => 'E', 'name' => 'Radiační havárie'],
            ['symbol' => 'F', 'name' => 'Konec poplachu'],
            ['symbol' => 'G', 'name' => 'Požární poplach'],
            ['symbol' => 'U', 'name' => 'Proběhne zkouška'],
            ['symbol' => 'V', 'name' => 'Proběhne zkouška (A)'],
            ['symbol' => 'X', 'name' => 'Proběhne zkouška (N)'],
            ['symbol' => 'Y', 'name' => 'Proběhne zkouška (R)'],
        ];

        $audioSources = [
            ['symbol' => 'I', 'name' => 'Externí audio', 'type' => JsvvAudioTypeEnum::SOURCE, 'source' => JsvvAudioSourceEnum::FM],
            ['symbol' => 'J', 'name' => 'Externí modulace', 'type' => JsvvAudioTypeEnum::SOURCE, 'source' => JsvvAudioSourceEnum::INPUT_4],
            ['symbol' => 'K', 'name' => 'Rezerva 1', 'type' => JsvvAudioTypeEnum::SOURCE, 'source' => JsvvAudioSourceEnum::INPUT_2],
            ['symbol' => 'L', 'name' => 'Rezerva 3', 'type' => JsvvAudioTypeEnum::SOURCE, 'source' => JsvvAudioSourceEnum::INPUT_3],
            ['symbol' => 'M', 'name' => 'Mikrofon', 'type' => JsvvAudioTypeEnum::SOURCE, 'source' => JsvvAudioSourceEnum::INPUT_1],
        ];

        $audioSilence = [
            ['symbol' => 'P', 'name' => 'Ticho pro P'],
            ['symbol' => 'Q', 'name' => 'Ticho pro Q'],
            ['symbol' => 'R', 'name' => 'Ticho pro R'],
            ['symbol' => 'S', 'name' => 'Ticho pro S'],
            ['symbol' => 'T', 'name' => 'Ticho pro T'],
        ];

        $audios = [];

        foreach ($sirens as $audio) {
            $audios[] = [
                'symbol' => $audio['symbol'],
                'name' => $audio['name'],
                'group' => JsvvAudioGroupEnum::SIREN,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach ($gongs as $audio) {
            $audios[] = [
                'symbol' => $audio['symbol'],
                'name' => $audio['name'],
                'group' => JsvvAudioGroupEnum::GONG,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach ($verbals as $audio) {
            $audios[] = [
                'symbol' => $audio['symbol'],
                'name' => $audio['name'],
                'group' => JsvvAudioGroupEnum::VERBAL,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach ($audioSources as $audio) {
            $audios[] = [
                'symbol' => $audio['symbol'],
                'name' => $audio['name'],
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => $audio['type'],
                'source' => $audio['source'],
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach ($audioSilence as $audio) {
            $audios[] = [
                'symbol' => $audio['symbol'],
                'name' => $audio['name'],
                'group' => JsvvAudioGroupEnum::AUDIO,
                'type' => JsvvAudioTypeEnum::FILE,
                'source' => null,
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('jsvv_audio')->insert($audios);
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
