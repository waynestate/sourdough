@extends('layouts.app')

@section('title', config('app.name', 'Laravel'))

@section('content')
    <div class="wsu-page-header">
        <h1 class="wsu-page-header__title">{{ config('app.name', 'Laravel') }}</h1>
        <p class="wsu-page-header__subtitle">A Wayne State app, baked fresh by Sourdough.</p>
    </div>

    <div class="wsu-prose">
        <p>This is the public home page. Anyone can see it; no sign-in needed.</p>
        <p>
            <a class="wsu-btn wsu-btn--primary" href="{{ route('dashboard') }}">Go to the dashboard</a>
        </p>
    </div>
@endsection
