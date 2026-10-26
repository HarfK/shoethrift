<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\ReviewMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Saves a new or edited review with its media, then (re)flags it for admins.
 *
 * New files are stored inside the transaction and deleted again if it fails; files of removed
 * media are deleted only after the transaction commits (same pattern as product images).
 */
class SaveReview
{
    /**
     * @param  list<UploadedFile>  $photos
     * @param  list<int>  $removeMediaIds  ids of $review's media to delete (edit only)
     */
    public function handle(
        Review $review,
        float $rating,
        int $conditionRating,
        string $comment,
        array $photos = [],
        ?UploadedFile $video = null,
        ?float $videoDuration = null,
        array $removeMediaIds = [],
    ): Review {
        $editing = $review->exists;
        $stored = [];
        $removed = [];

        try {
            DB::transaction(function () use ($review, $rating, $conditionRating, $comment, $photos, $video, $videoDuration, $removeMediaIds, $editing, &$stored, &$removed) {
                $review->fill(['rating' => $rating, 'condition_rating' => $conditionRating, 'comment' => trim($comment)]);
                if ($editing && $review->isDirty(['rating', 'condition_rating', 'comment'])) {
                    $review->edited_at = now();
                }
                $review->save();

                if ($removeMediaIds !== []) {
                    $removed = $review->media()->whereIn('id', $removeMediaIds)->get()->all();
                    $review->media()->whereIn('id', $removeMediaIds)->delete();
                }

                $position = (int) $review->media()->where('type', 'photo')->max('position');
                foreach ($photos as $photo) {
                    $path = $photo->store("reviews/{$review->id}", ReviewMedia::PUBLIC_DISK);
                    $stored[] = $path;
                    $review->media()->create([
                        'type' => 'photo', 'path' => $path, 'mime' => (string) $photo->getMimeType(),
                        'size' => (int) $photo->getSize(), 'position' => ++$position,
                    ]);
                }

                if ($video) {
                    $path = $video->store("reviews/{$review->id}", ReviewMedia::PUBLIC_DISK);
                    $stored[] = $path;
                    $review->media()->create([
                        'type' => 'video', 'path' => $path, 'mime' => (string) $video->getMimeType(),
                        'size' => (int) $video->getSize(), 'duration_seconds' => $videoDuration,
                    ]);
                }

                // Adding or removing media is an edit too.
                if ($editing && ($photos !== [] || $video || $removed !== [])) {
                    $review->edited_at = now();
                    $review->save();
                }
            });
        } catch (Throwable $e) {
            Storage::disk(ReviewMedia::PUBLIC_DISK)->delete($stored);

            throw $e;
        }

        foreach ($removed as $item) {
            Storage::disk($item->disk())->delete($item->path);
        }

        $review->unsetRelation('media');
        $review->refreshFlag();

        return $review;
    }

    /**
     * Delete a review and all its files.
     */
    public function delete(Review $review): void
    {
        $media = $review->media()->get();

        DB::transaction(fn () => $review->delete()); // review_media rows cascade

        foreach ($media as $item) {
            Storage::disk($item->disk())->delete($item->path);
        }
        Storage::disk(ReviewMedia::PUBLIC_DISK)->deleteDirectory("reviews/{$review->id}");
        Storage::disk(ReviewMedia::PRIVATE_DISK)->deleteDirectory("reviews/{$review->id}");
    }
}
