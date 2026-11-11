@extends('layout')
@section('title', 'Unlock upload link')
@section('content')
<div class="guest-wrap"><section class="panel"><h1>Private upload link</h1><p>Enter the password provided by your organizer.</p><form method="post" action="{{ route('upload.unlock', $collection->token) }}">@csrf<label for="link-password">Upload password</label><input id="link-password" type="password" name="password" required maxlength="128" autocomplete="current-password"><button class="primary full">Unlock upload link</button></form></section></div>
@endsection
