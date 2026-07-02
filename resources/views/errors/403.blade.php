@extends('errors.layout')
@section('code', '403')
@section('title', __('errors.403.title'))
@section('message', __('errors.403.message'))
@if (! empty($exception) && $exception->getMessage())
    @section('detail', $exception->getMessage())
@endif
