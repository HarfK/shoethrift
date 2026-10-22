<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Available = 'available';
    case Sold = 'sold';
    case Hidden = 'hidden';
}
