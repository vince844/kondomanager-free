{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  «Errore interno» (Coda 755 (1), decisioni 690 (2), 696 (8) e 698; bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scene 1–5).

  Fino alla 1.12.0-beta.13 questa pagina stampava a chiunque il messaggio dell'eccezione: con il database fermo l'indirizzo del server, la porta e il nome
  del database. Ora a tutti una frase e il codice di sei caratteri, lo stesso del registro degli errori e della scheda «Errori»; che cosa si vede in più lo
  decide `PaginaDiErrore::per()`:
  - `ospite` (il visitatore, o chiunque con il database fermo): «Se si ripete, comunica allo studio questo codice:»;
  - `avviso` (collegato senza il dettaglio): il riquadro «Avvisa lo studio» («Avvisa l'amministratore» al collaboratore), con la nota facoltativa;
  - `senza_avviso` (collegato senza il dettaglio, con l'interruttore dell'avviso spento, Coda 788): il codice con «Copia» e la frase che dice di comunicarlo allo studio;
  - `dettaglio` (chi ha «Gestisci impostazioni generali»): «Dettaglio tecnico (lo vedi solo tu)» con il messaggio ripulito e «Copia il rapporto»;
  - `demo` (la demo pubblica): né l'uno né l'altro.
  Dopo un salvataggio niente «Riprova», che rifarebbe l'operazione. Compare anche nella finestra che il programma apre sopra una pagina, quando l'errore nasce
  da un clic: i collegamenti aprono la pagina intera.
--}}
@extends('errors.minimal')

@php
    $p = \App\Support\Errori\PaginaDiErrore::per($exception ?? null);
    $paginaIniziale = $p['pagina_iniziale'];
    $nero = in_array($p['modo'], ['ospite', 'demo', 'senza_avviso'], true);
@endphp

