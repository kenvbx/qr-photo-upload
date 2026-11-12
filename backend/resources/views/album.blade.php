@extends('layout')
@section('title',$collection->title)
@section('event-color',$collection->brand_color)
@section('event-foreground',$collection->brandForeground())
@section('content')
<div class="event-page" data-album data-feed="{{ request()->fullUrl() }}">
<div class="album-heading">@if($collection->logo_path)<img class="event-logo" src="{{ route('album.logo',$collection->share_token) }}" alt="Event logo">@endif<span class="eyebrow">OUR SHARED MOMENTS</span><h1>{{ $collection->title }}</h1><p class="lead event-welcome">{{ $collection->welcome_message ?: 'A collection of moments, seen together.' }}</p></div>
<div class="album-toolbar"><button type="button" class="primary" data-slideshow-start hidden @disabled(!$photos->count())>Start slideshow</button><p class="hint" role="status" data-album-status>Approved previews only. Updates automatically.</p><a class="secondary" href="{{ request()->fullUrl() }}">Refresh album</a></div>
<noscript><p class="hint">Open a photo to view its preview. Slideshow playback requires JavaScript.</p></noscript>
<div class="photo-grid" data-album-grid>@forelse($photos as $photo)<a class="album-photo" href="{{ route('album.photo',[$collection->share_token,$photo->id]) }}" data-album-photo="{{ $photo->id }}" aria-label="View shared photo {{ $photo->id }}"><img src="{{ route('album.photo',[$collection->share_token,$photo->id]) }}" alt="Shared photo {{ $photo->id }}" width="480" height="480" loading="lazy"></a>@empty<p data-album-empty>No approved photos yet. Check back soon.</p>@endforelse</div>
<div class="pagination">{{ $photos->links() }}</div><p class="hint">Slideshow follows the photos on this page. Original files and camera metadata are not shared.</p>
<dialog class="album-viewer" aria-label="Shared album slideshow"><div class="album-viewer-toolbar"><button class="lightbox-button" type="button" data-album-close autofocus>Close slideshow</button><span data-slide-count aria-live="polite"></span><button class="lightbox-button" type="button" data-fullscreen>Fullscreen</button></div><div class="album-stage"><img data-slide-image alt=""><p data-slide-status role="status"></p></div><div class="album-viewer-toolbar"><button class="lightbox-button" type="button" data-slide-prev>Previous</button><button class="lightbox-button" type="button" data-slide-play>Play</button><button class="lightbox-button" type="button" data-slide-next>Next</button><label>Seconds per photo<select data-slide-seconds><option>3</option><option selected>5</option><option>10</option><option>15</option></select></label></div></dialog>
</div>
@endsection
