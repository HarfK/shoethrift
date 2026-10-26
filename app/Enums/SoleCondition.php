<?php

namespace App\Enums;

enum SoleCondition: string
{
    case Excellent = 'excellent';
    case Good = 'good';
    case Fair = 'fair';
    case Worn = 'worn';

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Excellent: barely worn',
            self::Good => 'Good: light wear',
            self::Fair => 'Fair: visible wear, plenty of grip left',
            self::Worn => 'Worn: heavy wear',
        };
    }
}
