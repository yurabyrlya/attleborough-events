<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\Schemas\SourceForm;
use App\Filament\Resources\Sources\SourceResource;
use App\Models\Source;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * New sources are always saved inactive: is_active is not a form field and
 * not fillable, and turning a source live goes through a test run and
 * "Create drafts" on the edit page (D-01). The slug is made from the name
 * once, here, and keeps its value when the source is renamed.
 */
class CreateSource extends CreateRecord
{
    protected static string $resource = SourceResource::class;

    public const string SLUG_TAKEN = 'Another source with this name was saved at the same moment. Save again to use the next free web address.';

    private const int SLUG_BASE_MAX_LENGTH = 130;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = SourceForm::prepareForSave($data);
        $data['slug'] = self::uniqueSlug((string) $data['name']);

        return $data;
    }

    /**
     * The insert runs in its own savepoint, so a slug taken by a parallel
     * save (sources.slug unique) becomes a validation error rather than a
     * server error or an aborted transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the slug was taken in the meantime
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(fn (): Model => parent::handleRecordCreation($data));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['data.name' => self::SLUG_TAKEN]);
        }
    }

    /**
     * Str::slug of the name ("source" when nothing is left), with "-2",
     * "-3", ... appended until it is free.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = rtrim(substr(Str::slug($name), 0, self::SLUG_BASE_MAX_LENGTH), '-');
        $base = $base === '' ? 'source' : $base;

        $taken = Source::query()
            ->where('slug', $base)
            ->orWhere('slug', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base).'-%')
            ->pluck('slug')
            ->flip();

        $slug = $base;

        for ($suffix = 2; $taken->has($slug); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
