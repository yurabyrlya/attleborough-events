@extends('layouts.app')

@push('og')
    <meta property="og:image" content="https://example.test/images/custom.png">
@endpush

@section('content')
    <p>Fixture page that replaces the default share metadata.</p>
@endsection
