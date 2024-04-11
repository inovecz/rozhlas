<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Str;
use App\Enums\FileTypeEnum;
use App\Enums\FileSubtypeEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\FileUploadRequest;

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
        return $this->upload($file, $type, $subtype, $name, $extension, $metadata);
    }

    public function upload(UploadedFile $file, FileTypeEnum $type, FileSubtypeEnum $subtype, string $name, string $extension = null, array $metadata = null): File
    {
        $filename = Str::uuid()->toString();
        $extension = $extension ?? $file->getClientOriginalExtension();
        $path = 'uploads/';
        Storage::put($path.$filename.'.'.$extension, file_get_contents($file));
        $fileSize = Storage::size($path.$filename.'.'.$extension);
        $data = [
            'author_id' => auth()->id(),
            'type' => $type,
            'subtype' => $subtype,
            'name' => $name,
            'filename' => $filename,
            'path' => $path,
            'extension' => $extension,
            'mime_type' => $file->getClientMimeType(),
            'size' => $fileSize,
            'metadata' => $metadata,
        ];
        return File::create($data);
    }
}