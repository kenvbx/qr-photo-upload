@extends('layout')
@section('title',$collection->title)
@section('content')
<nav class="crumb"><a href="{{ route('dashboard') }}">Collections</a><span>/</span>{{ $collection->title }}</nav><div class="page-heading"><div><span class="badge {{ $collection->active()?'active':'' }}">{{ $collection->closed_at ? 'Closed' : ($collection->active() ? 'Accepting photos' : 'Expired') }}</span><h1>{{ $collection->title }}</h1><p class="lead">{{ $photos->total() }} of 100 photos · Uploads expire {{ $collection->expires_at->format('M j, Y · H:i') }} UTC</p></div><a class="secondary" href="{{ route('collections.show',$collection) }}">Refresh gallery</a></div>
<div class="workspace-grid"><section data-gallery>
    <div class="section-heading"><h2>The gallery</h2><span class="muted">Your private view</span></div>
    @if($photos->count())
    <form id="photo-selection" method="post" action="{{ route('photos.selection', $collection) }}" class="gallery-toolbar" aria-label="Selected photos">
        @csrf
        <div class="gallery-selection-summary">
            <label class="gallery-select-all" hidden><input type="checkbox" data-select-all> Select this page</label>
            <span data-selection-count role="status" aria-live="polite">0 selected</span>
        </div>
        <div class="gallery-bulk-actions">
            <button class="text-button" type="button" data-clear-selection hidden>Clear selection</button>
            <button class="secondary" name="action" value="download" data-bulk-action>Download ZIP</button>
            <button class="secondary danger" name="action" value="delete" data-bulk-action>Delete selected</button>
        </div>
        <p class="gallery-scope muted">Select up to 24 photos on this page. Selection resets when you change pages.</p>
        <noscript><p class="hint">Use the checkboxes to select photos. Deletion is permanent.</p></noscript>
    </form>
    @endif
    @if($exports->count())
    <section class="export-history" aria-label="Recent ZIP requests"><h3>Recent ZIP requests</h3><ul>@foreach($exports as $export)<li><a class="text-button" href="{{ route('exports.show', $export) }}">ZIP · {{ count($export->photo_ids) }} photos · {{ $export->expires_at->isPast() ? 'Expired' : 'View status' }} · {{ $export->created_at->format('M j H:i') }} UTC</a></li>@endforeach</ul></section>
    @endif
    <div class="photo-grid">
    @forelse($photos as $photo)
        <article class="photo-card" data-photo-id="{{ $photo->id }}" data-thumbnail-status="{{ $photo->thumbnail_status }}">
            <div class="gallery-photo-media">
                <a class="gallery-open" href="{{ route('photos.show', $photo) }}" target="_blank" rel="noopener" aria-label="View photo {{ $photo->id }}" data-lightbox-photo data-title="Photo {{ $photo->id }}" data-details="{{ number_format($photo->bytes/1024/1024,2) }} MB · {{ $photo->width }} × {{ $photo->height }}" data-download="{{ route('photos.show', [$photo, 'download' => 1]) }}">
                    <img src="{{ route('photos.thumbnail', $photo) }}" alt="Uploaded photo {{ $photo->id }}" width="{{ $photo->width }}" height="{{ $photo->height }}" loading="lazy">
                    <span class="gallery-view-hint" aria-hidden="true">View photo</span>
                </a>
                <label class="gallery-photo-select"><input type="checkbox" name="photos[]" value="{{ $photo->id }}" form="photo-selection" aria-label="Select photo {{ $photo->id }}"><span class="sr-only">Select photo {{ $photo->id }}</span></label>
            </div>
            <div class="photo-info"><strong>Photo {{ $photo->id }}</strong>@if($photo->thumbnail_status !== 'ready')<p class="thumbnail-status muted">{{ $photo->thumbnail_status === 'failed' ? 'Preview unavailable. Original available.' : 'Preview processing. Refresh to update.' }}</p>@endif<span>{{ number_format($photo->bytes/1024/1024,2) }} MB · {{ $photo->width }} × {{ $photo->height }}</span><div class="photo-actions"><a class="text-button" href="{{ route('photos.show',[$photo,'download'=>1]) }}">Download</a><form method="post" action="{{ route('photos.destroy',$photo) }}" data-confirm="Permanently delete this photo?">@csrf @method('DELETE')<button class="text-button danger">Delete</button></form></div></div>
        </article>
    @empty
        <div class="empty"><div class="empty-icon" aria-hidden="true">↗</div><h3>Ready for the first photo</h3><p>Share the QR code with your guests. Their photos will appear here after you refresh.</p></div>
    @endforelse
    </div>
    <div class="pagination">{{ $photos->links() }}</div>
    @if($photos->count())
    <dialog class="gallery-lightbox" aria-labelledby="lightbox-title" aria-describedby="lightbox-help">
        <div class="lightbox-panel">
            <div class="lightbox-toolbar"><div><h2 id="lightbox-title">Photo</h2><p class="lightbox-counter" aria-live="polite"></p></div><button type="button" class="lightbox-button" data-lightbox-close autofocus aria-label="Close photo viewer">Close <span aria-hidden="true">×</span></button></div>
            <div class="lightbox-stage" aria-busy="false"><img class="lightbox-image" alt=""><p class="lightbox-state" role="status"></p></div>
            <div class="lightbox-controls"><button class="lightbox-button" type="button" data-lightbox-previous aria-label="Previous photo">← Previous</button><label class="lightbox-select"><input type="checkbox" data-lightbox-select> Select photo</label><button class="lightbox-button" type="button" data-lightbox-next aria-label="Next photo">Next →</button></div>
            <div class="lightbox-footer"><p class="lightbox-details"></p><a class="lightbox-button" data-lightbox-download>Download</a></div>
            <p id="lightbox-help">Use ← / → to browse this page. Escape to close.</p>
        </div>
    </dialog>
    @endif
</section>
<aside class="panel share-panel"><span class="eyebrow">SCAN. UPLOAD. TOGETHER.</span><h2>Invite a perspective</h2><p class="muted">Share this code with your guests.</p><div class="qr" role="img" aria-label="QR code linking to this collection's guest upload page">{!! $qr !!}</div><label for="share-url">Guest upload link</label><input id="share-url" readonly value="{{ $url }}"><button class="primary full" type="button" data-copy="share-url">Copy upload link</button><p class="copy-status hint" role="status"></p><a class="secondary full" href="{{ $url }}" target="_blank" rel="noopener">Preview guest page ↗</a><p class="hint">Anyone with this link can contribute while it is active. The gallery stays private.</p>@if($collection->active())<form method="post" action="{{ route('collections.close',$collection) }}" data-confirm="Close this upload link? Guests will no longer be able to add photos.">@csrf<button class="text-button danger full">Close upload link</button></form>@endif</aside></div>
@endsection
