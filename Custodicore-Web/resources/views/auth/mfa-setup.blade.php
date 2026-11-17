@extends('layouts.auth')

@section('title', 'Set up two-step verification')

@section('content')
    @include('auth.partials.mfa-code-form', [
        'step' => 'First sign-in · Turn on two-step verification',
        'heading' => 'Check your email',
        'intro' => 'Hello ' . $account->displayName() . '. Staff accounts need a second step when signing in.',
        'after' => 'Enter it to turn this on.',
        'action' => route('mfa.setup.confirm'),
        'button' => 'Turn on and sign in',
    ])
@endsection
