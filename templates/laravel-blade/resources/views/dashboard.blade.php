@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="wsu-page-header">
        <h1 class="wsu-page-header__title">Dashboard</h1>
        <p class="wsu-page-header__subtitle">Signed in as {{ $user->name }}</p>
    </div>

    <div class="wsu-prose">
        <p>Hello, {{ $user->name }}. This page sits behind the auth.sesame middleware, so only a signed-in session gets here.</p>

        <form method="POST" action="{{ route('sesame.logout') }}">
            @csrf
            <button type="submit" class="wsu-btn wsu-btn--outline">Sign out</button>
        </form>
    </div>
@endsection
