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
                'name' => 'JSVV Siréna 1 - Kolísavý tón (ROT)',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
                'storage_path' => 'jsvv/sirens/',
            ], [
                'path' => resource_path('audio/jsvv/vseobecna_ES.mp3'),
                'name' => 'JSVV Siréna 1 - Kolísavý tón (ES)',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
                'storage_path' => 'jsvv/sirens/',
            ], [
                'path' => resource_path('audio/jsvv/pozarni_ROT.mp3'),
                'name' => 'JSVV Siréna 4 - Požární poplach (ROT)',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
                'storage_path' => 'jsvv/sirens/',
            ], [
                'path' => resource_path('audio/jsvv/pozarni_ES.mp3'),
                'name' => 'JSVV Siréna 4 - Požární poplach (ES)',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
                'storage_path' => 'jsvv/sirens/',
            ], [
                'path' => resource_path('audio/jsvv/zkusebni_ROT.mp3'),
                'name' => 'JSVV Siréna 2 - Trvalý tón (ROT)',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
                'storage_path' => 'jsvv/sirens/',
            ], [
                'path' => resource_path('audio/jsvv/zkusebni_ES.mp3'),
                'name' => 'JSVV Siréna 2 - Trvalý tón (ES)',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::SIREN,
                'storage_path' => 'jsvv/sirens/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 1 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 1 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 2 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 2 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 3 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 3 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 4 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 4 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 5 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 5 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 6 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 6 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 7 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 7 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 13 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 13 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 14 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 14 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 15 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 15 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/'. "Informace \u{010D}. 16 - mu\u{017E}.mp3"),
                'name' => "JSVV Verbální informace 16 (mu\u{017E})",
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::VERBAL,
                'storage_path' => 'jsvv/verbal/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/Gong 1.wav'),
                'name' => 'JSVV Gong 1',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::GONG,
                'storage_path' => 'jsvv/gongs/',
            ], [
                'path' => resource_path('audio/jsvv/verbal-informations/Gong 2.wav'),
                'name' => 'JSVV Gong 2',
                'type' => FileTypeEnum::JSVV,
                'subtype' => FileSubtypeEnum::GONG,
                'storage_path' => 'jsvv/gongs/',
            ],
        ];

        $fileService = new FileService();

        foreach ($files as $jsvvFile) {
            $path = $jsvvFile['path'];

            if (!is_file($path)) {
                continue;
            }

            if (File::where([
                'name' => $jsvvFile['name'],
                'type' => $jsvvFile['type'],
                'subtype' => $jsvvFile['subtype'],
            ])->exists()) {
                continue;
            }

            $name = basename($path);
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $mime = mime_content_type($path) ?: 'audio/mpeg';

            $uploadedFile = new UploadedFile(
                $path,
                $name,
                $mime,
                null,
                true // Set test mode to true if you don't want to move the file immediately
            );

            try {
                $duration = audio_duration($path);
            } catch (\Throwable $exception) {
                $duration = null;
            }

            $metadata = $duration !== null
                ? ['duration' => $duration]
                : null;

            $storagePath = $jsvvFile['storage_path'] ?? 'jsvv/';

            $fileService->upload(
                $uploadedFile,
                $jsvvFile['type'],
                $jsvvFile['subtype'],
                $jsvvFile['name'],
                $storagePath,
                $extension,
                $metadata
            );
        }
    }
}
