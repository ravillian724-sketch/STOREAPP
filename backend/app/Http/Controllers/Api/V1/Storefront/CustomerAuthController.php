<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use LogicException;

class CustomerAuthController extends Controller
{
    public function register(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:200',
            ],
            'email' => [
                'required',
                'email',
                'max:254',
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],
            'password' => [
                'required',
                'string',
                Password::min(8)
                    ->letters()
                    ->numbers(),
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $email = strtolower(
            trim(
                $validated['email']
            )
        );

        if (
            Customer::query()
                ->where(
                    'email',
                    $email,
                )
                ->exists()
        ) {
            return $this->alreadyExists(
                $request
            );
        }

        try {
            [$customer, $token] =
                DB::transaction(
                    function () use (
                        $validated,
                        $email,
                    ): array {
                        $customer =
                            Customer::query()
                                ->create([
                                    'name' => $validated['name'],
                                    'email' => $email,
                                    'phone' => $validated['phone']
                                        ?? null,
                                    'password' => $validated['password'],
                                    'is_active' => true,
                                ]);

                        return [
                            $customer,
                            $this->issueToken(
                                $customer,
                                $validated['device_name']
                                    ?? 'customer-device',
                            ),
                        ];
                    }
                );
        } catch (QueryException $error) {
            if (
                $this->isUniqueViolation(
                    $error
                )
            ) {
                return $this->alreadyExists(
                    $request
                );
            }

            throw $error;
        }

        return $this->noStore(
            ApiResponse::success(
                $request,
                $this->authenticatedPayload(
                    $customer,
                    $token,
                ),
                201,
            )
        );
    }

    public function login(
        Request $request,
    ): JsonResponse {
        $validated = $request->validate([
            'email' => [
                'required',
                'email',
                'max:254',
            ],
            'password' => [
                'required',
                'string',
            ],
            'device_name' => [
                'nullable',
                'string',
                'max:100',
            ],
        ]);

        $email = strtolower(
            trim(
                $validated['email']
            )
        );

        $customer =
            Customer::query()
                ->where(
                    'email',
                    $email,
                )
                ->where(
                    'is_active',
                    true,
                )
                ->first();

        if (
            $customer === null ||
            ! Hash::check(
                $validated['password'],
                $customer->password,
            )
        ) {
            return ApiResponse::error(
                $request,
                'INVALID_CREDENTIALS',
                'Invalid email or password.',
                401,
            );
        }

        $customer->forceFill([
            'last_login_at' => now(),
        ])->save();

        $token = $this->issueToken(
            $customer,
            $validated['device_name']
                ?? 'customer-device',
        );

        return $this->noStore(
            ApiResponse::success(
                $request,
                $this->authenticatedPayload(
                    $customer,
                    $token,
                ),
            )
        );
    }

    public function me(
        Request $request,
    ): JsonResponse {
        return $this->noStore(
            ApiResponse::success(
                $request,
                [
                    'customer' => $this->presentCustomer(
                        $this->customer(
                            $request
                        )
                    ),
                ],
            )
        );
    }

    public function logout(
        Request $request,
    ): JsonResponse {
        $this->customer(
            $request
        )
            ->currentAccessToken()
            ?->delete();

        return $this->noStore(
            ApiResponse::success(
                $request,
                [
                    'logged_out' => true,
                ],
            )
        );
    }

    private function noStore(
        JsonResponse $response,
    ): JsonResponse {
        $response->headers->set(
            'Cache-Control',
            'private, no-store',
        );

        return $response;
    }

    private function customer(
        Request $request,
    ): Customer {
        $customer =
            $request->attributes->get(
                'storefront_customer'
            );

        if (! $customer instanceof Customer) {
            throw new LogicException(
                'Customer middleware context is unavailable.'
            );
        }

        return $customer;
    }

    private function issueToken(
        Customer $customer,
        string $deviceName,
    ) {
        return $customer->createToken(
            trim($deviceName) !== ''
                ? trim($deviceName)
                : 'customer-device',
            ['customer'],
            now()->addDays(30),
        );
    }

    private function authenticatedPayload(
        Customer $customer,
        $token,
    ): array {
        return [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken
                ->expires_at
                ?->toIso8601String(),
            'customer' => $this->presentCustomer(
                $customer
            ),
        ];
    }

    private function presentCustomer(
        Customer $customer,
    ): array {
        return [
            'id' => $customer->public_id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'email_verified' => $customer->email_verified_at
                    !== null,
            'created_at' => $customer
                ->created_at
                ?->toIso8601String(),
        ];
    }

    private function alreadyExists(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'CUSTOMER_ALREADY_EXISTS',
            'A customer account already exists for this email.',
            409,
        );
    }

    private function isUniqueViolation(
        QueryException $error,
    ): bool {
        $sqlState =
            (string) (
                $error->errorInfo[0]
                ?? $error->getCode()
            );

        return in_array(
            $sqlState,
            [
                '23000',
                '23505',
            ],
            true,
        );
    }
}
