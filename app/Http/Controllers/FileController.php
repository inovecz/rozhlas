<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ListRequest;
use App\Http\Resources\FileResource;
use App\Http\Requests\FileUploadRequest;
use Illuminate\Support\Facades\Response;
use App\Http\Requests\RenameFileRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Http\Requests\RecordingCopyRequest;
use App\Enums\FileSubtypeEnum;

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

    public function renameFile(RenameFileRequest $request, File $file): JsonResponse
    {
        $file->update($request->validated());
        return $this->success('File renamed successfully.');
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

    public function copyFromCentralFile(RecordingCopyRequest $request): FileResource
    {
        $sourceFile = File::findOrFail($request->input('source_file_id'));
        $metadata = $request->input('metadata', []);

        if ($request->filled('note')) {
            $metadata['note'] = $request->input('note');
        }

        if (!isset($metadata['source'])) {
            $metadata['source'] = 'central_file';
        }

        if (!isset($metadata['duration'])) {
            $sourceMetadata = $sourceFile->getMetadata() ?? [];
            $duration = data_get($sourceMetadata, 'duration', data_get($sourceMetadata, 'duration_seconds'));
            if ($duration !== null) {
                $metadata['duration'] = $duration;
            }
        }

        $fileService = new FileService();
        $file = $fileService->createRecordingFromExistingFile(
            $sourceFile,
            $request->input('name'),
            FileSubtypeEnum::from($request->input('subtype')),
            $metadata
        );

        return new FileResource($file);
    }
}
