<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shoe preferences on the profile page (size, brands, budget), used for personal recommendations.
 */
class PreferenceController extends Controller
{
    public const MAX_BRANDS = 5;

    public const MAX_PRICE = 100000;

    /**
     * Brands customers can pick: the ones the shop has (not hidden), A to Z.
     *
     * @return list<string>
     */
    public static function brandOptions(): array
    {
        return Product::visible()->distinct()->orderBy('brand')->pluck('brand')->all();
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('preferences', [
            'shoe_size' => ['nullable', 'numeric', 'between:1,15', 'multiple_of:0.5'],
            'preferred_brands' => ['nullable', 'array', 'max:'.self::MAX_BRANDS],
            'preferred_brands.*' => ['string', Rule::in(self::brandOptions())],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_PRICE],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:'.self::MAX_PRICE,
                Rule::when($request->filled('min_price') && is_numeric($request->input('min_price')), 'gte:min_price')],
        ], [
            'shoe_size.between' => 'Choose a UK size between 1 and 15.',
            'shoe_size.multiple_of' => 'Use whole or half UK sizes, e.g. 8 or 8.5.',
            'preferred_brands.max' => 'Choose up to '.self::MAX_BRANDS.' brands.',
            'preferred_brands.*.in' => 'Choose brands from the list.',
            'max_price.gte' => 'The maximum price must be the same as or higher than the minimum.',
        ]);

        $request->user()->preference()->updateOrCreate([], [
            'shoe_size' => $data['shoe_size'] ?? null,
            'preferred_brands' => array_values(array_unique($data['preferred_brands'] ?? [])) ?: null,
            'min_price' => $data['min_price'] ?? null,
            'max_price' => $data['max_price'] ?? null,
        ]);

        return back()->with('success', 'Your shoe preferences were saved.');
    }
}
