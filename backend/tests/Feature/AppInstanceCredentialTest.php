<?php

namespace Tests\Feature;

use App\Models\AppInstance;
use App\Models\Tenant;
use App\Services\AppInstanceCredentialService;
use App\Support\AppInstance\AppInstanceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppInstanceCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function createInstance(): AppInstance
    {
        $tenant = Tenant::query()->create([
            'name_ar' => 'متجر',
            'name_en' => 'Store',
            'country_code' => 'SA',
            'currency_code' => 'SAR',
            'vat_rate' => 15,
            'primary_color' => '#000000',
            'secondary_color' => '#FFFFFF',
            'is_active' => true,
        ]);

        return AppInstance::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => 'mobile',
            'is_active' => true,
        ]);
    }

    public function test_plaintext_token_is_never_stored(): void
    {
        $issued = app(
            AppInstanceCredentialService::class
        )->issue(
            $this->createInstance()
        );

        $parsed = app(
            AppInstanceToken::class
        )->parse($issued->token);

        $this->assertNotNull($parsed);

        $credential = $issued
            ->credential
            ->fresh();

        $this->assertNotSame(
            $parsed['secret'],
            $credential->secret_hash,
        );

        $this->assertStringNotContainsString(
            $parsed['secret'],
            $credential->secret_hash,
        );
    }

    public function test_valid_token_resolves_store(): void
    {
        $issued = app(
            AppInstanceCredentialService::class
        )->issue(
            $this->createInstance()
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $issued->token,
            )
            ->postJson('/api/v1/bootstrap')
            ->assertOk();
    }

    public function test_modified_secret_is_rejected(): void
    {
        $issued = app(
            AppInstanceCredentialService::class
        )->issue(
            $this->createInstance()
        );

        $tampered = substr(
            $issued->token,
            0,
            -1,
        ).(
            str_ends_with(
                $issued->token,
                'A',
            )
                ? 'B'
                : 'A'
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $tampered,
            )
            ->postJson('/api/v1/bootstrap')
            ->assertNotFound()
            ->assertJsonPath(
                'error.code',
                'APP_INSTANCE_NOT_FOUND',
            );
    }

    public function test_revoked_token_is_rejected(): void
    {
        $service = app(
            AppInstanceCredentialService::class
        );

        $issued = $service->issue(
            $this->createInstance()
        );

        $service->revoke(
            $issued->credential
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $issued->token,
            )
            ->postJson('/api/v1/bootstrap')
            ->assertNotFound();
    }

    public function test_expired_token_is_rejected(): void
    {
        $issued = app(
            AppInstanceCredentialService::class
        )->issue(
            $this->createInstance(),
            now()->subSecond(),
        );

        $this
            ->withHeader(
                'X-App-Instance-Key',
                $issued->token,
            )
            ->postJson('/api/v1/bootstrap')
            ->assertNotFound();
    }

    public function test_rotation_supports_grace_period(): void
    {
        Carbon::setTestNow(
            Carbon::parse(
                '2026-09-16 12:00:00'
            )
        );

        try {
            $service = app(
                AppInstanceCredentialService::class
            );

            $old = $service->issue(
                $this->createInstance()
            );

            $new = $service->rotate(
                $old->credential,
                graceSeconds: 60,
            );

            $this
                ->withHeader(
                    'X-App-Instance-Key',
                    $old->token,
                )
                ->postJson('/api/v1/bootstrap')
                ->assertOk();

            $this
                ->withHeader(
                    'X-App-Instance-Key',
                    $new->token,
                )
                ->postJson('/api/v1/bootstrap')
                ->assertOk();

            Carbon::setTestNow(
                now()->addSeconds(61)
            );

            $this
                ->withHeader(
                    'X-App-Instance-Key',
                    $old->token,
                )
                ->postJson('/api/v1/bootstrap')
                ->assertNotFound();

            $this
                ->withHeader(
                    'X-App-Instance-Key',
                    $new->token,
                )
                ->postJson('/api/v1/bootstrap')
                ->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }
}
