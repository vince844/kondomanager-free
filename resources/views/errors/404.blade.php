{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{-- «Pagina non trovata», nella veste comune delle pagine d'errore (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scena 6). --}}
@extends('errors.minimal')

@section('title', __('errors.404_title'))
@section('code', '404')
@section('tono', 'sl')
@section('message', __('errors.404_heading'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="m13.5 8.5-5 5"/><path d="m8.5 8.5 5 5"/></svg>
@endsection
@section('corpo')
    <p class="lead">{{ __('errors.404_message') }}</p>
@endsection
