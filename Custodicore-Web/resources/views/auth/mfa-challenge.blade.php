@extends('layouts.auth')

@section('title', 'Two-step verification')
@section('hero_title', 'One more step keeps records safe.')
@section('hero_text', 'A password alone is not enough to open a staff dashboard. The code we email proves it is really you.')

@section('content')
    @include('auth.partials.mfa-code-form', [
        'step' => 'Step 2 of 2 · Verify it\'s you',
        'heading' => 'Check your email',
        'intro' => 'Hello ' . $account->displayName() . '.',
        'after' => 'Enter it below to finish signing in.',
        'action' => route('mfa.verify'),
        'button' => 'Verify and sign in',
    ])
@endsection
