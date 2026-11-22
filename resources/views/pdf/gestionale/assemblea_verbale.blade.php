{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  Il verbale (`StampeDelVerbale::bozza()` e `perIlLibro()`), sul modello comune: la testata è quella di `pdf.base`, e su ogni pagina il titolo della stampa
  (`titolo_stampa`) dice che cosa è la pagina («Bozza da firmare · versione 3 · impronta 3f2a9c1b», o per il libro «Pagina del libro n. ____ · Verbale n. 42»).

  - Il testo viene dalla struttura validata dell'editor ed entra da `TestoDelVerbaleInPdf::html()`, che scappa ogni testo: è l'unica uscita `{!! !!}`.
  - L'elenco in calce: le presenze e i voti della riunione, lo stesso pezzo del prospetto della sala (`_assemblea_presenze_voti_seduta`).
  - In fondo, dopo l'elenco (che si firma con il verbale), le firme del presidente e del segretario; per il libro di carta lo spazio per la firma a
    cavallo fra il foglio e la pagina del libro.
  - In fondo la versione e l'impronta del testo; registrato e poi annullato, il riquadro dice quando e perché.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', $titolo . ' — ' . $condominio->nome)

@section('content')
    @if($per_il_libro)
        <div style="padding: 4px 6px; border: 1px solid #b8c4d2; background: #f5f7fa; margin-bottom: 8px; font-size: 9.5pt; font-weight: bold;">{{ $registro_del_libro }}</div>
    @endif

    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
        <div style="font-size: 9.5pt; color: #333; margin-top: 2px;">{{ $assemblea }} · {{ $condominio_nome }}</div>
        @if(! empty($registrazione))
            <div style="font-size: 9pt; color: #333; margin-top: 2px;">
                @if(isset($registrazione['sottoscritto_il']))Sottoscritto il {{ $registrazione['sottoscritto_il'] }}@endif
                @if(isset($registrazione['registrato_il'])) · registrato il {{ $registrazione['registrato_il'] }} nel {{ $registro }}@endif
                @if(isset($registrazione['sostituisce'])) · sostituisce il n. {{ $registrazione['sostituisce'] }}, annullato @endif
            </div>
        @endif
    </div>

    @if($annullato !== null)
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
            <tr>
                <td style="padding: 6px 8px; border: 1.5px solid #9f1239; color: #9f1239; font-size: 9.5pt; line-height: 1.4;">
                    <b>Registrazione annullata il {{ $annullato['il'] }}</b>: {{ $annullato['motivo'] }}<br>
                    Il numero non torna: la registrazione giusta prende il numero dopo, e dice quale sostituisce.
                </td>
            </tr>
        </table>
    @endif

    <div>
        {!! \App\Services\Assemblee\Verbale\TestoDelVerbaleInPdf::html($documento) !!}
    </div>

    @if($elenco !== null)
        <div style="margin-top: 8mm; border-top: 1px solid #b8c4d2; padding-top: 4px;">
            <div style="font-size: 12pt; font-weight: bold; color: {{ $coloreTitoli }};">Elenco in calce: le presenze e i voti</div>
            <div style="font-size: 8.5pt; color: #444; margin: 2px 0 4px; line-height: 1.45;">
                Fa parte del verbale: i nomi e i millesimi dei presenti, di persona o per delega, e per ogni votazione chi è favorevole, contrario, astenuto e chi
                non ha votato, come la sala li ha registrati.
            </div>
            @include('pdf.gestionale._assemblea_presenze_voti_seduta', ['s' => $elenco, 'coloreTitoli' => $coloreTitoli])
            @if(filled($elenco['impronta_fotografia'] ?? null))
                <div style="font-size: 8.5pt; color: #333; margin-top: 6px;">Impronta della fotografia della seduta: {{ $elenco['impronta_fotografia'] }}</div>
            @endif
        </div>
    @endif

    <div style="page-break-inside: avoid;">
        <table style="width: 100%; margin-top: 10mm; font-size: 10pt;">
            <tr>
                <td style="width: 50%; vertical-align: top;">Il presidente<br><b>{{ $presidente !== '' ? $presidente : '—' }}</b><br><br>______________________________</td>
                <td style="width: 50%; vertical-align: top; text-align: right;">Il segretario<br><b>{{ $segretario !== '' ? $segretario : '—' }}</b><br><br>______________________________</td>
            </tr>
        </table>
        <div style="font-size: 8.5pt; color: #333; margin-top: 8px; line-height: 1.5;">
            <div>Versione {{ $versione }} · Impronta del testo: {{ $impronta }}</div>
            @if($per_il_libro)
                <table style="width: 100%; border-collapse: collapse; margin-top: 8mm;">
                    <tr>
                        <td style="height: 22mm; border: 1px dashed #8a97a8; color: #555; font-size: 8.5pt; padding: 3px 6px; vertical-align: top;">
                            Spazio per la firma a cavallo fra questo foglio e la pagina del libro
                        </td>
                    </tr>
                </table>
            @endif
        </div>
    </div>
@endsection
