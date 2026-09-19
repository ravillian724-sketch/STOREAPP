<?php

namespace App\Http\Controllers\Api\V1\ControlPlane;

use App\Exceptions\ProvisioningIdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Services\ControlPlane\StoreProvisioningService;
use App\Support\ApiResponse;
use App\Support\ControlPlane\ProvisionedStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class StoreProvisioningController extends Controller
{
    public function __construct(
        private readonly StoreProvisioningService $provisioning,
    ) {}

    public function store(
        Request $request,
    ): JsonResponse {
        $idempotencyKey = trim(
            (string) $request->header(
                'Idempotency-Key',
                '',
            )
        );

        if (
            preg_match(
                '/^[A-Za-z0-9._:-]{16,128}$/',
                $idempotencyKey,
            ) !== 1
        ) {
            return ApiResponse::error(
                $request,
                'INVALID_IDEMPOTENCY_KEY',
                'A valid Idempotency-Key header is required.',
                422,
            );
        }

        $validated = $request->validate([
            'tenant' => [
                'required',
                'array',
            ],
            'tenant.name_ar' => [
                'required',
                'string',
                'max:200',
            ],
            'tenant.name_en' => [
                'required',
                'string',
                'max:200',
            ],
            'tenant.country_code' => [
                'required',
                'string',
                'size:2',
                'regex:/^[A-Za-z]{2}$/',
            ],
            'tenant.currency_code' => [
                'required',
                'string',
                'size:3',
                'regex:/^[A-Za-z]{3}$/',
            ],
            'tenant.vat_rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'tenant.primary_color' => [
                'required',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'tenant.secondary_color' => [
                'required',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],

            'branch' => [
                'required',
                'array',
            ],
            'branch.code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9_-]+$/',
            ],
            'branch.name_ar' => [
                'required',
                'string',
                'max:200',
            ],
            'branch.name_en' => [
                'required',
                'string',
                'max:200',
            ],

            'app_instance' => [
                'required',
                'array',
            ],
            'app_instance.channel' => [
                'required',
                'string',
                'in:mobile,android,ios,web',
            ],

            'owner' => [
                'required',
                'array',
            ],
            'owner.name' => [
                'required',
                'string',
                'max:200',
            ],
            'owner.email' => [
                'required',
                'email',
                'max:254',
            ],
            'owner.password' => [
                'required',
                'string',
                Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers(),
            ],
        ]);

        try {
            $result =
                $this->provisioning->provision(
                    $validated,
                    $idempotencyKey,
                    $request,
                );
        } catch (
            ProvisioningIdempotencyConflictException
        ) {
            return ApiResponse::error(
                $request,
                'IDEMPOTENCY_CONFLICT',
                'Idempotency key was already used with different provisioning data.',
                409,
            );
        }

        $response = ApiResponse::success(
            $request,
            $this->present($result),
            $result->replayed
                ? 200
                : 201,
        );

        $response->headers->set(
            'Cache-Control',
            'no-store, private',
        );

        return $response;
    }

    private function present(
        ProvisionedStore $result,
    ): array {
        return [
            'provisioning' => [
                'id' => $result->receipt->public_id,
                'status' => $result->receipt->status,
                'replayed' => $result->replayed,
            ],

            'tenant' => [
                'id' => $result->tenant->id,
                'name_ar' => $result->tenant->name_ar,
                'name_en' => $result->tenant->name_en,
                'country_code' => $result->tenant->country_code,
                'currency_code' => $result->tenant->currency_code,
                'vat_rate' => $result->tenant->vat_rate,
                'is_active' => $result->tenant->is_active,
            ],

            'branch' => [
                'id' => $result->branch->id,
                'code' => $result->branch->code,
                'name_ar' => $result->branch->name_ar,
                'name_en' => $result->branch->name_en,
                'is_active' => $result->branch->is_active,
            ],

            'app_instance' => [
                'id' => $result->appInstance->id,
                'channel' => $result->appInstance->channel,
                'is_active' => $result->appInstance->is_active,
            ],

            'owner' => [
                'id' => $result->owner->id,
                'name' => $result->owner->name,
                'email' => $result->owner->email,
                'is_active' => $result->owner->is_active,
            ],

            'credential' => [
                'public_id' => $result->credential->public_id,
                'app_instance_key' => $result->appInstanceToken,
                'reissue_required' => $result
                    ->credentialReissueRequired(),
            ],
        ];
    }
}
