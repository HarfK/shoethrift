<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One saved forecast for one Malaysian day. The dates are plain "Y-m-d" strings, on purpose:
 * they are calendar days, with no time or timezone to convert.
 */
class RevenueForecast extends Model
{
    /** How many days ahead each daily save covers, so one missed save doesn't leave a gap. */
    public const SAVE_DAYS = 7;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'forecast_date',
        'made_on',
        'forecast',
        'lower',
        'upper',
        'is_backfill',
        'model',
        'trained_until',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'forecast' => 'decimal:2',
            'lower' => 'decimal:2',
            'upper' => 'decimal:2',
            'is_backfill' => 'boolean',
        ];
    }
}
