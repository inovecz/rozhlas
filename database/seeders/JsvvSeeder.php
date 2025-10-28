<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Enums\JsvvAudioTypeEnum;
use App\Enums\JsvvAudioGroupEnum;
use Illuminate\Support\Facades\DB;
use App\Enums\JsvvAudioSourceEnum;
use App\Enums\FileSubtypeEnum;
use App\Enums\FileTypeEnum;
use App\Models\File;

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
                'group' => JsvvAudioGroupEnum::SIREN->value,
                'type' => JsvvAudioTypeEnum::FILE->value,
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
                'group' => JsvvAudioGroupEnum::GONG->value,
                'type' => JsvvAudioTypeEnum::FILE->value,
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
                'group' => JsvvAudioGroupEnum::VERBAL->value,
                'type' => JsvvAudioTypeEnum::FILE->value,
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
                'group' => JsvvAudioGroupEnum::AUDIO->value,
                'type' => $audio['type']->value,
                'source' => $audio['source']->value,
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach ($audioSilence as $audio) {
            $audios[] = [
                'symbol' => $audio['symbol'],
                'name' => $audio['name'],
                'group' => JsvvAudioGroupEnum::AUDIO->value,
                'type' => JsvvAudioTypeEnum::FILE->value,
                'source' => null,
                'file_id' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $defaultFiles = [
            '1' => 'JSVV Siréna 1 - Kolísavý tón (ES)',
            '2' => 'JSVV Siréna 2 - Trvalý tón (ES)',
            '4' => 'JSVV Siréna 4 - Požární poplach (ES)',
            '8' => 'JSVV Gong 1',
            '9' => 'JSVV Gong 2',
            'A' => "JSVV Verbální informace 1 (mu\u{017E})",
            'B' => "JSVV Verbální informace 2 (mu\u{017E})",
            'C' => "JSVV Verbální informace 3 (mu\u{017E})",
            'D' => "JSVV Verbální informace 4 (mu\u{017E})",
            'E' => "JSVV Verbální informace 5 (mu\u{017E})",
            'F' => "JSVV Verbální informace 6 (mu\u{017E})",
            'G' => "JSVV Verbální informace 7 (mu\u{017E})",
            'U' => "JSVV Verbální informace 13 (mu\u{017E})",
            'V' => "JSVV Verbální informace 14 (mu\u{017E})",
            'X' => "JSVV Verbální informace 15 (mu\u{017E})",
            'Y' => "JSVV Verbální informace 16 (mu\u{017E})",
        ];

        $groupSubtypeMap = [
            JsvvAudioGroupEnum::SIREN->value => FileSubtypeEnum::SIREN,
            JsvvAudioGroupEnum::GONG->value => FileSubtypeEnum::GONG,
            JsvvAudioGroupEnum::VERBAL->value => FileSubtypeEnum::VERBAL,
        ];

        foreach ($audios as &$audio) {
            $symbol = $audio['symbol'];
            $fileName = $defaultFiles[$symbol] ?? null;
            if ($fileName === null) {
                continue;
            }

            $groupValue = $audio['group'];
            $subtype = $groupSubtypeMap[$groupValue] ?? null;
            if ($subtype === null) {
                continue;
            }

            $file = File::query()
                ->where('name', $fileName)
                ->where('type', FileTypeEnum::JSVV)
                ->where('subtype', $subtype)
                ->first();

            if ($file === null) {
                continue;
            }

            $audio['file_id'] = $file->getKey();
        }
        unset($audio);

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
