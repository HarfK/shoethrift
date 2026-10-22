<?php

namespace App\Http\Requests\Admin;

class StoreProductRequest extends ProductRequest
{
    protected function keptImageCount(): int
    {
        return 0;
    }
}
