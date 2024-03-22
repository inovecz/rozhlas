<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Http\Requests\FileUploadRequest;

class FileService
{
    public function uploadPost(FileUploadRequest $request)
    {
        $file = $request->file('file');
        $name = $request->input('name');
        $metadata = $request->input('metadata');
        return $this->upload($file, $name, $metadata);
    }

    public function upload(UploadedFile $file, string $name, array $metadata = null): File
    {
        $filename = Str::uuid();
        $extension = $file->getClientOriginalExtension();
        $path = 'uploads/';
        Storage::put($path.$filename.'.'.$extension, file_get_contents($file));
        $fileSize = Storage::size($path.$filename.'.'.$extension);
        $data = [
            'author_id' => auth()->id(),
            'name' => $name,
            'filename' => Str::uuid()->toString(),
            'path' => $path,
            'extension' => $extension,
            'mime_type' => $file->getClientMimeType(),
            'size' => $fileSize,
            'metadata' => $metadata,
        ];
        return File::create($data);
    }
}