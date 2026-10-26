<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewEditTest extends TestCase
{
    use BuildsReviews;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->setUpPurchase();
    }

    private function review(): Review
    {
        $this->postReview(['rating' => '4.5']);

        return Review::sole();
    }

    public function test_order_page_offers_edit_and_delete(): void
    {
        $review = $this->review();

        $this->actingAs($this->customer)->get(route('account.orders.show', $this->item->order->order_number))
            ->assertSee(['Your review:', route('account.reviews.edit', $review), 'Delete'], false);
        $this->actingAs($this->customer)->get(route('account.reviews.edit', $review))
            ->assertOk()->assertSee(['Edit your review', 'Save changes', 'Delete this review']);
    }

    public function test_editing_updates_the_review_and_marks_it_edited(): void
    {
        $review = $this->review();
        $this->assertNull($review->edited_at);

        $this->travel(2)->days();
        $this->updateReview($review, ['rating' => '3', 'comment' => 'After a week the left sole started squeaking a bit.'])
            ->assertRedirect(route('account.orders.show', $this->item->order->order_number))
            ->assertSessionHas('success', 'Your review was updated.');

        $review->refresh();
        $this->assertSame('3.0', $review->rating);
        $this->assertSame('After a week the left sole started squeaking a bit.', $review->comment);
        $this->assertNotNull($review->edited_at);
        $this->assertSame(1, Review::count(), 'Still one review for this product and order.');

        $this->get(route('shop.show', 'nike-dunk-low'))->assertSee('Edited '.$review->edited_at->shopDate());
    }

    public function test_saving_without_changes_is_not_an_edit(): void
    {
        $review = $this->review();

        $this->updateReview($review)->assertSessionHas('success');

        $this->assertNull($review->fresh()->edited_at);
    }

    public function test_editing_flags_the_review_and_a_hidden_review_stays_hidden(): void
    {
        $review = $this->review();
        $review->markChecked();
        $review->forceFill(['hidden_at' => now()])->save();

        $this->updateReview($review, ['comment' => 'Changed my mind about the fit, it is fine.'])
            ->assertSessionHas('success', 'Your review was updated. It stays hidden until ShoeThrift checks it.');

        $review->refresh();
        $this->assertTrue($review->isHidden());
        $this->assertTrue($review->isFlagged());
        $this->assertContains('edited', $review->flag_reasons);
    }

    public function test_edit_is_validated(): void
    {
        $review = $this->review();

        $this->updateReview($review, ['rating' => '6', 'comment' => 'short'])->assertSessionHasErrors(['rating', 'comment']);

        $this->assertSame('4.5', $review->fresh()->rating);
    }

    public function test_only_the_author_can_edit_or_delete(): void
    {
        $review = $this->review();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('account.reviews.edit', $review))->assertNotFound();
        $this->actingAs($stranger)->put(route('account.reviews.update', $review), ['rating' => '1', 'condition_rating' => '1', 'comment' => 'Not my review at all.'])->assertNotFound();
        $this->actingAs($stranger)->delete(route('account.reviews.destroy', $review))->assertNotFound();

        $this->assertSame('4.5', $review->fresh()->rating);
    }

    public function test_deleting_removes_review_and_files_and_allows_a_new_review(): void
    {
        $this->postReview(['photos' => [UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg')]]);
        $review = Review::sole();
        $path = $review->media()->sole()->path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->customer)->delete(route('account.reviews.destroy', $review))
            ->assertSessionHas('success', 'Your review was deleted.');

        $this->assertModelMissing($review);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseCount('review_media', 0);

        $this->actingAs($this->customer)->get(route('account.orders.show', $this->item->order->order_number))->assertSee('Write a review');
        $this->postReview(['rating' => '2'])->assertSessionHas('success');
        $this->assertSame('2.0', Review::sole()->rating);
    }

    public function test_cannot_write_a_second_review_for_the_same_order(): void
    {
        $this->review();

        $this->postReview()->assertSessionHas('error', 'You have already reviewed this pair for this order.');
        $this->assertSame(1, Review::count());
    }

    // --- "Condition matched the description?" -----------------------------------------

    public function test_condition_rating_is_required_whole_and_between_one_and_five(): void
    {
        $this->postReview(['condition_rating' => ''])
            ->assertSessionHasErrors(['condition_rating' => 'Tell us whether the condition matched the description.']);
        $this->postReview(['condition_rating' => '6'])->assertSessionHasErrors(['condition_rating' => 'Choose 1 to 5 for the condition.']);
        $this->postReview(['condition_rating' => '3.5'])->assertSessionHasErrors(['condition_rating']);
        $this->postReview(['condition_rating' => '0'])->assertSessionHasErrors(['condition_rating']);

        $this->assertSame(0, Review::count());
    }

    public function test_condition_rating_is_saved_and_prefilled_when_editing(): void
    {
        $this->actingAs($this->customer)->get(route('account.reviews.create', [$this->item->order->order_number, $this->item->id]))
            ->assertSee(['Condition matched the description?', 'Not at all', 'Exactly as described']);

        $this->postReview(['condition_rating' => '3'])->assertSessionHasNoErrors();
        $review = Review::sole();
        $this->assertSame(3, $review->condition_rating);

        $html = $this->actingAs($this->customer)->get(route('account.reviews.edit', $review))->getContent();
        $this->assertMatchesRegularExpression('/name="condition_rating" value="3"[^>]*checked/', $html);

        $this->updateReview($review, ['condition_rating' => '5'])->assertSessionHasNoErrors();
        $this->assertSame(5, $review->fresh()->condition_rating);
        $this->assertNotNull($review->fresh()->edited_at, 'Changing the condition answer is an edit.');
    }
}
