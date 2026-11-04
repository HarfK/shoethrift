<?php

namespace App\Services\Recommendations;

/**
 * What PersonalScore knows about one customer, built once per request by PersonalScore::profileFor().
 */
final class PersonalProfile
{
    /**
     * @param  ?float  $size  saved size, else the most frequent size they interacted with
     * @param  list<string>  $preferredBrands  saved brands, lower case
     * @param  array<string, float>  $brandAffinity  lower-case brand => weight / highest weight (0-1)
     * @param  array<int, float>  $categoryAffinity  category id => weight / highest weight (0-1)
     * @param  ?array{0: ?float, 1: ?float}  $budget  [min, max]; either end may be open (null)
     */
    public function __construct(
        public readonly ?float $size = null,
        public readonly array $preferredBrands = [],
        public readonly array $brandAffinity = [],
        public readonly array $categoryAffinity = [],
        public readonly ?array $budget = null,
        public readonly bool $hasData = false,
    ) {}
}
