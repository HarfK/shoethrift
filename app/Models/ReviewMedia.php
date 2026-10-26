<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A review photo or video. Visible files are on the public disk; hiding moves the file to the
 * private `local` disk (same path) so a saved public link stops working, unhiding moves it back.
 */
class ReviewMedia extends Model
{
    public const PUBLIC_DISK = 'public';

    public const PRIVATE_DISK = 'local';

    public const MAX_PHOTOS = 5;

    public const MAX_PHOTO_KB = 5120;

    public const MAX_VIDEO_KB = 30720;

    public const MAX_VIDEO_SECONDS = 30;

    public const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Some MP4s are detected as application/mp4; the .mp4/.webm extension is required as well. */
    public const VIDEO_MIMES = ['video/mp4', 'application/mp4', 'video/webm'];

    protected $table = 'review_media';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'path',
        'mime',
        'size',
        'duration_seconds',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration_seconds' => 'decimal:2',
            'position' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Review, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Public URL, or null when hidden or the file is missing. Uses asset() like product images.
     */
    public function url(): ?string
    {
        return ! $this->isHidden() && Storage::disk(self::PUBLIC_DISK)->exists($this->path)
            ? asset('storage/'.$this->path)
            : null;
    }

    public function disk(): string
    {
        return $this->isHidden() ? self::PRIVATE_DISK : self::PUBLIC_DISK;
    }

    /**
     * Hide: mark hidden and move the file off the public disk.
     */
    public function hide(): void
    {
        if ($this->isHidden()) {
            return;
        }

        DB::transaction(fn () => $this->forceFill(['hidden_at' => now()])->save());
        self::moveFile($this->path, self::PUBLIC_DISK, self::PRIVATE_DISK);
    }

    public function unhide(): void
    {
        if (! $this->isHidden()) {
            return;
        }

        DB::transaction(fn () => $this->forceFill(['hidden_at' => null])->save());
        self::moveFile($this->path, self::PRIVATE_DISK, self::PUBLIC_DISK);
    }

    /**
     * Delete the row and its file (wherever it currently is).
     */
    public function deleteWithFile(): void
    {
        $disk = $this->disk();
        $this->delete();
        Storage::disk($disk)->delete($this->path);
    }

    private static function moveFile(string $path, string $from, string $to): void
    {
        $source = Storage::disk($from);
        if (! $source->exists($path)) {
            return;
        }

        Storage::disk($to)->writeStream($path, $source->readStream($path));
        $source->delete($path);
    }

    /**
     * @param  Builder<ReviewMedia>  $query
     */
    protected function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }
}
