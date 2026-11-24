{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  «Non puoi aprire questa pagina» (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scena 6). Il motivo si mostra solo quando è una frase
  scritta dal programma per le persone (l'account sospeso, il collegamento scaduto, l'esercizio di un altro condominio): i messaggi di Laravel e del pacchetto
  dei permessi («This action is unauthorized.», «L'utente non ha le autorizzazioni corrette.») lasciano il posto alla frase della pagina
  (`PaginaDiErrore::motivoDel403()`).
--}}
@extends('errors.minimal')

@php($motivo = \App\Support\Errori\PaginaDiErrore::motivoDel403($exception ?? null))

@section('title', __('errors.403_title'))
@section('code', '403')
@section('tono', 'sl')
@section('message', __('errors.403_heading'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
@endsection
@section('corpo')
    <p class="lead">{{ $motivo ?? __('errors.403_message') }}</p>
@endsection
