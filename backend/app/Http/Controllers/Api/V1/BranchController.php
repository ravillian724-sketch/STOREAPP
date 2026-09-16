<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get([
                'id',
                'code',
                'name_ar',
                'name_en',
                'is_active',
            ]);

        return ApiResponse::success($request, [
            'branches' => $branches,
        ]);
    }
}
