<?php

namespace Tests\Unit;

use App\Services\Audit\AuditPayloadSanitizer;
use PHPUnit\Framework\TestCase;

class AuditPayloadSanitizerTest extends TestCase
{
    public function test_it_recursively_redacts_sensitive_values(): void
    {
        $result = (
            new AuditPayloadSanitizer
        )->sanitize([
            'name' => 'Safe',
            'password' => 'pw',
            'Authorization' => 'Bearer secret',
            'app.instance.key' => 'instance-secret',
            'Set-Cookie' => 'session=secret',
            'payment' => [
                'card-number' => '4111111111111111',
                'cvv' => '123',
                'access_token' => 'token-secret',
                'private_key' => 'private-secret',
                'token_count' => 3,
                'public_key' => 'public-value',
            ],
        ]);

        $this->assertSame(
            'Safe',
            $result['name'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['password'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['Authorization'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['app.instance.key'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['Set-Cookie'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['payment']['card-number'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['payment']['cvv'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['payment']['access_token'],
        );

        $this->assertSame(
            '[REDACTED]',
            $result['payment']['private_key'],
        );

        $this->assertSame(
            3,
            $result['payment']['token_count'],
        );

        $this->assertSame(
            'public-value',
            $result['payment']['public_key'],
        );
    }

    public function test_null_payload_remains_null(): void
    {
        $this->assertNull(
            (
                new AuditPayloadSanitizer
            )->sanitize(null)
        );
    }
}
