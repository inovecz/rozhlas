<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

abstract class Service
{
    public function __construct(protected ?string $status = null, protected ?string $customMessage = null, protected array $extraInfo = [], protected int $customMessageCode = 400) { }

    public function setStatus(string $status, string $customMessage = null, int $customMessageCode = 400, array $data = null): string
    {
        $this->status = $status;
        $this->customMessage = $customMessage;
        $this->customMessageCode = $customMessageCode;
        if ($data) {
            $this->extraInfo = $data;
        }
        return $status;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setResponseMessage(string $message = null, int $httpCode = 200): JsonResponse
    {
        $this->extraInfo = is_array($this->extraInfo) ? $this->extraInfo : $this->extraInfo->toArray();
        $json = $message ? array_merge(['message' => $message], $this->extraInfo) : $this->extraInfo;
        return response()->json($json, $httpCode);
    }

    public function getExtraInfo()
    {
        return $this->extraInfo;
    }

    public function checkRequiredFields($fields, Request $request): bool
    {
        $missingFields = [];
        $fields = is_array($fields) ? $fields : [$fields];
        foreach ($fields as $field) {
            if (!$request->has($field)) {
                $missingFields[] = $field;
            }
        }
        $this->extraInfo = $missingFields;
        return !count($missingFields);
    }

    public function getCaller(int $minStepsBack = 1): ?string
    {
        $trace = debug_backtrace();
        if (isset($trace[$minStepsBack]['class'])) {
            $class = $trace[$minStepsBack]['class'];
        } else {
            return null;
        }
        for ($i = $minStepsBack, $iMax = count($trace); $i < $iMax; $i++) {
            if (isset($trace[$i]['class']) && $class !== $trace[$i]['class']) {
                return $trace[$i]['class'];
            }
        }
        return null;
    }

    public function isCallerController(): bool
    {
        return Str::of($this->getCaller(2))->endsWith('Controller');
    }

    public function permissionDenied(): JsonResponse
    {
        return $this->setResponseMessage('global.permission_denied', 403);
    }

    public function notSpecifiedError(): JsonResponse
    {
        return $this->setResponseMessage('global.not_specified_error', 400);
    }

    public abstract function getResponse(): JsonResponse;
}
