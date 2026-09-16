<?php

namespace App\Services;

use App\Models\AppInstance;
use App\Models\AppInstanceCredential;
use App\Support\AppInstance\AppInstanceToken;
use App\Support\AppInstance\IssuedAppInstanceCredential;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class AppInstanceCredentialService
{
    public function __construct(
        private readonly AppInstanceToken $tokenCodec,
    ) {}

    public function issue(
        AppInstance $instance,
        ?\DateTimeInterface $expiresAt = null,
    ): IssuedAppInstanceCredential {
        $material = $this->tokenCodec->generate();

        $credential = $instance
            ->credentials()
            ->create([
                'public_id' => $material['public_id'],
                'secret_hash' => $this->tokenCodec
                    ->hashSecret(
                        $material['secret'],
                    ),
                'valid_from' => now(),
                'expires_at' => $expiresAt,
            ]);

        return new IssuedAppInstanceCredential(
            credential: $credential,
            token: $material['token'],
        );
    }

    public function rotate(
        AppInstanceCredential $current,
        int $graceSeconds = 600,
    ): IssuedAppInstanceCredential {
        if ($graceSeconds < 0) {
            throw new InvalidArgumentException(
                'Rotation grace period cannot be negative.'
            );
        }

        return DB::transaction(function () use (
            $current,
            $graceSeconds,
        ): IssuedAppInstanceCredential {
            $locked = AppInstanceCredential::query()
                ->with('appInstance')
                ->lockForUpdate()
                ->findOrFail($current->id);

            if ($locked->revoked_at !== null) {
                throw new LogicException(
                    'A revoked credential cannot be rotated.'
                );
            }

            $issued = $this->issue(
                $locked->appInstance,
            );

            $graceUntil = now()
                ->addSeconds($graceSeconds);

            if (
                $locked->expires_at === null ||
                $locked->expires_at->greaterThan(
                    $graceUntil
                )
            ) {
                $locked->expires_at = $graceUntil;
                $locked->save();
            }

            return $issued;
        });
    }

    public function revoke(
        AppInstanceCredential $credential,
    ): void {
        if ($credential->revoked_at !== null) {
            return;
        }

        $credential->forceFill([
            'revoked_at' => now(),
        ])->save();
    }
}
