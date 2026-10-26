<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's photo for a return request. Stored on the private `local` disk and served only
 * to the customer who sent it and to admins (ReturnPhotoController).
 */
class ReturnRequestPhoto extends Model
{
    public const DISK = 'local';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'path',
    ];

    /**
     * @return BelongsTo<ReturnRequest, $this>
     */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }
}
