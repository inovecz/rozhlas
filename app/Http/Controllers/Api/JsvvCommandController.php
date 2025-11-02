<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlChannelService;
use App\Services\JsvvSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class JsvvCommandController extends Controller
{
    public function __construct(
        private readonly JsvvSequenceService $sequenceService,
        private readonly ControlChannelService $controlChannel,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in(['STOP', 'SEQUENCE'])],
            'payload' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $type = strtoupper((string) $validated['type']);
        $payload = Arr::get($validated, 'payload', []);

        return match ($type) {
            'STOP' => $this->handleStopCommand($payload),
            'SEQUENCE' => $this->handleSequenceCommand($payload),
            default => response()->json(['status' => 'unsupported_command'], 400),
        };
    }

    private function handleStopCommand(array $payload): JsonResponse
    {
        $reason = (string) Arr::get($payload, 'reason', 'jsvv_manual_stop');

        $sequenceResult = $this->sequenceService->stopAll($reason);

        $controlResult = null;
        try {
            $command = $this->controlChannel->stop(reason: $reason);
            $controlResult = $command->toArray();
        } catch (\Throwable $exception) {
            Log::warning('Control channel stop command failed.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'status' => $sequenceResult['status'] ?? 'stopped',
            'sequence' => $sequenceResult,
            'control_channel' => $controlResult,
        ]);
    }

    private function handleSequenceCommand(array $payload): JsonResponse
    {
        $steps = Arr::get($payload, 'steps', []);
        if (!is_array($steps) || $steps === []) {
            return response()->json(['errors' => ['payload.steps' => ['Sekvence musí obsahovat alespoň jeden krok.']]], 422);
        }

        $options = Arr::except($payload, ['steps']);

        try {
            $plan = $this->sequenceService->plan($steps, $options);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'errors' => ['payload.steps' => [$exception->getMessage()]],
            ], 422);
        }

        $sequenceId = (string) (Arr::get($plan, 'id') ?? Arr::get($plan, 'sequence.id') ?? '');
        if ($sequenceId === '') {
            return response()->json([
                'status' => 'failed',
                'message' => 'Nepodařilo se připravit sekvenci JSVV.',
            ], 500);
        }

        $trigger = $this->sequenceService->trigger($sequenceId);

        return response()->json([
            'status' => $trigger['status'] ?? 'queued',
            'sequence' => $trigger,
        ]);
    }
}
