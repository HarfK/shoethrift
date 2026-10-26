<?php

namespace Tests\Feature\Reviews;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Half-star ratings and the star picker (including the regression behind the "only 1 star" bug).
 */
class HalfStarTest extends TestCase
{
    use BuildsReviews;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPurchase();
    }

    public function test_picker_radios_are_in_natural_order_with_matching_labels(): void
    {
        $html = $this->actingAs($this->customer)
            ->get(route('account.reviews.create', [$this->item->order->order_number, $this->item->id]))
            ->assertOk()
            ->getContent();

        // The bug: radios were listed 5→1 and only CSS flipped them back. They must be ascending
        // in the HTML itself so the value under the cursor is what gets submitted.
        preg_match_all('/<input type="radio" id="rating-(\d+)" name="rating" value="([\d.]+)"/', $html, $radios);
        $this->assertSame(['0.5', '1.0', '1.5', '2.0', '2.5', '3.0', '3.5', '4.0', '4.5', '5.0'], $radios[2]);
        $this->assertSame(array_map('strval', range(1, 10)), $radios[1]);

        // Each radio is followed by its own label, whose text says the same value.
        preg_match_all('/id="rating-(\d+)"[^>]*>\s*<label for="rating-(\d+)" title="([^"]+)" data-rating="([\d.]+)"/', $html, $pairs);
        $this->assertSame($pairs[1], $pairs[2]);
        $this->assertSame($radios[2], $pairs[4]);
        $this->assertSame(['0.5 stars', '1 star', '1.5 stars', '5 stars'], [$pairs[3][0], $pairs[3][1], $pairs[3][2], $pairs[3][9]]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function ratings(): array
    {
        return ['half a star' => ['0.5', '0.5'], 'three and a half' => ['3.5', '3.5'], 'five' => ['5', '5.0'], 'one' => ['1.0', '1.0']];
    }

    #[DataProvider('ratings')]
    public function test_half_star_ratings_are_stored_exactly(string $posted, string $stored): void
    {
        $this->postReview(['rating' => $posted])->assertSessionHasNoErrors();

        $this->assertSame($stored, Review::sole()->rating);
    }

    public function test_ratings_must_be_half_steps_between_half_and_five(): void
    {
        $this->postReview(['rating' => '4.25'])->assertSessionHasErrors(['rating' => 'Ratings go in half stars, e.g. 3.5.']);
        $this->postReview(['rating' => '0'])->assertSessionHasErrors(['rating' => 'Choose between half a star and 5 stars.']);
        $this->postReview(['rating' => '5.5'])->assertSessionHasErrors(['rating']);
        $this->postReview(['rating' => 'five'])->assertSessionHasErrors(['rating']);
        $this->postReview(['rating' => ''])->assertSessionHasErrors(['rating' => 'Choose a star rating.']);

        $this->assertSame(0, Review::count());
    }

    public function test_review_shows_half_stars_and_the_form_remembers_the_choice(): void
    {
        $this->postReview(['rating' => '3.5']);

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('Rated 3.5 out of 5')
            ->assertSee('style="width: 70%"', false);

        $review = Review::sole();
        $this->actingAs($this->customer)->get(route('account.reviews.edit', $review))
            ->assertSee('value="3.5"', false)
            ->assertSee('3.5 / 5');
        $this->assertMatchesRegularExpression('/value="3\.5"\s+checked/', $this->actingAs($this->customer)->get(route('account.reviews.edit', $review))->getContent());
    }

    public function test_averages_show_one_decimal_with_stars_rounded_to_the_nearest_half(): void
    {
        foreach (['4.5', '4.0', '4.5'] as $rating) { // average 4.333…
            $this->postReview(['rating' => $rating], $this->purchase());
        }
        $this->postReview(['rating' => '4.5']); // the setUp purchase too → average 4.375

        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertSee('4.4 · 4 reviews')
            ->assertSee('Rated 4.4 out of 5')
            ->assertSee('style="width: 90%"', false); // drawn as 4.5 stars

        $this->assertSame(4.5, Review::roundToHalf(4.3));
        $this->assertSame(4.0, Review::roundToHalf(4.2));
        $this->assertSame(0.5, Review::roundToHalf(0.6));
    }

    public function test_breakdown_buckets_half_stars(): void
    {
        foreach (['5.0', '4.5', '4.0', '3.5', '1.5', '0.5', '1.0'] as $rating) {
            $order = Order::factory()->create(['status' => OrderStatus::Completed, 'paid_at' => now()]);
            Review::factory()->for($order)->for($this->dunk)->create(['user_id' => $order->user_id, 'rating' => $rating]);
        }

        $this->assertSame([5 => 1, 4 => 2, 3 => 1, 2 => 0, 1 => 3], Review::breakdown(Review::query()));
        $this->get(route('shop.show', 'nike-dunk-low'))
            ->assertViewHas('ratingBreakdown', fn ($rows) => $rows->all() === [5 => 1, 4 => 2, 3 => 1, 2 => 0, 1 => 3]);
    }
}
