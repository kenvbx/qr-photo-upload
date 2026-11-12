<section class="panel collection-settings"><h2>Event appearance & album</h2><form method="post" enctype="multipart/form-data" action="{{ route('event.update',$collection) }}">@csrf
<label for="event-message">Welcome message</label><textarea id="event-message" name="welcome_message" maxlength="500" rows="3">{{ $collection->welcome_message }}</textarea>
<label for="brand-color">Event color</label><input id="brand-color" type="color" name="brand_color" value="{{ $collection->brand_color }}"><p class="hint">Button text adjusts for contrast.</p>
<label for="event-logo">Event logo</label><input id="event-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp"><p class="hint">JPEG, PNG or WebP · 2 MB maximum · up to 2000 × 2000 px.</p>
@if($collection->logo_path)<img class="event-logo" src="{{ route('event.logo',$collection) }}" alt="Current event logo"><label class="checkbox-label"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label>@endif
<label class="checkbox-label"><input type="checkbox" name="share_enabled" value="1" @checked($collection->share_enabled)> Enable shared album</label><p class="hint">Anyone with the separate album link can view approved previews and the slideshow. Originals stay private. New uploads need your approval.</p>
<label for="share-days">Album link valid for (days from saving)</label><input id="share-days" name="share_days" type="number" min="1" max="30" value="7" required>
@if($collection->share_token)<label class="checkbox-label"><input type="checkbox" name="rotate_share" value="1"> Replace album link (revoke the old link)</label>@endif
<button class="primary full">Save event settings</button></form>
@if($collection->shared())<label for="album-url">Shared album link</label><input id="album-url" readonly value="{{ route('album.show',$collection->share_token) }}"><a class="secondary full" href="{{ route('album.show',$collection->share_token) }}" target="_blank" rel="noopener">Open shared album</a><p class="hint">Expires {{ $collection->share_expires_at->format('M j, Y H:i') }} UTC. Share only with intended viewers.</p>@else<p class="hint">Album sharing is off or expired.</p>@endif
</section>
