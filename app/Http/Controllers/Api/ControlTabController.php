<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlTabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ControlTabController extends Controller
{
    public function __construct(private readonly ControlTabService $service)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:panel_loaded,button_pressed,text_field_request'],
            'screen' => ['sometimes', 'integer'],
            'panel' => ['sometimes', 'integer'],
            'button_id' => ['required_if:type,button_pressed', 'integer'],
            'field_id' => ['required_if:type,text_field_request', 'integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $type = $data['type'];

        return match ($type) {
            'panel_loaded' => response()->json($this->service->handlePanelLoaded(
                (int) ($data['screen'] ?? 0),
                (int) ($data['panel'] ?? 0),
            )),
            'button_pressed' => response()->json($this->service->handleButtonPress(
                (int) $data['button_id'],
                $data,
            )),
            'text_field_request' => response()->json($this->service->handleTextRequest(
                (int) $data['field_id'],
            )),
            default => response()->json(['status' => 'unsupported'], 400),
        };
    }
}
