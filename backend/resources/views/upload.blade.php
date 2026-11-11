@extends('layout')
@section('title','Add your photos')
@section('content')
<div class="guest-wrap"><span class="eyebrow">YOU WERE THERE. SHARE YOUR VIEW.</span><h1>{{ $collection->title }}</h1><p class="lead">The best moments look different through every lens.</p><section class="panel upload-panel"><h2>Add your photos</h2><p class="muted">No sign-up needed. Just choose your favorites.</p><form method="post" enctype="multipart/form-data" action="{{ route('upload.store',$collection->token) }}" data-upload>@csrf<input type="hidden" name="upload_key" value="{{ \Illuminate\Support\Str::uuid() }}"><label class="upload-zone" for="photos"><span class="upload-symbol" aria-hidden="true">↑</span><strong>Choose photos to share</strong><span>Tap to browse your photo library</span><input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required aria-describedby="upload-limits file-info"></label><p class="hint" id="upload-limits">JPEG, PNG or WebP · Up to 10 MB each · 10 photos at a time</p>
<label class="checkbox-label"><input type="checkbox" data-compress> Reduce upload size (up to 2048 px, JPEG). Leave off to preserve originals.</label><noscript><p class="hint">Compression requires JavaScript. Original files will be uploaded.</p></noscript>
<section class="upload-selection" aria-labelledby="preview-heading" hidden>
<div class="preview-heading"><h3 id="preview-heading">Your selected photos</h3><span class="muted">Not uploaded yet</span></div>
<ul class="upload-previews" aria-label="Selected photos"></ul>
<p class="hint selection-help">Remove any photos you don't want to share. Choosing files again replaces this selection.</p>
</section>
<p class="hint selection-summary" id="file-info" aria-live="polite" aria-atomic="true"></p><button class="primary full" type="submit">Upload photos <span aria-hidden="true">↑</span></button><button class="secondary full retry-failed" type="button" hidden>Retry failed photos</button>
<div class="upload-total" hidden><label for="upload-progress">Overall upload progress</label><progress id="upload-progress" max="100" value="0"></progress><p class="hint upload-total-label"></p></div>
<p class="upload-status hint" role="status" aria-live="polite" aria-atomic="true"></p></form><div class="info-note"><strong>Just for the organizer</strong><p>Your photos go to a private collection. Original files may include camera location metadata. Share only photos you have permission to upload.</p></div><p class="hint">Upload link expires {{ $collection->expires_at->format('M j, Y · H:i') }} UTC.</p></section></div>
@endsection
