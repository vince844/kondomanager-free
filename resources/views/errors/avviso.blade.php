{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  L'esito di «Avvisa lo studio» quando il browser manda il modulo senza JavaScript (con JavaScript l'esito compare nella pagina d'errore stessa): la veste
  comune delle pagine d'errore, con il riquadro verde o arancio e «Torna alla pagina iniziale».
--}}
@extends('errors.minimal')

@section('title', $titolo)
@section('tono', $riuscito ? 'sl' : 'am')
@section('message', $titolo)
@section('corpo')
    <div class="ep-esito {{ $riuscito ? '' : 'am' }}" role="status">
        <svg class="i" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
        <div>{{ $messaggio }}</div>
    </div>
@endsection
@section('azioni')
    <a class="eb pri" href="{{ $paginaIniziale }}" target="_top">
        <svg class="i s" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>{{ __('errors.torna_alla_pagina_iniziale') }}
    </a>
@endsection
