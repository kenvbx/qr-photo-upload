@extends('layout')
@section('title', 'Your ZIP download')
@section('content')
<nav class="crumb"><a href="{{ route('dashboard') }}">Collections</a><span>/</span><a href="{{ route('collections.show', $export->upload_session_id) }}">{{ $export->uploadSession->title }}</a><span>/</span>ZIP download</nav>
<div class="export-wrap" data-export data-status="{{ $data['status'] }}" data-status-url="{{ route('exports.show', $export) }}">
    <span class="eyebrow">YOUR ORIGINAL PHOTOS</span>
    <h1>Your ZIP download.</h1>
    <p class="lead">{{ $data['count'] }} selected photos · Private to your account</p>
    <section class="panel export-panel" aria-label="ZIP preparation">
        <h2 data-export-title>{{ ['queued' => 'Waiting to start', 'processing' => 'Building your ZIP', 'ready' => 'Your ZIP is ready', 'failed' => 'Unable to prepare this ZIP', 'expired' => 'This ZIP has expired'][$data['status']] }}</h2>
        <p data-export-message role="status" aria-live="polite">{{ ['queued' => 'Your request is saved. You can leave this page and return later.', 'processing' => 'Gathering your original photos. This page updates automatically.', 'ready' => 'Your original photos are ready to download.', 'failed' => 'Retry, or return to the gallery if a selected photo was removed.', 'expired' => 'Request a new copy to download these photos again.'][$data['status']] }}</p>
        <div class="export-actions">
            <a class="primary" data-export-download href="{{ $data['download_url'] ?? '#' }}" @if($data['status'] !== 'ready') hidden @endif>Download ready ZIP</a>
            <form data-export-retry method="post" action="{{ route('exports.retry', $export) }}" @if(!in_array($data['status'], ['failed', 'expired'])) hidden @endif>@csrf<button class="secondary">Retry ZIP</button></form>
            <a class="secondary" href="{{ route('collections.show', $export->upload_session_id) }}">Back to gallery</a>
        </div>
        <p class="hint">Available until {{ $export->expires_at->format('M j, Y · H:i') }} UTC. ZIP links expire after 24 hours.</p>
        <a class="text-button" href="{{ route('exports.show', $export) }}">Refresh status</a>
        <p class="hint" data-export-connection role="status"></p>
    </section>
</div>
@endsection
