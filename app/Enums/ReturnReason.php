<?php

namespace App\Enums;

enum ReturnReason: string
{
    case DoesntFit = 'doesnt_fit';
    case NotAsDescribed = 'not_as_described';
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DoesntFit => "Doesn't fit",
            self::NotAsDescribed => 'Not as described (condition or details)',
            self::Damaged => 'Arrived damaged',
            self::WrongItem => 'Wrong item sent',
            self::ChangedMind => 'Changed my mind',
            self::Other => 'Other',
        };
    }
}
