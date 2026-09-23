@extends('layouts.app')

@section('title', 'Edit Kategori')

@section('content')
    @include('admin-cat._form', [
        'title' => 'Edit Kategori: ' . $category->name,
        'action' => route('admin.categories.update', $category),
        'category' => $category,
        'parents' => $parents,
    ])
@endsection
