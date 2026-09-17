<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $defaultBranch = Branch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        return ApiResponse::success($request, [
            'store' => [
                'tenant_id' => (string) $tenant->id,
                'brand_id' => null,
                'name_ar' => $tenant->name_ar,
                'name_en' => $tenant->name_en,
                'logo_url' => null,
                'app_icon_url' => null,
                'primary_color' => $tenant->primary_color,
                'secondary_color' => $tenant->secondary_color,
                'country_code' => $tenant->country_code,
                'currency_code' => $tenant->currency_code,
                'vat_rate' => (float) $tenant->vat_rate,
                'features' => [],
            ],
            'default_branch_id' => $defaultBranch === null
                ? null
                : (string) $defaultBranch->id,
        ]);
    }
}
