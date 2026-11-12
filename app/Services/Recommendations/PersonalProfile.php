<?php

namespace App\Services\Recommendations;

/**
 * What PersonalScore knows about one customer, built once per request by PersonalScore::profileFor().
 * History-based values are only filled when there is enough recent evidence (PersonalScore::MIN_INTERACTIONS).
 */
final class PersonalProfile
{
    /**
     * @param  ?float  $size  saved size, else the size with the most (decayed) interaction weight
     * @param  list<string>  $preferredBrands  saved brands, lower case
     * @param  array<string, float>  $brandAffinity  lower-case brand => weight / highest weight (0-1)
     * @param  array<string, int>  $brandCounts  lower-case brand => number of interactions
     * @param  array<int, float>  $categoryAffinity  category id => weight / highest weight (0-1)
     * @param  array<int, int>  $categoryCounts  category id => number of interactions
     * @param  ?array{0: ?float, 1: ?float}  $budget  [min, max]; either end may be open (null)
     */
    public function __construct(
        public readonly ?float $size = null,
        public readonly array $preferredBrands = [],
        public readonly array $brandAffinity = [],
        public readonly array $brandCounts = [],
        public readonly array $categoryAffinity = [],
        public readonly array $categoryCounts = [],
        public readonly ?array $budget = null,
        public readonly bool $hasData = false,
    ) {}

    /**
     * Within ±0.5 UK of the customer's size (always true when the size isn't known).
     */
    public function fitsSize(float $size): bool
    {
        return $this->size === null || abs($size - $this->size) <= PersonalScore::SIZE_GATE;
    }

    /**
     * A saved brand, or one with enough interactions to name it in a label.
     */
    public function likesBrand(string $brand): bool
    {
        $brand = mb_strtolower($brand);

        return in_array($brand, $this->preferredBrands, true)
            || $this->clearlyBrowsed($this->brandCounts[$brand] ?? 0, $this->brandAffinity[$brand] ?? 0);
    }

    public function browsesCategory(int $categoryId): bool
    {
        return $this->clearlyBrowsed($this->categoryCounts[$categoryId] ?? 0, $this->categoryAffinity[$categoryId] ?? 0);
    }

    private function clearlyBrowsed(int $count, float $share): bool
    {
        return $count >= PersonalScore::LABEL_MIN_INTERACTIONS && $share >= PersonalScore::LABEL_MIN_SHARE;
    }
}
