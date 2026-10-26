<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\ReviewMedia;
use App\Services\Reviews\VideoDuration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewMediaTest extends TestCase
{
    use BuildsReviews;
    use FakesVideos;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->setUpPurchase();
    }

    private function photo(string $name = 'shoe.jpg', int $kb = 200, string $mime = 'image/jpeg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, $mime);
    }

    // --- Photos -------------------------------------------------------------------

    public function test_up_to_five_photos_are_stored_on_the_public_disk_and_shown(): void
    {
        $this->postReview(['photos' => [
            $this->photo('1.jpg'), $this->photo('2.png', 200, 'image/png'), $this->photo('3.webp', 200, 'image/webp'), $this->photo('4.jpg'), $this->photo('5.jpg'),
        ]])->assertSessionHasNoErrors();

        $review = Review::sole();
        $this->assertSame(5, $review->media()->where('type', 'photo')->count());
        foreach ($review->media as $media) {
            Storage::disk('public')->assertExists($media->path);
            $this->assertStringStartsWith("reviews/{$review->id}/", $media->path);
        }

        $first = $review->media->first();
        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('href="#review-photo-'.$first->id.'"', false)   // thumbnail opens the lightbox
            ->assertSee('id="review-photo-'.$first->id.'"', false)      // lightbox overlay
            ->assertSee(['Customer photo 1 of 5', 'Next', 'Close', 'storage/'.$first->path]);
    }

    public function test_a_sixth_photo_is_rejected(): void
    {
        $this->postReview(['photos' => array_map(fn ($i) => $this->photo("{$i}.jpg"), range(1, 6))])
            ->assertSessionHasErrors(['photos' => 'A review can have at most 5 photos.']);

        $this->assertSame(0, Review::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_photo_type_and_size_are_checked(): void
    {
        $this->postReview(['photos' => [$this->photo('cv.pdf', 100, 'application/pdf')]])->assertSessionHasErrors(['photos.0']);
        $this->postReview(['photos' => [$this->photo('anim.gif', 100, 'image/gif')]])->assertSessionHasErrors(['photos.0' => 'Photos must be JPG, PNG or WebP.']);
        $this->postReview(['photos' => [$this->photo('huge.jpg', 5121)]])->assertSessionHasErrors(['photos.0' => 'Each photo must be 5 MB or smaller.']);

        $this->assertSame(0, Review::count());
    }

    public function test_photo_limit_counts_photos_kept_when_editing(): void
    {
        $this->postReview(['photos' => array_map(fn ($i) => $this->photo("{$i}.jpg"), range(1, 4))]);
        $review = Review::sole();

        $this->updateReview($review, ['photos' => [$this->photo('a.jpg'), $this->photo('b.jpg')]])
            ->assertSessionHasErrors(['photos' => 'A review can have at most 5 photos.']);

        // Removing one makes room for two.
        $removed = $review->media()->first();
        $this->updateReview($review, ['remove_media' => [$removed->id], 'photos' => [$this->photo('a.jpg'), $this->photo('b.jpg')]])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $review->media()->count());
        Storage::disk('public')->assertMissing($removed->path);
        $this->assertNotNull($review->fresh()->edited_at);
    }

    public function test_customers_cannot_remove_media_of_other_reviews(): void
    {
        $other = $this->purchase();
        $this->postReview(['photos' => [$this->photo()]], $other);
        $theirMedia = ReviewMedia::sole();
        $this->postReview();
        $mine = Review::latest('id')->first();

        $this->updateReview($mine, ['remove_media' => [$theirMedia->id]])->assertSessionHasNoErrors();

        $this->assertModelExists($theirMedia);
    }

    // --- Video -------------------------------------------------------------------

    public function test_short_mp4_is_stored_with_its_length_and_plays_on_the_review(): void
    {
        $this->postReview(['video' => $this->fakeMp4(12.5)])->assertSessionHasNoErrors();

        $video = ReviewMedia::sole();
        $this->assertSame('video', $video->type);
        $this->assertSame('12.50', $video->duration_seconds);
        Storage::disk('public')->assertExists($video->path);

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('<video src="'.$video->url().'" controls', false);
    }

    public function test_short_webm_is_accepted(): void
    {
        $this->postReview(['video' => $this->fakeWebm(8.25)])->assertSessionHasNoErrors();

        $this->assertSame('8.25', ReviewMedia::sole()->duration_seconds);
        $this->assertNotContains('video_length_unknown', Review::sole()->flag_reasons);
    }

    public function test_videos_over_30_seconds_are_rejected_even_with_moov_at_the_end(): void
    {
        $this->postReview(['video' => $this->fakeMp4(45, 'long.mp4', moovAtEnd: true)])
            ->assertSessionHasErrors(['video' => 'The video must be 30 seconds or shorter (yours is 45 seconds).']);
        $this->postReview(['video' => $this->fakeWebm(31)])->assertSessionHasErrors('video');

        $this->assertSame(0, Review::count());
    }

    public function test_video_with_unknown_length_is_accepted_but_flagged(): void
    {
        $this->postReview(['video' => $this->fakeWebm(null)])->assertSessionHasNoErrors();

        $this->assertNull(ReviewMedia::sole()->duration_seconds);
        $this->assertContains('video_length_unknown', Review::sole()->flag_reasons);
    }

    public function test_video_type_size_and_count_are_checked(): void
    {
        $this->postReview(['video' => UploadedFile::fake()->create('clip.avi', 100, 'video/x-msvideo')])
            ->assertSessionHasErrors(['video' => 'The video must be MP4 or WebM.']);
        $this->postReview(['video' => UploadedFile::fake()->create('fake.mp4', 100, 'text/plain')])
            ->assertSessionHasErrors(['video']);
        $this->postReview(['video' => UploadedFile::fake()->create('big.mp4', 30721, 'video/mp4')])
            ->assertSessionHasErrors(['video' => 'The video must be 30 MB or smaller.']);

        // One video per review: a second needs the first removed.
        $this->postReview(['video' => $this->fakeMp4(5)]);
        $review = Review::sole();
        $this->updateReview($review, ['video' => $this->fakeMp4(6)])
            ->assertSessionHasErrors(['video' => 'Your review already has a video. Remove it to upload a new one.']);
        $this->updateReview($review, ['remove_media' => [ReviewMedia::sole()->id], 'video' => $this->fakeMp4(6)])
            ->assertSessionHasNoErrors();
        $this->assertSame('6.00', ReviewMedia::sole()->duration_seconds);
    }

    public function test_video_duration_reader(): void
    {
        // Keep each fake in a variable: its temp file is deleted when the object goes away.
        $mp4 = $this->fakeMp4(12.5);
        $webm = $this->fakeWebm(8.25);
        $noDuration = $this->fakeWebm(null);
        $notAVideo = $this->photo();

        $this->assertSame(12.5, VideoDuration::seconds($mp4->getRealPath(), 'video/mp4'));
        $this->assertSame(8.25, VideoDuration::seconds($webm->getRealPath(), 'video/webm'));
        $this->assertNull(VideoDuration::seconds($noDuration->getRealPath(), 'video/webm'));
        $this->assertNull(VideoDuration::seconds($notAVideo->getRealPath(), 'video/mp4'));
    }

    // --- Flags and limits ----------------------------------------------------------

    public function test_reviews_with_media_or_low_ratings_are_flagged(): void
    {
        $this->postReview(['rating' => '5', 'photos' => [$this->photo()]]);
        $this->assertSame(['has_media'], Review::sole()->flag_reasons);

        Review::query()->delete();
        $this->postReview(['rating' => '1.5']);
        $this->assertSame(['low_rating'], Review::sole()->flag_reasons);

        Review::query()->delete();
        $this->postReview(['rating' => '4']);
        $this->assertFalse(Review::sole()->isFlagged());
    }

    public function test_too_large_uploads_get_a_friendly_message(): void
    {
        Route::post('/_test/too-large', fn () => throw new PostTooLargeException)->middleware('web');

        $this->from(route('home'))->post('/_test/too-large')
            ->assertRedirect(route('home'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Your upload was too large'));
    }
}
