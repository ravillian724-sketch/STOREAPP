<?php

namespace App\Http\Controllers\Api\V1\ControlPlane;

use App\Exceptions\BuildProfileConflictException;
use App\Exceptions\BuildProfileValidationException;
use App\Http\Controllers\Controller;
use App\Models\AppBuildProfile;
use App\Services\ControlPlane\BuildProfileService;
use App\Support\ApiResponse;
use App\Support\ControlPlane\ConfiguredBuildProfile;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildProfileController extends Controller
{
    public function __construct(
        private readonly BuildProfileService $profiles,
    ) {}

    public function update(
        Request $request,
        string $provisioningPublicId,
    ): JsonResponse {
        $validated = $request->validate([
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/',
            ],
            'display_name' => [
                'required',
                'string',
                'max:100',
            ],
            'android_application_id' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/',
            ],
            'ios_bundle_id' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*){2,}$/',
            ],
            'version_name' => [
                'required',
                'string',
                'max:50',
                'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/',
            ],
            'build_number' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        try {
            $result = $this->profiles->configure(
                $provisioningPublicId,
                $validated,
                $request,
            );
        } catch (BuildProfileConflictException $error) {
            return ApiResponse::error(
                $request,
                'BUILD_PROFILE_CONFLICT',
                $error->getMessage(),
                409,
            );
        } catch (BuildProfileValidationException $error) {
            return ApiResponse::error(
                $request,
                'BUILD_PROFILE_INVALID',
                $error->getMessage(),
                422,
            );
        } catch (ModelNotFoundException) {
            return $this->notFound($request);
        }

        $response = ApiResponse::success(
            $request,
            $this->present($result),
            $result->created
                ? 201
                : 200,
        );

        $response->headers->set(
            'Cache-Control',
            'no-store, private',
        );

        return $response;
    }

    public function manifest(
        Request $request,
        string $provisioningPublicId,
    ): JsonResponse {
        try {
            $manifest = $this->profiles->manifest(
                $provisioningPublicId
            );
        } catch (BuildProfileValidationException $error) {
            return ApiResponse::error(
                $request,
                'BUILD_PROFILE_INVALID',
                $error->getMessage(),
                422,
            );
        } catch (ModelNotFoundException) {
            return $this->notFound($request);
        }

        $response = ApiResponse::success(
            $request,
            [
                'manifest' => $manifest,
            ],
        );

        $response->headers->set(
            'Cache-Control',
            'no-store, private',
        );

        return $response;
    }

    private function present(
        ConfiguredBuildProfile $result,
    ): array {
        $profile = $result->profile;

        return [
            'profile' => $this->profilePayload(
                $profile
            ),
            'created' => $result->created,
        ];
    }

    private function profilePayload(
        AppBuildProfile $profile,
    ): array {
        return [
            'id' => $profile->public_id,
            'slug' => $profile->slug,
            'display_name' => $profile->display_name,
            'android_application_id' => $profile->android_application_id,
            'ios_bundle_id' => $profile->ios_bundle_id,
            'version_name' => $profile->version_name,
            'build_number' => $profile->build_number,
            'is_active' => $profile->is_active,
        ];
    }

    private function notFound(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'BUILD_PROFILE_NOT_FOUND',
            'Provisioning or build profile was not found.',
            404,
        );
    }
}
