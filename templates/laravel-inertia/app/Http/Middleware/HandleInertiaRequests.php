<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Waynestate\Sesame\Sesame;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            // The signed-in landing page's route name: the Vue layer calls
            // route(page.props.homeRoute), so it never pins a path of its own.
            'homeRoute' => config('home.route'),
            'auth' => [
                // Sign-in is SESAME's, so the shared user comes from its
                // session, not from a Laravel guard.
                'user' => app(Sesame::class)->user(),
            ],
        ]);
    }
}
