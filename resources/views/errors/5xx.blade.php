{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{-- Gli errori del server che non hanno una pagina loro (502, 504 e gli altri): la veste comune, con il loro numero. --}}
@extends('errors.minimal')

@section('title', __('errors.5xx_title'))
@section('code', (string) (isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : ''))
@section('tono', 'am')
@section('message', __('errors.5xx_heading'))
@section('corpo')
    <p class="lead">{{ __('errors.5xx_message') }}</p>
@endsection
@section('azioni')
    <a class="eb pri" href="" data-riprova>
        <svg class="i s" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>{{ __('errors.riprova') }}
    </a>
@endsection