@section('title', __('errors.500_title'))
@section('code', '500')
@section('tono', 'ro')
@section('message', __('errors.500_heading'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
@endsection
@if ($p['modo'] === 'dettaglio')
    @section('larga', 'ep-larga')
@endif

@section('corpo')
    <p class="lead">{{ $p['lettura'] ? __('errors.500_message') : __('errors.500_message_dopo_salvataggio') }}</p>

    <div class="ep-codice" data-codice-errore="{{ $p['codice'] }}">
        <p class="lab">{{ $p['modo'] === 'ospite' ? __('errors.comunica_allo_studio') : __('errors.codice_di_questo_errore') }}</p>
        <div class="riga">
            <span class="cod">{{ $p['codice'] }}</span>
            <button type="button" class="eb sm" data-copia="{{ $p['codice'] }}" data-copiato="{{ __('errors.copiato') }}">
                <svg class="i s" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>{{ __('errors.copia') }}
            </button>
        </div>
        <p class="quando">{{ $p['quando'] }}</p>
    </div>

    @if ($p['motivo'] === \App\Support\Errori\RegistroDegliErrori::DATABASE)
        <div class="ep-nota" data-database-fermo>
            <svg class="i s" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
            <div><b>{{ __('errors.database_fermo_titolo') }}</b> {{ __('errors.database_fermo') }}</div>
        </div>
    @elseif ($p['motivo'] === \App\Support\Errori\RegistroDegliErrori::TROPPE && $p['modo'] !== 'dettaglio')
        <div class="ep-nota">
            <svg class="i s" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <div>{{ __('errors.troppe_volte') }}</div>
        </div>
    @endif

    @if ($p['modo'] === 'demo')
        <div class="ep-nota sk" data-demo>
            <svg class="i s" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            <div><b>{{ __('errors.demo_titolo') }}</b> {{ __('errors.demo') }}</div>
        </div>
    @endif

    @if ($p['modo'] === 'senza_avviso')
        <p class="ep-aiuto" data-senza-avviso>{{ __('errors.avviso_spento_frase') }}</p>
    @endif

    @if ($p['modo'] === 'avviso')
        <div class="ep-sep"></div>
        <form class="ep-avviso" method="POST" action="{{ $p['avviso']['url'] }}" target="_top" data-avviso
              data-fatto="{{ $p['avviso']['fatto'] }}" data-troppi="{{ __('errors.avviso_troppi') }}" data-non-riuscito="{{ __('errors.avviso_non_riuscito') }}">
            <input type="hidden" name="_token" value="{{ $p['avviso']['token'] }}">
            <input type="hidden" name="codice" value="{{ $p['codice'] }}">
            <input type="hidden" name="firma" value="{{ $p['avviso']['firma'] }}">
            <div data-campi>
                <p class="tt">
                    <svg class="i s" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>{{ $p['avviso']['etichetta'] }}
                </p>
                <p class="sot">{{ $p['avviso']['sotto'] }}</p>
                <div class="ep-campo">
                    <label for="nota-avviso">{{ __('errors.avviso_domanda') }} <span>{{ __('errors.facoltativo') }}</span></label>
                    <textarea id="nota-avviso" name="nota" class="ep-ta" maxlength="300" rows="2" placeholder="{{ __('errors.avviso_esempio') }}"></textarea>
                    <div class="sotto"><span>{{ __('errors.non_scrivere_password') }}</span><span><span data-conta>0</span> / 300</span></div>
                </div>
                <button type="submit" class="eb pri full">
                    <svg class="i s" viewBox="0 0 24 24"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>{{ $p['avviso']['etichetta'] }}
                </button>
            </div>
            <div class="ep-esito" data-esito role="status" hidden>
                <svg class="i" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <div><b data-titolo></b><span data-testo></span></div>
            </div>
        </form>
    @endif

    @if ($p['modo'] === 'dettaglio' && $p['dettaglio'] !== null)
        @php($d = $p['dettaglio'])
        <div class="ep-tec" data-dettaglio>
            <p class="hd">
                <svg class="i s" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>{{ __('errors.dettaglio_titolo') }}
            </p>
            <p class="msg">{{ $d['messaggio'] }}</p>
            <dl>
                <dt>{{ __('errors.dettaglio_pagina') }}</dt><dd>{{ $d['pagina'] }}</dd>
                @if ($d['nel_programma'])
                    <dt>{{ __('errors.dettaglio_nel_programma') }}</dt><dd>{{ $d['nel_programma'] }}</dd>
                @endif
                <dt>{{ __('errors.dettaglio_versione') }}</dt><dd>{{ $d['versione'] }}</dd>
                <dt>{{ __('errors.dettaglio_testo_intero') }}</dt><dd>{{ __('errors.dettaglio_testo_intero_valore', ['codice' => $p['codice']]) }}</dd>
            </dl>
            <textarea id="rapporto-errore" hidden readonly>{{ $d['rapporto'] }}</textarea>
            <div class="btns">
                <button type="button" class="eb sm pri" data-copia data-copia-da="rapporto-errore" data-copiato="{{ __('errors.rapporto_copiato') }}">
                    <svg class="i s" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>{{ __('errors.copia_il_rapporto') }}
                </button>
                @if ($d['scheda'])
                    <a class="eb sm" href="{{ $d['scheda'] }}" target="_top">
                        <svg class="i s" viewBox="0 0 24 24"><path d="M3 12h.01M3 18h.01M3 6h.01M8 12h13M8 18h13M8 6h13"/></svg>{{ __('errors.apri_nella_scheda') }}
                    </a>
                @endif
            </div>
            @if ($d['traccia'] !== '')
                <details>
                    <summary>{{ __('errors.mostra_la_traccia', ['righe' => $d['righe']]) }}</summary>
                    <p class="traccia">{{ $d['traccia'] }}</p>
                </details>
            @endif
        </div>
    @endif
@endsection

@section('azioni')
    @if ($p['lettura'])
        <a class="eb" href="" data-riprova>
            <svg class="i s" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>{{ __('errors.riprova') }}
        </a>
    @endif
    <a class="eb {{ $nero ? 'pri' : '' }}" href="{{ $paginaIniziale }}" target="_top" data-pagina-iniziale>
        <svg class="i s" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>{{ __('errors.torna_alla_pagina_iniziale') }}
    </a>
@endsection
