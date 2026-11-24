{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  La veste di tutte le pagine d'errore (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, decisione 696 (8)): il quadrato «Km», la scheda
  delle pagine d'accesso, l'icona con il colore che vuol dire qualcosa (`tono`: ro il guasto, am l'attesa, sk la sessione, sl la pagina che non c'è o non si
  apre), il numero dell'errore piccolo in un badge, il titolo, il corpo e i pulsanti.

  Si chiama `minimal` perché è il nome che usano le pagine d'errore di Laravel (`@extends('errors::minimal')`, con le sezioni `title`, `code` e `message`):
  così anche le pagine che il programma non ha scritto (401, 402 e le altre) prendono questa veste. Lo stile e il poco JavaScript stanno dentro la pagina
  (`_stile`, `_script`): niente Vite né file dalla rete. I collegamenti aprono la pagina intera (`target="_top"`), anche quando la pagina d'errore è nella
  finestra che il programma apre sopra una pagina.
--}}
@php
    $paginaIniziale = $paginaIniziale ?? \App\Support\Errori\PaginaDiErrore::paginaIniziale(null, true);
    $numeroDellErrore = trim($__env->yieldContent('code'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @stack('testa')
    <title>@yield('title') · KondoManager</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <script>
        // Il tema scelto nel programma (Impostazioni › Aspetto, salvato nel browser); «di sistema» o niente: quello del telefono o del computer.
        (function () {
            try {
                var tema = localStorage.getItem('appearance');
                if (tema === 'dark') document.documentElement.classList.add('scuro');
                if (tema === 'light') document.documentElement.classList.add('chiaro');
            } catch (e) {}
        })();
    </script>
    @include('errors._stile')
</head>
<body>
<main class="ep">
    <a class="ep-logo" href="{{ $paginaIniziale }}" target="_top" aria-label="KondoManager">Km</a>
    <div class="ep-card @yield('larga')">
        <div class="ep-top">
            <span class="ep-ico @yield('tono', 'sl')" aria-hidden="true">
                @hasSection('icona')
                    @yield('icona')
                @else
                    <svg class="i l" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                @endif
            </span>
            @if ($numeroDellErrore !== '')
                <span class="ep-num">{{ __('errors.errore_numero', ['numero' => $numeroDellErrore]) }}</span>
            @endif
        </div>
        <h1>@yield('message')</h1>
        @hasSection('corpo')
            @yield('corpo')
        @endif
        <div class="ep-azioni">
            @hasSection('azioni')
                @yield('azioni')
            @else
                <a class="eb" href="{{ $paginaIniziale }}" target="_top" data-indietro>
                    <svg class="i s" viewBox="0 0 24 24"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>{{ __('errors.torna_indietro') }}
                </a>
                <a class="eb pri" href="{{ $paginaIniziale }}" target="_top" data-pagina-iniziale>
                    <svg class="i s" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>{{ __('errors.pagina_iniziale') }}
                </a>
            @endif
        </div>
    </div>
</main>
@include('errors._script')
</body>
</html>
