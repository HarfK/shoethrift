<?php

namespace App\Enums;

enum ProductCondition: string
{
    case LikeNew = 'like_new';
    case Good = 'good';
    case Fair = 'fair';

    public function label(): string
    {
        return match ($this) {
            self::LikeNew => 'Like new',
            self::Good => 'Good',
            self::Fair => 'Fair',
        };
    }
}
