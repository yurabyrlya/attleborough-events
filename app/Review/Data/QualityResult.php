<?php

namespace App\Review\Data;

use App\Enums\QualityBand;

/**
 * A draft's quality: the 0-100 score, its band and the plain-English
 * reasons behind it, in display order (QualityScorer).
 */
final readonly class QualityResult
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $score,
        public QualityBand $band,
        public array $reasons,
    ) {}
}
