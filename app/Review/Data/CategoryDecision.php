<?php

namespace App\Review\Data;

/**
 * The category CategoryAssigner chose for a draft, why (shown to the admin),
 * and whether the admin should check it.
 */
final readonly class CategoryDecision
{
    public function __construct(
        public ?int $categoryId,
        public string $reason,
        public bool $flagged,
    ) {}
}
