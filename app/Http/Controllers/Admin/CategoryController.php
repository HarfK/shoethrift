<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CategoryController extends Controller
{
    private const PER_PAGE = 15;

    public function index(): View
    {
        return view('admin.categories.index', [
            'categories' => Category::withCount('products')->orderBy('name')->paginate(self::PER_PAGE),
        ]);
    }

    public function create(): View
    {
        return view('admin.categories.create', ['category' => new Category]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $category = Category::create([
            ...$request->validated(),
            'slug' => Category::uniqueSlug($request->validated('name')),
        ]);

        return redirect()->route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" was created.");
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.edit', ['category' => $category]);
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->fill($request->validated());

        if ($category->isDirty('name')) {
            $category->slug = Category::uniqueSlug($category->name, $category->id);
        }

        $category->save();

        return redirect()->route('admin.categories.index')
            ->with('success', "Category \"{$category->name}\" was updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        $productCount = $category->products()->count();

        if ($productCount > 0) {
            return back()->with('error', $this->hasProductsMessage($category, $productCount));
        }

        try {
            $category->delete();
        } catch (QueryException $e) {
            // A product may have been added between the check above and the delete; the FK blocked it.
            $productCount = $category->products()->count();

            if ($productCount === 0) {
                throw $e;
            }

            return back()->with('error', $this->hasProductsMessage($category, $productCount));
        }

        return back()->with('success', "Category \"{$category->name}\" was deleted.");
    }

    private function hasProductsMessage(Category $category, int $productCount): string
    {
        return "\"{$category->name}\" can't be deleted because it still has {$productCount} "
            .str('product')->plural($productCount).'. Move them to another category or delete them first.';
    }
}
