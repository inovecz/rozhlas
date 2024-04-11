<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ListRequest;
use App\Http\Resources\FileResource;
use App\Http\Requests\FileUploadRequest;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FileController extends Controller
{
    public function upload(FileUploadRequest $request): FileResource
    {
        $fileService = new FileService();
        $file = $fileService->uploadPost($request);

        return new FileResource($file);
    }

    public function list(ListRequest $request): AnonymousResourceCollection
    {
        $files = File::query()
            ->when($request->input('search'), static function ($query, $search) {
                return $query->where('name', 'like', '%'.$search.'%');
            })
            ->when($request->input('order'), static function ($query, $order) {
                foreach ($order as $item) {
                    $query->orderBy($item['column'], $item['dir']);
                }
                return $query;
            })->when($request->input('filter'), static function ($query, $filter) {
                if (!empty($filter)) {
                    foreach ($filter as $item) {
                        $query->where($item['column'], $item['value']);
                    }
                }
                return $query;
            })
            ->paginate($request->input('length', 10));

        return FileResource::collection($files);
    }

    public function getRecordWithBlob(File $file): ?BinaryFileResponse
    {
        $headers = [
            'Content-Type' => $file->getMimeType(),
        ];
        return Response::download(storage_path('app/'.$file->getStoragePath()), $file->getName().'.'.$file->getExtension(), $headers);
    }

    public function delete(File $file): JsonResponse
    {
        $file->delete();
        return $this->success();
    }
}
