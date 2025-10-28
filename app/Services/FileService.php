<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ConvertToMp3;
use App\Models\File;
use Illuminate\Support\Str;
use App\Enums\FileTypeEnum;
use App\Enums\FileSubtypeEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\FileUploadRequest;
use RuntimeException;

class FileService
{
    public function uploadPost(FileUploadRequest $request)
    {
        $file = $request->file('file');
        $type = ($request->input('type') ? FileTypeEnum::tryFrom($request->input('type') ?? 'COMMON') : FileTypeEnum::COMMON) ?? FileTypeEnum::COMMON;
        $subtype = ($request->input('subtype') ? FileSubtypeEnum::tryFrom($request->input('subtype') ?? 'OTHER') : FileSubtypeEnum::OTHER) ?? FileSubtypeEnum::OTHER;
        $name = $request->input('name');
        $metadata = $request->input('metadata');
        $extension = $request->input('extension');
        return $this->upload($file, $type, $subtype, $name, null, $extension, $metadata);
    }

    public function upload(UploadedFile $uploadedFile, FileTypeEnum $type, FileSubtypeEnum $subtype, string $name, string $path = null, string $extension = null, array $metadata = null): File
    {
        $filename = Str::uuid()->toString();
        $extension = $extension ?? $uploadedFile->getClientOriginalExtension();
        $path = $path ?? 'uploads/';
        Storage::put($path.$filename.'.'.$extension, $uploadedFile->getContent());
        $fileSize = Storage::size($path.$filename.'.'.$extension);
        $data = [
            'author_id' => auth()->id(),
            'type' => $type,
            'subtype' => $subtype,
            'name' => $name,
            'filename' => $filename,
            'path' => $path,
            'extension' => $extension,
            'mime_type' => $uploadedFile->getClientMimeType(),
            'size' => $fileSize,
            'metadata' => $metadata,
        ];
        $file = File::create($data);
        if ($file->getMimeType() !== 'audio/mpeg' && str($file->getMimeType())->startsWith('audio/')) {
            ConvertToMp3::dispatch($file);
        }
        return $file;
    }

    public function createRecordingFromExistingFile(File $sourceFile, string $name, FileSubtypeEnum $subtype, ?array $metadata = null): File
    {
        if (!Storage::exists($sourceFile->getStoragePath())) {
            throw new RuntimeException('Zdrojový soubor nebyl nalezen.');
        }

        $filename = Str::uuid()->toString();
        $extension = $sourceFile->getExtension();
        $targetPath = 'uploads/'.$filename.'.'.$extension;
        Storage::copy($sourceFile->getStoragePath(), $targetPath);

        $sourceMetadata = $sourceFile->getMetadata() ?? [];
        $mergedMetadata = array_filter(array_merge($sourceMetadata, $metadata ?? []), static fn ($value) => $value !== null);

        $file = File::create([
            'author_id' => auth()->id(),
            'type' => FileTypeEnum::RECORDING,
            'subtype' => $subtype,
            'name' => $name,
            'filename' => $filename,
            'path' => 'uploads/',
            'extension' => $extension,
            'mime_type' => $sourceFile->getMimeType(),
            'size' => Storage::size($targetPath),
            'metadata' => $mergedMetadata ?: null,
        ]);

        if ($file->getMimeType() !== 'audio/mpeg' && str($file->getMimeType())->startsWith('audio/')) {
            ConvertToMp3::dispatch($file);
        }

        return $file;
    }
}
