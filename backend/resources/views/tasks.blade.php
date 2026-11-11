@extends('layout')
@section('title', 'Background tasks')
@section('content')
<nav class="crumb"><a href="{{ route('dashboard') }}">Collections</a><span>/</span>Tasks</nav><h1>Background tasks</h1><p class="lead">Thumbnail processing and ZIP downloads across your collections.</p><p class="hint" data-live-status role="status">Updates automatically while this page is open.</p><a class="secondary" href="{{ request()->fullUrl() }}">Refresh tasks</a><section data-live-content data-live-url="{{ request()->fullUrl() }}">{!! $html !!}</section>
@endsection
