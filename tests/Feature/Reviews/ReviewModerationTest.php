<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewModerationTest extends TestCase
{
    use BuildsReviews;
    use FakesVideos;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
        $this->setUpPurchase();
    }

    private function reviewWithMedia(): Review
    {
        $this->postReview(['rating' => '2', 'photos' => [UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg')], 'video' => $this->fakeMp4(10)]);

        return Review::sole();
    }

    public function test_flagged_reviews_are_listed_with_reasons_and_can_be_marked_checked(): void
    {
        $flagged = $this->reviewWithMedia();
        $this->postReview(['rating' => '5'], $this->purchase()); // not flagged

        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee('1 need attention'); // sidebar count
        $this->actingAs($this->admin)->get(route('admin.reviews.index', ['visibility' => 'flagged']))
            ->assertViewHas('reviews', fn ($reviews) => $reviews->pluck('id')->all() === [$flagged->id])
            ->assertSee(['Flagged:', 'Low rating (2★ or less)', 'Has photos or a video', 'Mark as checked']);

        $this->actingAs($this->admin)->patch(route('admin.reviews.checked', $flagged))->assertSessionHas('success');

        $this->assertFalse($flagged->fresh()->isFlagged());
        $this->actingAs($this->admin)->get(route('admin.reviews.index', ['visibility' => 'flagged']))
            ->assertViewHas('reviews', fn ($reviews) => $reviews->isEmpty());
    }

    public function test_hiding_a_photo_moves_it_off_the_public_disk_and_unhiding_restores_it(): void
    {
        $photo = $this->reviewWithMedia()->media()->where('type', 'photo')->sole();

        $this->actingAs($this->admin)->patch(route('admin.review-media.toggle', $photo))->assertSessionHas('success', 'Photo hidden.');

        $photo->refresh();
        $this->assertTrue($photo->isHidden());
        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertNull($photo->url());
        $this->get(route('shop.show', 'nike-dunk-low'))->assertDontSee('review-photo-'.$photo->id);

        $this->actingAs($this->admin)->patch(route('admin.review-media.toggle', $photo))->assertSessionHas('success', 'Photo visible again.');
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('local')->assertMissing($photo->path);
        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('review-photo-'.$photo->id);
    }

    public function test_deleting_a_video_removes_the_file_but_keeps_the_review(): void
    {
        $review = $this->reviewWithMedia();
        $video = $review->media()->where('type', 'video')->sole();

        $this->actingAs($this->admin)->delete(route('admin.review-media.destroy', $video))->assertSessionHas('success', 'Video deleted.');

        $this->assertModelMissing($video);
        Storage::disk('public')->assertMissing($video->path);
        $this->assertModelExists($review);
        $this->get(route('shop.show', 'nike-dunk-low'))->assertDontSee('<video', false);
    }

    public function test_deleting_a_hidden_media_file_removes_it_from_private_storage(): void
    {
        $photo = $this->reviewWithMedia()->media()->where('type', 'photo')->sole();
        $photo->hide();

        $this->actingAs($this->admin)->delete(route('admin.review-media.destroy', $photo));

        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_admin_deleting_a_review_removes_its_files(): void
    {
        $review = $this->reviewWithMedia();
        $paths = $review->media->pluck('path')->all();

        $this->actingAs($this->admin)->delete(route('admin.reviews.destroy', $review))->assertSessionHas('success', 'Review deleted.');

        $this->assertModelMissing($review);
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_hidden_reviews_and_media_never_show_publicly(): void
    {
        $review = $this->reviewWithMedia();
        $this->actingAs($this->admin)->patch(route('admin.reviews.toggle', $review));

        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('No reviews for this pair yet.');
        $this->get(route('reviews.index'))->assertSee('No reviews yet.');
    }

    public function test_customers_cannot_moderate_media(): void
    {
        $photo = $this->reviewWithMedia()->media()->first();

        $this->actingAs($this->customer)->patch(route('admin.review-media.toggle', $photo))->assertForbidden();
        $this->actingAs($this->customer)->delete(route('admin.review-media.destroy', $photo))->assertForbidden();
        $this->actingAs($this->customer)->patch(route('admin.reviews.checked', $photo->review))->assertForbidden();
        $this->assertFalse($photo->fresh()->isHidden());
    }
}
