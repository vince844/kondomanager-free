{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{-- Il 402: la veste comune e le parole delle richieste che il programma non sa completare (la pagina di Laravel era in inglese). --}}
@extends('errors.minimal')

@section('title', __('errors.4xx_title'))
@section('code', '402')
@section('tono', 'sl')
@section('message', __('errors.4xx_heading'))
@section('corpo')
    <p class="lead">{{ __('errors.4xx_message') }}</p>
@endsection
