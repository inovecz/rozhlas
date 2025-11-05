<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlTabBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class ControlTabPushController extends Controller
{
    public function __construct(private readonly ControlTabBridge $bridge)
    {
    }

    public function pushFields(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['nullable', 'string'],
            'screen' => ['sometimes', 'integer'],
            'panel' => ['sometimes', 'integer'],
            'switch_panel' => ['sometimes', 'boolean'],
            'panel_status' => ['sometimes', 'integer', 'in:0,1'],
            'panel_repeat' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'delay_ms' => ['sometimes', 'numeric', 'min:0'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $fields = [];
        foreach ($data['fields'] as $key => $value) {
            $fields[(int) $key] = $value ?? '';
        }

        ksort($fields, SORT_NUMERIC);

        $options = [
            'screen' => $data['screen'] ?? config('control_tab.default_screen', 3),
            'panel' => $data['panel'] ?? config('control_tab.default_panel', 1),
            'switch_panel' => (bool) Arr::get($data, 'switch_panel', false),
            'panel_status' => Arr::get($data, 'panel_status'),
            'panel_repeat' => Arr::get($data, 'panel_repeat'),
            'delay_ms' => Arr::get($data, 'delay_ms'),
            'dry_run' => Arr::get($data, 'dry_run'),
        ];

        if (empty($options['switch_panel'])) {
            unset($options['panel_status'], $options['panel_repeat']);
        }

        try {
            $result = $this->bridge->sendFields($fields, $options);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'Control Tab update failed.',
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'exit_code' => $result['exit_code'],
            'duration_ms' => $result['duration_ms'],
            'output' => $result['output'],
            'error_output' => $result['error_output'],
        ]);
    }
}

