<?php

namespace App\Support\AppInstance;

use Illuminate\Support\Str;
use RuntimeException;

final class AppInstanceToken
{
    private const PREFIX = 'si1';

    public function __construct(
        private readonly string $pepper,
    ) {
        if (strlen($this->pepper) < 32) {
            throw new RuntimeException(
                'APP_INSTANCE_CREDENTIAL_PEPPER must contain at least 32 characters.'
            );
        }
    }

    /**
     * @return array{
     *     public_id: string,
     *     secret: string,
     *     token: string
     * }
     */
    public function generate(): array
    {
        $publicId = strtoupper(
            (string) Str::ulid()
        );

        $secret = rtrim(
            strtr(
                base64_encode(random_bytes(32)),
                '+/',
                '-_',
            ),
            '=',
        );

        return [
            'public_id' => $publicId,
            'secret' => $secret,
            'token' => self::PREFIX.'.'.$publicId.'.'.$secret,
        ];
    }

    /**
     * @return array{
     *     public_id: string,
     *     secret: string
     * }|null
     */
    public function parse(string $token): ?array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 3) {
            return null;
        }

        [$prefix, $publicId, $secret] = $parts;

        if ($prefix !== self::PREFIX) {
            return null;
        }

        if (
            preg_match(
                '/^[0-9A-HJKMNP-TV-Z]{26}$/i',
                $publicId,
            ) !== 1
        ) {
            return null;
        }

        if (
            preg_match(
                '/^[A-Za-z0-9_-]{43}$/',
                $secret,
            ) !== 1
        ) {
            return null;
        }

        return [
            'public_id' => strtoupper($publicId),
            'secret' => $secret,
        ];
    }

    public function hashSecret(string $secret): string
    {
        return hash_hmac(
            'sha256',
            $secret,
            $this->pepper,
        );
    }

    public function verify(
        string $secret,
        string $expectedHash,
    ): bool {
        return hash_equals(
            $expectedHash,
            $this->hashSecret($secret),
        );
    }
}
