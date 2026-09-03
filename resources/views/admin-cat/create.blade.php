@extends('layouts.app')

@section('title', 'Tambah Kategori')

@section('content')
    @include('admin-cat._form', [
        'title' => 'Tambah Kategori',
        'action' => route('admin.categories.store'),
    ])
@endsection
