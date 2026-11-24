{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{-- Le richieste che il programma non sa completare e che non hanno una pagina loro (405, 422 e le altre): la veste comune, con il loro numero. --}}
@extends('errors.minimal')

@section('title', __('errors.4xx_title'))
@section('code', (string) (isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : ''))
@section('tono', 'sl')
@section('message', __('errors.4xx_heading'))
@section('corpo')
    <p class="lead">{{ __('errors.4xx_message') }}</p>
@endsection
