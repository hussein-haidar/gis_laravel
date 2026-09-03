@extends('layouts.app')

@section('title', 'Edit Lokasi')

@section('content')
    <h1 class="h3 mb-4">Edit Lokasi: {{ $location->name }}</h1>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.locations.update', $location) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @include('admin-loc._form')
            </form>
        </div>
    </div>
@endsection
