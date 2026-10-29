<?php

namespace App\Sesame;

use App\Models\User;
use Illuminate\Support\Str;
use Waynestate\Sesame\Identity;
use Waynestate\Sesame\UserResolver as UserResolverContract;

/**
 * {{app_name}}'s side of a SESAME login.
 *
 * Every driver ends here: the core hands the app a verified identity, and
 * this class decides what a user is. It finds the user by their CAS
 * username and imports one when it has not been seen before. The name comes
 * from the identity's cn attribute when the provider releases it, and the
 * email falls back to the Wayne State address for the username when the
 * provider releases no mail attribute. Imported users get a random password
 * they can never use: sign-in goes through SESAME, never through a local
 * credential.
 */
class UserResolver implements UserResolverContract
{
    public function findOrImportUser(Identity $identity): mixed
    {
        $user = User::firstOrCreate(
            ['username' => $identity->username],
            [
                'name' => $identity->attribute('cn') ?? $identity->username,
                'email' => $identity->attribute('mail')
                    ?? $identity->attribute('email')
                    ?? $identity->username.'@wayne.edu',
                'password' => bcrypt(Str::random(40)),
            ],
        );

        if ($user->wasRecentlyCreated) {
            // The identity provider already verified this person, so the
            // imported user counts as verified from the first request.
            // forceFill: the column is deliberately left out of $fillable.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
