<?php

namespace App\Services\Payment;

use App\Models\PaymentAttempt;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PaymentProviderReferenceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function bind(
        PaymentAttempt $attempt,
        string $providerReference,
    ): PaymentAttempt {
        $tenantId =
            $this->tenantContext->requireId();

        if (
            (int) $attempt->tenant_id !==
            $tenantId
        ) {
            throw new LogicException(
                'Payment attempt must belong to the active tenant.'
            );
        }

        $providerReference =
            $this->normalizeReference(
                $providerReference
            );

        return DB::transaction(
            function () use (
                $attempt,
                $providerReference,
            ): PaymentAttempt {
                $locked =
                    PaymentAttempt::query()
                        ->whereKey(
                            $attempt->id
                        )
                        ->lockForUpdate()
                        ->first();

                if ($locked === null) {
                    throw new LogicException(
                        'Payment attempt is not accessible.'
                    );
                }

                if (
                    $locked->provider_reference ===
                    $providerReference
                ) {
                    return $locked;
                }

                if (
                    $locked->provider_reference !==
                    null
                ) {
                    throw new LogicException(
                        'Provider reference is immutable once assigned.'
                    );
                }

                $locked->provider_reference =
                    $providerReference;

                $locked->save();

                return $locked->refresh();
            }
        );
    }

    private function normalizeReference(
        string $value,
    ): string {
        $value =
            trim(
                $value
            );

        if (
            $value === '' ||
            mb_strlen($value) > 191 ||
            preg_match(
                '/[\x00-\x1F\x7F]/',
                $value,
            ) === 1
        ) {
            throw new InvalidArgumentException(
                'Invalid provider reference.'
            );
        }

        return $value;
    }
}
