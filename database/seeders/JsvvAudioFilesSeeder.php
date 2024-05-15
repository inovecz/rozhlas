<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\File;
use App\Enums\FileTypeEnum;
use App\Services\FileService;
use App\Enums\FileSubtypeEnum;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

class JsvvAudioFilesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $files = [
            [
                'path' => resource_path('audio/jsvv/vseobecna_ROT.mp3'),
                'name' => 'Všeobecný poplach - rotační siréna',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
            ], [
                'path' => resource_path('audio/jsvv/vseobecna_ES.mp3'),
                'name' => 'Všeobecný poplach - elektronická siréna',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
            ], [
                'path' => resource_path('audio/jsvv/pozarni_ROT.mp3'),
                'name' => 'Požární poplach - rotační siréna',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
            ], [
                'path' => resource_path('audio/jsvv/pozarni_ES.mp3'),
                'name' => 'Požární poplach - elektronická siréna',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
            ],
            [
                'path' => resource_path('audio/jsvv/zkusebni_ROT.mp3'),
                'name' => 'Zkouška sirén - rotační siréna',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
            ], [
                'path' => resource_path('audio/jsvv/zkusebni_ES.mp3'),
                'name' => 'Zkouška sirén - elektronická siréna',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
            ],
        ];

        $fileService = new FileService();

        foreach ($files as $jsvvFile) {
            if (File::where([
                'name' => $jsvvFile['name'],
                'type' => $jsvvFile['type'],
                'subtype' => $jsvvFile['subtype'],
            ])->exists()) {
                continue;
            }
            $path = $jsvvFile['path'];
            $name = basename($jsvvFile['path']);
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $mime = mime_content_type($path);

            $uploadedFile = new UploadedFile(
                $path,
                $name,
                $mime,
                null,
                true // Set test mode to true if you don't want to move the file immediately
            );

            $metadata = [
                'duration' => audio_duration($jsvvFile['path']),
            ];

            $fileService->upload($uploadedFile, $jsvvFile['type'], $jsvvFile['subtype'], $jsvvFile['name'], 'jsvv/', $extension, $metadata);
        }
    }
}
