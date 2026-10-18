<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source's own category label mapped to one of ours (PIPE-03 link 2, used
 * by App\Review\CategoryAssigner). Written only by App\Review\SaveRules.
 * source_id scopes the mapping to one source (EXT-01); null maps for every
 * source. Unique on (coalesce(source_id, 0), lower(source_value)).
 *
 * @property int $id
 * @property string $source_value
 * @property int $category_id
 * @property int|null $source_id
 */
#[Fillable(['source_value', 'category_id', 'source_id'])]
class CategoryMapping extends Model
{
    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The one source this mapping is for; null for every source.
     *
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'source_id' => 'integer',
        ];
    }
}
