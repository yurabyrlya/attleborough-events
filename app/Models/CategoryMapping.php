<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source's own category label mapped to one of ours (PIPE-03 link 2, used
 * by App\Review\CategoryAssigner). Written only by App\Review\SaveRules.
 *
 * @property int $id
 * @property string $source_value
 * @property int $category_id
 */
#[Fillable(['source_value', 'category_id'])]
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
        ];
    }
}
