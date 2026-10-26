<?php

namespace App\Http\Requests;

use App\Models\Review;
use App\Models\ReviewMedia;
use App\Services\Reviews\VideoDuration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Writing or editing a review: half-star rating, comment, up to 5 photos and 1 video (all checked
 * server-side). On edit, the route's {review} is the customer's own review; `remove_media[]`
 * lists its media to delete, and the photo limit counts the photos that stay.
 */
class ReviewRequest extends FormRequest
{
    /** Read in after(); reused by the controller so the file is only parsed once. */
    public ?float $videoDuration = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'numeric', 'between:0.5,5', 'multiple_of:0.5'],
            'condition_rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'min:'.Review::MIN_COMMENT, 'max:'.Review::MAX_COMMENT],
            'photos' => ['nullable', 'array', 'max:'.max(0, ReviewMedia::MAX_PHOTOS - $this->keptPhotoCount())],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.ReviewMedia::MAX_PHOTO_KB],
            'video' => ['nullable', 'file', 'mimetypes:'.implode(',', ReviewMedia::VIDEO_MIMES), 'extensions:mp4,webm', 'max:'.ReviewMedia::MAX_VIDEO_KB],
            'remove_media' => ['nullable', 'array'],
            'remove_media.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Choose a star rating.',
            'rating.between' => 'Choose between half a star and 5 stars.',
            'rating.multiple_of' => 'Ratings go in half stars, e.g. 3.5.',
            'condition_rating.required' => 'Tell us whether the condition matched the description.',
            'condition_rating.between' => 'Choose 1 to 5 for the condition.',
            'condition_rating.integer' => 'Choose 1 to 5 for the condition.',
            'photos.max' => 'A review can have at most '.ReviewMedia::MAX_PHOTOS.' photos.',
            'photos.*.image' => 'Each photo must be an image.',
            'photos.*.mimes' => 'Photos must be JPG, PNG or WebP.',
            'photos.*.max' => 'Each photo must be 5 MB or smaller.',
            'video.mimetypes' => 'The video must be MP4 or WebM.',
            'video.extensions' => 'The video must be MP4 or WebM.',
            'video.max' => 'The video must be 30 MB or smaller.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $video = $this->file('video');
                if (! $video || $validator->errors()->has('video')) {
                    return;
                }

                if ($this->keepsVideo()) {
                    $validator->errors()->add('video', 'Your review already has a video. Remove it to upload a new one.');

                    return;
                }

                $this->videoDuration = VideoDuration::seconds($video->getRealPath(), (string) $video->getMimeType());
                if ($this->videoDuration !== null && $this->videoDuration > ReviewMedia::MAX_VIDEO_SECONDS) {
                    $validator->errors()->add('video', 'The video must be '.ReviewMedia::MAX_VIDEO_SECONDS.' seconds or shorter (yours is '.round($this->videoDuration).' seconds).');
                }
            },
        ];
    }

    /**
     * The review being edited, or null when writing a new one.
     */
    public function review(): ?Review
    {
        $review = $this->route('review');

        return $review instanceof Review ? $review : null;
    }

    /**
     * @return list<int>
     */
    public function removeIds(): array
    {
        return array_values(array_map('intval', array_filter((array) $this->input('remove_media', []), 'is_numeric')));
    }

    private function keptPhotoCount(): int
    {
        return $this->review()?->media()->where('type', 'photo')->whereNotIn('id', $this->removeIds())->count() ?? 0;
    }

    private function keepsVideo(): bool
    {
        return (bool) $this->review()?->media()->where('type', 'video')->whereNotIn('id', $this->removeIds())->exists();
    }
}
