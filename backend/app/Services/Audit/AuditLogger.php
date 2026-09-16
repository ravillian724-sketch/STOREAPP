<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditPayloadSanitizer $sanitizer,
    ) {}

    public function record(
        string $action,
        string $subjectType,
        string|int|null $subjectId = null,
        ?User $actor = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?Request $request = null,
    ): AuditLog {
        $tenantId =
            $this->tenantContext->requireId();

        $action = trim($action);
        $subjectType = trim($subjectType);

        if (
            $action === '' ||
            mb_strlen($action) > 120
        ) {
            throw new InvalidArgumentException(
                'Invalid audit action.'
            );
        }

        if (
            $subjectType === '' ||
            mb_strlen($subjectType) > 160
        ) {
            throw new InvalidArgumentException(
                'Invalid audit subject type.'
            );
        }

        if (
            $actor !== null &&
            (int) $actor->tenant_id !==
                $tenantId
        ) {
            throw new LogicException(
                'Audit actor must belong to the active tenant.'
            );
        }

        $normalizedSubjectId =
            $subjectId === null
                ? null
                : trim((string) $subjectId);

        if (
            $normalizedSubjectId !== null &&
            mb_strlen(
                $normalizedSubjectId
            ) > 160
        ) {
            throw new InvalidArgumentException(
                'Invalid audit subject id.'
            );
        }

        $requestId = null;
        $httpMethod = null;
        $path = null;
        $ipAddress = null;

        if ($request !== null) {
            $value = trim(
                (string) $request->attributes
                    ->get(
                        'request_id',
                        ''
                    )
            );

            $requestId = $value === ''
                ? null
                : mb_substr(
                    $value,
                    0,
                    255,
                );

            $httpMethod = mb_substr(
                strtoupper(
                    $request->method()
                ),
                0,
                16,
            );

            $path = mb_substr(
                $request->path(),
                0,
                500,
            );

            $ip = $request->ip();

            $ipAddress =
                is_string($ip) &&
                mb_strlen($ip) <= 45
                    ? $ip
                    : null;
        }

        $now = now();

        return AuditLog::query()
            ->create([
                'actor_user_id' => $actor?->id,

                'action' => $action,

                'subject_type' => $subjectType,

                'subject_id' => $normalizedSubjectId,

                'request_id' => $requestId,

                'http_method' => $httpMethod,

                'path' => $path,

                'ip_address' => $ipAddress,

                'before_values' => $this->sanitizer->sanitize($before),

                'after_values' => $this->sanitizer->sanitize($after),

                'metadata' => $metadata === []
                        ? null
                        : $this->sanitizer->sanitize($metadata),

                'occurred_at' => $now,

                'created_at' => $now,
            ]);
    }
}
