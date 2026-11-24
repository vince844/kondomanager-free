{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  «La pagina è scaduta» (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scena 6): fino alla 1.12.0-beta.13 usciva la pagina di Laravel,
  in inglese («Page Expired»). «Ricarica la pagina» ricarica la pagina intera da cui è partita l'operazione, anche dalla finestra sopra una pagina.
--}}
@extends('errors.minimal')

@php($paginaIniziale = \App\Support\Errori\PaginaDiErrore::paginaIniziale(null, true))

@section('title', __('errors.419_title'))
@section('code', '419')
@section('tono', 'sk')
@section('message', __('errors.419_heading'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
@endsection
@section('corpo')
    <p class="lead">{{ __('errors.419_message') }}</p>
@endsection
@section('azioni')
    <a class="eb" href="{{ $paginaIniziale }}" target="_top">
        <svg class="i s" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>{{ __('errors.pagina_iniziale') }}
    </a>
    <a class="eb pri" href="{{ $paginaIniziale }}" target="_top" data-ricarica>
        <svg class="i s" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>{{ __('errors.ricarica_la_pagina') }}
    </a>
@endsection
