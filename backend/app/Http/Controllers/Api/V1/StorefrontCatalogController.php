<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Storefront\StorefrontCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCatalogController extends Controller
{
    public function __construct(
        private readonly StorefrontCatalogService $catalog,
    ) {}

    public function home(Request $request): JsonResponse
    {
        return $this->respond(
            $request,
            defaultPerPage: 16,
            allowSearch: false,
        );
    }

    public function index(Request $request): JsonResponse
    {
        return $this->respond(
            $request,
            defaultPerPage: 24,
            allowSearch: true,
        );
    }

    private function respond(
        Request $request,
        int $defaultPerPage,
        bool $allowSearch,
    ): JsonResponse {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $branch = $this->resolveBranch($request);

        if ($branch instanceof JsonResponse) {
            return $branch;
        }

        $perPage = max(
            1,
            min(
                60,
                $request->integer('per_page', $defaultPerPage),
            ),
        );

        $search = null;

        if ($allowSearch) {
            $validated = $request->validate([
                'q' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:100',
                ],
            ]);

            $search = $validated['q'] ?? null;
        }

        return ApiResponse::success(
            $request,
            $this->catalog->page(
                tenant: $tenant,
                branch: $branch,
                search: $search,
                perPage: $perPage,
            ),
        );
    }

    private function resolveBranch(
        Request $request,
    ): Branch|JsonResponse|null {
        $claimed = trim(
            (string) $request->header(
                'X-Branch-Id',
                '',
            )
        );

        if ($claimed === '') {
            return Branch::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        }

        if (! ctype_digit($claimed)) {
            return $this->branchNotFound($request);
        }

        $branch = Branch::query()
            ->whereKey((int) $claimed)
            ->where('is_active', true)
            ->first();

        if ($branch === null) {
            return $this->branchNotFound($request);
        }

        return $branch;
    }

    private function branchNotFound(
        Request $request,
    ): JsonResponse {
        return ApiResponse::error(
            $request,
            'BRANCH_NOT_FOUND',
            'The selected branch is unavailable.',
            404,
        );
    }
}
