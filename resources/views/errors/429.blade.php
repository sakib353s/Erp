@extends('errors.layout')

@section('code', '429')
@section('title', 'Too many attempts')
@section('text', 'Rate limit reached — wait a moment before trying again. Repeated abuse is recorded in the audit log.')
