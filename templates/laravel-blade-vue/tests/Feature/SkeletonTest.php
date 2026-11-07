<?php

use App\Models\User;
use App\Sesame\UserResolver;

/*
|--------------------------------------------------------------------------
| Skeleton tests
|--------------------------------------------------------------------------
|
| These are the app's first tests: proof that the loaf Sourdough baked
| holds its shape, so you extend them instead of starting from scratch.
| They sign in through SESAME's fake driver, the same door the local
| server uses, and they run on SQLite in memory, as phpunit.xml says.
| withoutVite() keeps the page tests free of Node: the layout asks Vite
| for a bundle that composer ci never builds.
*/

it('answers /up with 200', function () {
    $this->get('/up')->assertStatus(200);
});

it('answers / with 200 for a guest', function () {
    $this->withoutVite();

    $this->get('/')
        ->assertStatus(200)
        ->assertSee('baked fresh by Sourdough');
});

it('sends a guest at /dashboard to the sign-in flow', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows /dashboard to a signed-in user', function () {
    // The fake driver answers /login with its configured identity and the
    // resolver imports the user, so one request signs the session in.
    $this->withoutVite();
    $this->get('/login')->assertRedirect('/');

    $this->get('/dashboard')
        ->assertStatus(200)
        ->assertViewIs('dashboard')
        ->assertSee('Signed in as')
        ->assertSeeHtml('<div data-vue-island="counter"></div>');
});

it('answers 404 for a missing page', function () {
    $this->get('/nope')->assertStatus(404);
});

it('turns a stored user array back into the User by username', function () {
    $user = User::factory()->create(['username' => 'jane.doe']);

    // Laravel 13 stores sessions as JSON, so the user SESAME saved at
    // sign-in comes back as a plain array on the next request.
    $refreshed = (new UserResolver)->refreshUser([
        'id' => $user->id,
        'username' => $user->username,
    ]);

    expect($refreshed)->toBeInstanceOf(User::class)
        ->and($refreshed->username)->toBe('jane.doe')
        ->and($refreshed->is($user))->toBeTrue();
});

it('returns null from refreshUser when the username no longer exists', function () {
    expect((new UserResolver)->refreshUser(['username' => 'ghost.user']))->toBeNull();
});
