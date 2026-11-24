{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')
{{-- I titoli e i loro filetti hanno il colore della carta dello studio; senza un colore, quello di sempre. --}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Ripartizione delle Spese')

@section('content')

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 14px; border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 8px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $coloreTitoli }}; letter-spacing: 0.5px;">
        PROSPETTO DI RIPARTIZIONE SPESE PER UNITÀ IMMOBILIARE E PER SOGGETTO
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Piano dei conti: <strong>{{ $pianoConto->nome }}</strong> &nbsp;|&nbsp;
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
    </div>
</div>

@if(empty($matrice))
    <p style="color: #888; font-style: italic; text-align: center; margin-top: 40px;">
        Nessuna rata da mostrare: i piani rate di questa gestione non hanno ancora le rate calcolate.<br>
        Il prospetto si stampa anche con il piano in bozza: le rate si calcolano dalla pagina del piano, con «Ricalcola».
    </p>
@else

{{-- UNA RIGA PER SOGGETTO DENTRO L'UNITÀ, UNA COLONNA PER RATA DI OGNI PIANO --}}
@include('pdf.gestionale._tabella_scadenziario', [
    'matrice'      => $matrice,
    'colonneRate'  => $colonneRate,
    'labelColonna' => 'Unità',
    'perSoggetto'  => true,
    'conPiani'     => $conPiani ?? false,
])

<div style="margin-top: 8px; font-size: 7pt; color: #666;">
    * Gli importi sono espressi in Euro (€). Il prospetto mostra la quota di ogni soggetto per ciascuna rata del piano di pagamento.
</div>

@endif

{{-- NOTE LEGALI --}}
<div style="margin-top: 18px; font-size: 7pt; color: #666; border-top: 1px solid #ccc; padding-top: 6px;">
    Documento redatto ai sensi dell'art. 1123 c.c. (ripartizione delle spese condominiali).<br>
    Le quote di ripartizione sono calcolate in base alle tabelle millesimali in uso.
</div>

@endsection
