<?php

namespace App\Services\Audit;

final class AuditPayloadSanitizer
{
    private const REDACTED = '[REDACTED]';

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'passphrase',
        'secret',
        'client_secret',
        'app_instance_secret',
        'token',
        'access_token',
        'refresh_token',
        'csrf_token',
        'x_csrf_token',
        'api_key',
        'app_instance_key',
        'authorization',
        'cookie',
        'set_cookie',
        'credential',
        'credentials',
        'private_key',
        'otp',
        'pin',
        'pan',
        'card_number',
        'cvv',
        'cvc',
        'security_code',
    ];

    public function sanitize(
        ?array $payload,
    ): ?array {
        if ($payload === null) {
            return null;
        }

        foreach (
            $payload as $key => $value
        ) {
            if (
                is_string($key) &&
                $this->isSensitiveKey($key)
            ) {
                $payload[$key] =
                    self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $payload[$key] =
                    $this->sanitize($value);
            }
        }

        return $payload;
    }

    private function isSensitiveKey(
        string $key,
    ): bool {
        $normalized = strtolower(
            str_replace(
                ['-', '.', ' '],
                '_',
                trim($key),
            )
        );

        if (
            in_array(
                $normalized,
                self::SENSITIVE_KEYS,
                true,
            )
        ) {
            return true;
        }

        foreach (
            [
                '_password',
                '_passphrase',
                '_secret',
                '_token',
                '_api_key',
                '_private_key',
                '_credential',
                '_credentials',
                '_cookie',
                '_otp',
                '_pin',
            ] as $suffix
        ) {
            if (
                str_ends_with(
                    $normalized,
                    $suffix,
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
