<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends ProductRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Image ids must belong to the product being edited, not just exist.
        $ownImage = Rule::exists('product_images', 'id')->where('product_id', $this->product()->id);

        return [
            ...parent::rules(),
            'primary_image_id' => ['nullable', 'integer', $ownImage],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer', $ownImage],
        ];
    }

    protected function keptImageCount(): int
    {
        return $this->product()->images()
            ->whereKeyNot(array_filter((array) $this->input('remove_images', []), 'is_numeric'))
            ->count();
    }

    private function product(): Product
    {
        return $this->route('product');
    }
}
