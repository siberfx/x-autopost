@if ($flash)
    <div class="alert {{ $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' }} py-2 mb-3" role="{{ $flash['type'] === 'error' ? 'alert' : 'status' }}">
        {{ $flash['message'] }}
    </div>
@endif
