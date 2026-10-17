<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A title keyword that picks a draft's category (PIPE-03 link 3, used by
 * App\Review\CategoryAssigner). Written only by App\Review\SaveRules and
 * RulesSeeder.
 *
 * @property int $id
 * @property string $keyword
 * @property int $category_id
 * @property int $priority
 */
#[Fillable(['keyword', 'category_id', 'priority'])]
class CategoryKeywordRule extends Model
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
            'priority' => 'integer',
        ];
    }
}
