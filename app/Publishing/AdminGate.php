<?php

namespace App\Publishing;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The single ADMIN_EMAIL rule (ADM-01).
 *
 * Only the user whose email matches config('app.admin_email'),
 * case-insensitively, is the admin. An empty or missing ADMIN_EMAIL denies
 * everyone. Used by the Filament panel gate (User::canAccessPanel) and, as
 * defence in depth beside it, by every write action in App\Publishing\Actions,
 * because Phase 2's approval path calls those actions outside the panel.
 */
final class AdminGate
{
    public static function allows(User $user): bool
    {
        $adminEmail = config('app.admin_email');

        return is_string($adminEmail)
            && $adminEmail !== ''
            && strcasecmp($user->email, $adminEmail) === 0;
    }

    /**
     * @throws AuthorizationException when the user is not the configured admin
     */
    public static function authorize(User $user, string $message): void
    {
        if (! self::allows($user)) {
            throw new AuthorizationException($message);
        }
    }
}
