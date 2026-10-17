<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One geofence entry (ADM-04): a postcode district (upper-case, no spaces,
 * e.g. NR17) or a place name. Written by App\Review\SaveRules and
 * RulesSeeder; enforced from Phase 4.
 *
 * @property int $id
 * @property string $type one of self::POSTCODE_DISTRICT, self::PLACE_NAME
 * @property string $value
 */
#[Fillable(['type', 'value'])]
class GeofenceTerm extends Model
{
    public const POSTCODE_DISTRICT = 'postcode_district';

    public const PLACE_NAME = 'place_name';
}
