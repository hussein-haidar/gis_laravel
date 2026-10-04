@extends('layouts.app')

@section('title', __('messages.favorites_my_title'))

@section('content')
    <h1 class="h3 mb-4">⭐ {{ __('messages.favorites_my_title') }}</h1>

    @if ($favorites->isEmpty())
        <div class="card">
            <div class="card-body text-center text-muted py-5">
                <div class="fs-1 mb-2">🔖</div>
                <p class="mb-3">{{ __('messages.favorites_empty') }}</p>
                <a href="{{ route('map.index') }}" class="btn btn-primary">{{ __('messages.explore_map') }}</a>
            </div>
        </div>
    @else
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
            @foreach ($favorites as $favorite)
                @php $loc = $favorite->location; @endphp
                <div class="col">
                    <div class="card h-100 position-relative">
                        <button type="button" class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-unfavorite"
                                data-url="{{ route('favorites.toggle', $loc) }}" title="{{ __('messages.remove_from_favorites') }}">♥</button>
                        @if ($loc->photo_url)
                            <img src="{{ $loc->photo_display }}" class="card-img-top" alt="{{ $loc->name }}" style="height:150px;object-fit:cover;" onerror="this.style.display='none'">
                        @else
                            <div class="card-img-top bg-light" style="height:150px;"></div>
                        @endif
                        <div class="card-body">
                            {{-- Sama seperti grid beranda: nama disembunyikan
                                 sampai foto lokasi disetujui. --}}
                            @if ($loc->photo_url)
                                <h2 class="h6 mb-1">{{ $loc->name }}</h2>
                            @endif
                            @if ($loc->category)
                                <span class="badge text-white mb-2" style="background:{{ $loc->category->color }}">{{ $loc->category->name }}</span>
                            @endif
                            <div class="small text-muted">{{ $loc->created_at->format('d M Y') }}</div>
                        </div>
                        <div class="card-footer bg-white border-top-0 d-flex gap-2">
                            <a href="{{ route('map.show', $loc) }}" class="btn btn-sm btn-primary flex-fill">{{ __('messages.view_detail') }}</a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($favorites->hasPages())
            <div class="mt-4">
                {{ $favorites->links() }}
            </div>
        @endif
    @endif
@endsection

@section('scripts')
<script>
    document.querySelectorAll('.btn-unfavorite').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('{{ __('messages.confirm_remove_favorite') }}')) return;
            fetch(this.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
            }).then(function (r) { return r.json(); })
                .then(function (data) {
                    window.location.reload();
                }).catch(function () { alert('{{ __('messages.favorites_remove_fail') }}'); });
        });
    });
</script>
@endsection