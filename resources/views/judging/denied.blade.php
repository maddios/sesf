@extends('judging.layout')

@section('title', $title)

@section('content')
    <div class="mx-auto max-w-lg rounded-lg border border-border bg-panel p-6 shadow-sm">
        <h1 class="text-2xl font-bold text-heading">{{ $title }}</h1>
        @if ($email !== '')
            <p class="mt-3">You are signed in as <strong class="break-all">{{ $email }}</strong>.</p>
        @endif
        <p class="mt-3">{{ $message }}</p>
        <form method="post" action="/login/logout" class="mt-6">
            @csrf
            <input type="hidden" name="redirect" value="{{ $signOutRedirect }}">
            <button type="submit" class="rounded border border-default-border bg-default px-4 py-2 hover:bg-default-hover">Sign out and use another account</button>
        </form>
    </div>
@endsection
