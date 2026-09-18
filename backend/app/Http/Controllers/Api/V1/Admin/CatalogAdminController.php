<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sku;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Audit\AdminAuditAction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogAdminController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(
            1,
            min(
                100,
                $request->integer('per_page', 25),
            ),
        );

        $page = Product::query()
            ->with([
                'skus' => fn ($query) => $query->orderBy('id'),
            ])
            ->orderBy('id')
            ->simplePaginate($perPage);

        return ApiResponse::success($request, [
            'items' => collect($page->items())
                ->map(
                    fn (Product $product): array => $this->serializeProduct($product)
                )
                ->values()
                ->all(),

            'pagination' => [
                'current_page' => $page->currentPage(),

                'per_page' => $page->perPage(),

                'has_more' => $page->hasMorePages(),
            ],
        ]);
    }

    public function storeProduct(
        Request $request,
    ): JsonResponse {
        $data = $request->validate([
            'name_ar' => [
                'required',
                'string',
                'max:255',
            ],

            'name_en' => [
                'required',
                'string',
                'max:255',
            ],

            'description_ar' => [
                'nullable',
                'string',
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'image_url' => [
                'nullable',
                'url:http,https',
                'max:2048',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $actor = $request->user(
            'sanctum'
        );

        $product = DB::transaction(
            function () use (
                $data,
                $actor,
                $request,
            ): Product {
                $product = Product::query()->create([
                    'name_ar' => trim(
                        $data['name_ar']
                    ),

                    'name_en' => trim(
                        $data['name_en']
                    ),

                    'description_ar' => $data['description_ar']
                            ?? null,

                    'description_en' => $data['description_en']
                            ?? null,

                    'image_url' => $data['image_url']
                            ?? null,

                    'is_active' => $data['is_active']
                            ?? true,
                ]);

                $product->setRelation(
                    'skus',
                    collect(),
                );

                $this->auditLogger->record(
                    action: AdminAuditAction::PRODUCT_CREATED,
                    subjectType: 'product',
                    subjectId: $product->id,
                    actor: $actor,
                    after: $this->productAuditSnapshot(
                        $product
                    ),
                    request: $request,
                );

                return $product;
            }
        );

        return ApiResponse::success(
            $request,
            [
                'product' => $this->serializeProduct($product),
            ],
            201,
        );
    }

    public function updateProduct(
        Request $request,
        string $productId,
    ): JsonResponse {
        $product = Product::query()
            ->find($productId);

        if ($product === null) {
            return ApiResponse::error(
                $request,
                'PRODUCT_NOT_FOUND',
                'Product was not found.',
                404,
            );
        }

        $data = $request->validate([
            'name_ar' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'name_en' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'description_ar' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'description_en' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'image_url' => [
                'sometimes',
                'nullable',
                'url:http,https',
                'max:2048',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $actor = $request->user(
            'sanctum'
        );

        DB::transaction(
            function () use (
                $product,
                $data,
                $actor,
                $request,
            ): void {
                $before =
                    $this->productAuditSnapshot(
                        $product
                    );

                foreach (
                    [
                        'name_ar',
                        'name_en',
                        'description_ar',
                        'description_en',
                        'image_url',
                        'is_active',
                    ] as $field
                ) {
                    if (
                        array_key_exists(
                            $field,
                            $data,
                        )
                    ) {
                        $product->{$field} =
                            $data[$field];
                    }
                }

                if (
                    array_key_exists(
                        'name_ar',
                        $data,
                    )
                ) {
                    $product->name_ar =
                        trim(
                            $data['name_ar']
                        );
                }

                if (
                    array_key_exists(
                        'name_en',
                        $data,
                    )
                ) {
                    $product->name_en =
                        trim(
                            $data['name_en']
                        );
                }

                $product->save();

                $changedFields =
                    array_keys($data);

                sort($changedFields);

                $this->auditLogger->record(
                    action: AdminAuditAction::PRODUCT_UPDATED,
                    subjectType: 'product',
                    subjectId: $product->id,
                    actor: $actor,
                    before: $before,
                    after: $this->productAuditSnapshot(
                        $product
                    ),
                    metadata: [
                        'changed_fields' => $changedFields,
                    ],
                    request: $request,
                );
            }
        );

        $product->load('skus');

        return ApiResponse::success($request, [
            'product' => $this->serializeProduct($product),
        ]);
    }

    public function storeSku(
        Request $request,
        string $productId,
    ): JsonResponse {
        $product = Product::query()
            ->find($productId);

        if ($product === null) {
            return ApiResponse::error(
                $request,
                'PRODUCT_NOT_FOUND',
                'Product was not found.',
                404,
            );
        }

        $this->normalizeSkuInput(
            $request
        );

        $tenantId = app(
            TenantContext::class
        )->requireId();

        $data = $request->validate(
            $this->skuRules(
                $tenantId,
            )
        );

        $actor = $request->user(
            'sanctum'
        );

        $sku = DB::transaction(
            function () use (
                $product,
                $data,
                $actor,
                $request,
            ): Sku {
                $sku = $product
                    ->skus()
                    ->create([
                        'code' => $data['code'],

                        'barcode' => $data['barcode']
                                ?? null,

                        'name_ar' => $data['name_ar']
                                ?? null,

                        'name_en' => $data['name_en']
                                ?? null,

                        'track_inventory' => $data[
                                'track_inventory'
                            ] ?? true,

                        'is_active' => $data['is_active']
                                ?? true,
                    ]);

                $this->auditLogger->record(
                    action: AdminAuditAction::SKU_CREATED,
                    subjectType: 'sku',
                    subjectId: $sku->id,
                    actor: $actor,
                    after: $this->skuAuditSnapshot(
                        $sku
                    ),
                    request: $request,
                );

                return $sku;
            }
        );

        return ApiResponse::success(
            $request,
            [
                'sku' => $this->serializeSku($sku),
            ],
            201,
        );
    }

    public function updateSku(
        Request $request,
        string $skuId,
    ): JsonResponse {
        $sku = Sku::query()
            ->find($skuId);

        if ($sku === null) {
            return ApiResponse::error(
                $request,
                'SKU_NOT_FOUND',
                'SKU was not found.',
                404,
            );
        }

        $this->normalizeSkuInput(
            $request
        );

        $tenantId = app(
            TenantContext::class
        )->requireId();

        $data = $request->validate(
            $this->skuRules(
                $tenantId,
                $sku,
                true,
            )
        );

        $actor = $request->user(
            'sanctum'
        );

        DB::transaction(
            function () use (
                $sku,
                $data,
                $actor,
                $request,
            ): void {
                $before =
                    $this->skuAuditSnapshot(
                        $sku
                    );

                foreach (
                    [
                        'code',
                        'barcode',
                        'name_ar',
                        'name_en',
                        'track_inventory',
                        'is_active',
                    ] as $field
                ) {
                    if (
                        array_key_exists(
                            $field,
                            $data,
                        )
                    ) {
                        $sku->{$field} =
                            $data[$field];
                    }
                }

                $sku->save();

                $this->auditLogger->record(
                    action: AdminAuditAction::SKU_UPDATED,
                    subjectType: 'sku',
                    subjectId: $sku->id,
                    actor: $actor,
                    before: $before,
                    after: $this->skuAuditSnapshot(
                        $sku
                    ),
                    request: $request,
                );
            }
        );

        return ApiResponse::success($request, [
            'sku' => $this->serializeSku($sku),
        ]);
    }

    private function productAuditSnapshot(
        Product $product,
    ): array {
        return [
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'image_url' => $product->image_url,
            'is_active' => (bool) $product->is_active,
        ];
    }

    private function skuAuditSnapshot(

        Sku $sku,
    ): array {
        return [
            'product_id' => (string) $sku->product_id,

            'code' => $sku->code,

            'barcode' => $sku->barcode,

            'name_ar' => $sku->name_ar,

            'name_en' => $sku->name_en,

            'track_inventory' => (bool) $sku->track_inventory,

            'is_active' => (bool) $sku->is_active,
        ];
    }

    private function normalizeSkuInput(
        Request $request,
    ): void {
        if ($request->has('code')) {
            $request->merge([
                'code' => mb_strtoupper(
                    trim(
                        (string)
                        $request->input('code')
                    )
                ),
            ]);
        }

        if ($request->has('barcode')) {
            $barcode = trim(
                (string)
                $request->input('barcode')
            );

            $request->merge([
                'barcode' => $barcode === ''
                        ? null
                        : $barcode,
            ]);
        }
    }

    private function skuRules(
        int $tenantId,
        ?Sku $sku = null,
        bool $partial = false,
    ): array {
        $codeUnique = Rule::unique(
            'skus',
            'code',
        )->where(
            fn ($query) => $query->where(
                'tenant_id',
                $tenantId,
            )
        );

        $barcodeUnique = Rule::unique(
            'skus',
            'barcode',
        )->where(
            fn ($query) => $query->where(
                'tenant_id',
                $tenantId,
            )
        );

        if ($sku !== null) {
            $codeUnique->ignore($sku->id);
            $barcodeUnique->ignore($sku->id);
        }

        return [
            'code' => [
                $partial
                    ? 'sometimes'
                    : 'required',

                'string',
                'max:100',
                $codeUnique,
            ],

            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                $barcodeUnique,
            ],

            'name_ar' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'name_en' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'track_inventory' => [
                'sometimes',
                'boolean',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    private function serializeProduct(
        Product $product,
    ): array {
        $product->loadMissing('skus');

        return [
            'id' => (string) $product->id,

            'name_ar' => $product->name_ar,

            'name_en' => $product->name_en,

            'description_ar' => $product->description_ar,

            'description_en' => $product->description_en,

            'image_url' => $product->image_url,

            'is_active' => $product->is_active,

            'skus' => $product->skus
                ->map(
                    fn (Sku $sku): array => $this->serializeSku($sku)
                )
                ->values()
                ->all(),
        ];
    }

    private function serializeSku(
        Sku $sku,
    ): array {
        return [
            'id' => (string) $sku->id,

            'product_id' => (string) $sku->product_id,

            'code' => $sku->code,

            'barcode' => $sku->barcode,

            'name_ar' => $sku->name_ar,

            'name_en' => $sku->name_en,

            'track_inventory' => $sku->track_inventory,

            'is_active' => $sku->is_active,
        ];
    }
}
