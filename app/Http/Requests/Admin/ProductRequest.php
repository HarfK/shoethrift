<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\SoleCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Rules shared by StoreProductRequest and UpdateProductRequest.
 */
abstract class ProductRequest extends FormRequest
{
    public const MAX_IMAGES = 10;

    public const MAX_IMAGE_KB = 5120;

    /**
     * How many images the product will still have before new uploads are added.
     */
    abstract protected function keptImageCount(): int;

    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            'size' => ['required', 'numeric', 'between:1,15', 'multiple_of:0.5'],
            'color' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', Rule::in(config('shop.materials'))],
            'insole_length_cm' => ['nullable', 'numeric', 'between:10,35', 'decimal:0,1'],
            'sole_condition' => ['nullable', Rule::enum(SoleCondition::class)],
            'box_included' => ['boolean'],
            'wear_notes' => ['nullable', 'string', 'max:1000'],
            'authenticity_notes' => ['nullable', 'string', 'max:1000'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'images' => ['nullable', 'array', 'max:'.max(0, self::MAX_IMAGES - $this->keptImageCount())],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_IMAGE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'size.between' => 'Enter a UK shoe size between 1 and 15.',
            'size.multiple_of' => 'Use whole or half UK sizes, e.g. 8 or 8.5.',
            'material.in' => 'Choose a material from the list.',
            'insole_length_cm.between' => 'Enter the insole length in cm, between 10 and 35.',
            'insole_length_cm.decimal' => 'Use at most one decimal place, e.g. 27.5.',
            'images.max' => 'A product can have at most '.self::MAX_IMAGES.' images.',
            'images.*.image' => 'Each upload must be an image.',
            'images.*.mimes' => 'Images must be JPG, PNG or WebP.',
            'images.*.max' => 'Each image must be 5 MB or smaller.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['category_id' => 'category'];
    }

    /**
     * Product::available() only counts in-stock items, so "available" with no stock would be invisible.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('stock')) {
                    return;
                }

                if ($this->input('status') === ProductStatus::Available->value && (int) $this->input('stock') < 1) {
                    $validator->errors()->add('stock', 'An available product needs at least 1 in stock. Set the status to Sold or Hidden instead.');
                }
            },
        ];
    }

    /**
     * The validated product columns, without the image fields.
     *
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        return $this->safe()->except(['images', 'primary_image_id', 'remove_images']);
    }
}
