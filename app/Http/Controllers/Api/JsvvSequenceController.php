<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\JsvvSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JsvvSequenceController extends Controller
{
    public function __construct(private readonly JsvvSequenceService $service = new JsvvSequenceService())
    {
    }

    public function plan(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'min:1'],
            'items.*.slot' => ['required', 'integer'],
            'items.*.category' => ['required', 'in:verbal,siren'],
            'items.*.voice' => ['nullable', 'string'],
            'items.*.repeat' => ['nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', 'string'],
            'zones' => ['sometimes', 'array'],
            'holdSeconds' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $sequence = $this->service->plan($data['items'], $data);

        return response()->json(['sequence' => $sequence]);
    }

    public function trigger(string $sequenceId): JsonResponse
    {
        $sequence = $this->service->trigger($sequenceId);
        return response()->json(['sequence' => $sequence]);
    }

    public function assets(Request $request): JsonResponse
    {
        $assets = $this->service->getAssets(
            $request->query('category'),
            $request->integer('slot'),
            $request->query('voice')
        );

        return response()->json(['assets' => $assets]);
    }
}
