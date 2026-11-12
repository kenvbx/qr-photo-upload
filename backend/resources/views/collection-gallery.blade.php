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
            <button class="secondary" formaction="{{ route('photos.moderate',$collection) }}" name="decision" value="approved" data-bulk-action>Approve selected</button>
            <button class="secondary" formaction="{{ route('photos.moderate',$collection) }}" name="decision" value="rejected" data-bulk-action>Reject selected</button>
        </div>
        <p class="gallery-scope muted">Select up to 24 photos on this page. Selection resets when you change pages.</p>
        <noscript><p class="hint">Use the checkboxes to select photos. Deleted photos move to trash for up to 30 days.</p></noscript>
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
                    <img src="{{ route('photos.thumbnail', [$photo, 'v' => $photo->thumbnail_status]) }}" alt="Uploaded photo {{ $photo->id }}" width="{{ $photo->width }}" height="{{ $photo->height }}" loading="lazy">
                    <span class="gallery-view-hint" aria-hidden="true">View photo</span>
                </a>
                <label class="gallery-photo-select"><input type="checkbox" name="photos[]" value="{{ $photo->id }}" form="photo-selection" aria-label="Select photo {{ $photo->id }}"><span class="sr-only">Select photo {{ $photo->id }}</span></label>
            </div>
            <div class="photo-info"><strong>Photo {{ $photo->id }}</strong><span class="badge">{{ $photo->moderation_status === 'pending' ? 'Needs review' : ucfirst($photo->moderation_status) }}</span><span class="photo-filename">{{ $photo->original_name }}</span>@if($photo->delete_after)<span>Auto-delete {{ $photo->delete_after->format('M j, Y H:i') }} UTC</span>@endif
@if($photo->thumbnail_status !== 'ready')<p class="thumbnail-status muted">{{ $photo->thumbnail_status === 'failed' ? 'Preview unavailable. Original available.' : 'Preview processing. Updates automatically.' }}</p>@endif<span>{{ number_format($photo->bytes/1024/1024,2) }} MB · {{ $photo->width }} × {{ $photo->height }}</span><div class="photo-actions"><a class="text-button" href="{{ route('photos.show',[$photo,'download'=>1]) }}">Download</a><form method="post" action="{{ route('photos.destroy',$photo) }}" data-confirm="Move this photo to trash? You can restore it for up to 30 days.">@csrf @method('DELETE')<button class="text-button danger">Delete</button></form></div></div>
        </article>
    @empty
        <div class="empty"><div class="empty-icon" aria-hidden="true">↗</div><h3>Ready for the first photo</h3><p>Share the QR code with your guests. Their photos appear here automatically.</p></div>
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