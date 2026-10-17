@extends('errors.layout')

@section('title', 'Too many submissions')
@section('heading', 'Too many submissions from here today')

@section('content')
    <p class="mt-4 text-ink">Please try again later, or email the details to <a href="mailto:{{ config('site.contact_email') }}" class="text-accent underline underline-offset-2">{{ config('site.contact_email') }}</a>.</p>
@endsection
