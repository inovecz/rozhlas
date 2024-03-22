<?php

namespace App\Http\Controllers;

use App\Services\FileService;
use App\Http\Resources\FileResource;
use App\Http\Requests\FileUploadRequest;

class FileController extends Controller
{
    public function upload(FileUploadRequest $request): FileResource
    {
        $fileService = new FileService();
        $file = $fileService->uploadPost($request);

        return new FileResource($file);
    }
}
