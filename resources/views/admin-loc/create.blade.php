@extends('layouts.app')

@section('title', 'Tambah Lokasi')

@section('content')
    <h1 class="h3 mb-4">Tambah Lokasi</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.locations.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                @include('admin-loc._form')
            </form>
        </div>
    </div>
@endsection
