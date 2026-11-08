<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProductCondition;
use App\Enums\SoleCondition;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Ml\MlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Suggest price" on the product form (AJAX). Sends the current form values to the ML API and returns
 * its suggestion; nothing is saved, the admin decides. Errors come back as plain sentences in "errors".
 */
class PriceSuggestionController extends Controller
{
    /** Readable names for the ML API's field names. */
    private const FIELD_LABELS = [
        'brand' => 'Brand', 'name' => 'Name', 'category' => 'Category', 'condition' => 'Condition', 'sole_condition' => 'Sole condition',
        'box_included' => 'Original box', 'size' => 'Size (UK)', 'color' => 'Colour', 'material' => 'Material', 'retail_price' => 'Retail price',
    ];

    public function __invoke(Request $request, MlService $ml): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:100'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            'size' => ['required', 'numeric'],
            'sole_condition' => ['nullable', Rule::enum(SoleCondition::class)],
            'box_included' => ['nullable', 'boolean'],
            'color' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', 'string', 'max:50'],
            'retail_price' => ['nullable', 'numeric', 'gt:0'],
        ], [
            'name.required' => 'Enter the product name first.',
            'brand.required' => 'Enter the brand first.',
            'category_id.required' => 'Choose a category first.',
            'condition.required' => 'Choose the condition first.',
            'size.required' => 'Enter the UK size first.',
            'retail_price.gt' => 'The retail price must be more than RM0.',
        ]);

        $result = $ml->recommendPrice([
            'brand' => $data['brand'],
            'name' => $data['name'],
            'category' => Category::find($data['category_id'])->name,
            'condition' => $data['condition'],
            'sole_condition' => $data['sole_condition'] ?? null,
            'box_included' => (bool) ($data['box_included'] ?? false),
            'size' => (float) $data['size'],
            'color' => filled($data['color'] ?? null) ? $data['color'] : null,
            'material' => filled($data['material'] ?? null) ? $data['material'] : null,
            'retail_price' => filled($data['retail_price'] ?? null) ? (float) $data['retail_price'] : null,
        ]);

        if ($result->ok()) {
            return response()->json($result->data);
        }

        if ($result->status === 422) {
            return response()->json([
                'message' => 'The price model could not use these details:',
                'errors' => array_map($this->plain(...), $result->details),
            ], 422);
        }

        return response()->json(['message' => $result->error, 'errors' => []], 503);
    }

    /** "size: must be between 3.0 and 12.0 ..." -> "Size (UK): must be between 3.0 and 12.0 ...". */
    private function plain(string $detail): string
    {
        [$field, $message] = array_pad(explode(':', $detail, 2), 2, null);
        if ($message === null || ! isset(self::FIELD_LABELS[trim($field)])) {
            return ucfirst($detail);
        }

        return self::FIELD_LABELS[trim($field)].':'.$message;
    }
}
