{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  Il registro dei verbali di un perimetro (`StampeDelVerbale::registro()`): il punto di partenza (il libro di carta che continua, o dal n. 1); una riga per
  verbale registrato, per annotazione di una seduta deserta e per riunione registrata a mano, per data, con il numero, l'assemblea, il contenuto, lo stato
  (l'annullata con il perché) e la colonna «Nel libro di carta»; il registro di nomina e revoca (le righe confermate, e le annullate con il perché: una riga
  si annulla, non si cancella); in fondo l'impronta della stampa.

  Con `$annotazione` (`StampeDelVerbale::annotazione()`) la vista è invece la pagina per il libro di carta di una seduta deserta: il registro e «Pagina del
  libro n. ____» in testa, le frasi, l'amministratore e lo spazio per la firma a cavallo.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $cella = 'padding: 3px 4px; border-bottom: 1px solid #d5dde7; vertical-align: top;';
    $testa = 'text-align: left; padding: 4px; border-bottom: 1px solid #b8c4d2; background: #eef2f7; color: #1e3a5f; font-weight: bold;';
@endphp

@section('title', isset($annotazione) ? $annotazione['titolo'] . ' — ' . $condominio->nome : $perimetro['nome'])

@section('content')
    @if(isset($annotazione))
        <div style="padding: 4px 6px; border: 1px solid #b8c4d2; background: #f5f7fa; margin-bottom: 8px; font-size: 9.5pt; font-weight: bold;">{{ $annotazione['registro'] }}</div>
        <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 10px;">
            <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">{{ $annotazione['titolo'] }}</h2>
        </div>
        @foreach($annotazione['frasi'] as $frase)
            <p style="margin: 0 0 6px 0; font-size: 10.5pt; line-height: 1.45; text-align: justify;">{{ $frase }}</p>
        @endforeach
        <table style="width: 100%; margin-top: 14mm; font-size: 10pt; page-break-inside: avoid;">
            <tr>
                <td style="width: 50%;"></td>
                <td style="width: 50%; text-align: right;">
                    L'amministratore
                    @if($annotazione['amministratore'] !== 'L\'amministratore')
                        <br><b>{{ $annotazione['amministratore'] }}</b>
                    @endif
                    <br><br>______________________________
                </td>
            </tr>
        </table>
        <table style="width: 100%; border-collapse: collapse; margin-top: 8mm;">
            <tr>
                <td style="height: 22mm; border: 1px dashed #8a97a8; color: #555; font-size: 8.5pt; padding: 3px 6px; vertical-align: top;">
                    Spazio per la firma a cavallo fra questo foglio e la pagina del libro
                </td>
            </tr>
        </table>
        <div style="font-size: 8.5pt; color: #333; margin-top: 6px;">Impronta dell'annotazione: {{ $impronta }}</div>
    @else
        <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
            <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">{{ $perimetro['nome'] }}{{ $anno !== null ? ' · ' . $anno : '' }}</h2>
            @if(filled($partenza))
                <div style="font-size: 9pt; color: #333; margin-top: 2px;">{{ $partenza }}</div>
            @endif
        </div>
        <div style="font-size: 8.5pt; color: #444; margin-bottom: 6px; line-height: 1.45;">
            Il registro dei verbali delle assemblee (art. 1130 n. 7 c.c.): i verbali registrati, le mancate costituzioni e le riunioni registrate a mano, per data.
            Una registrazione annullata resta, con il perché; il suo numero non torna.
        </div>

        <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
            <thead>
                <tr>
                    <th style="{{ $testa }} width: 6%;">N.</th>
                    <th style="{{ $testa }} width: 13%;">Data</th>
                    <th style="{{ $testa }} width: 23%;">Assemblea</th>
                    <th style="{{ $testa }} width: 17%;">Contenuto</th>
                    <th style="{{ $testa }} width: 24%;">Stato</th>
                    <th style="{{ $testa }} width: 17%;">Nel libro di carta</th>
                </tr>
            </thead>
            <tbody>
                @forelse($righe as $r)
                    <tr>
                        <td style="{{ $cella }} font-weight: bold;">{{ $r['numero'] }}</td>
                        <td style="{{ $cella }} white-space: nowrap;">{{ $r['giorno'] }}</td>
                        <td style="{{ $cella }}">{{ $r['assemblea'] }}</td>
                        <td style="{{ $cella }}">{{ $r['contenuto'] }}</td>
                        <td style="{{ $cella }}{{ $r['annullato'] ? ' color: #9f1239;' : '' }}">{{ $r['stato'] }}</td>
                        <td style="{{ $cella }}">{{ $r['libro'] }}</td>
                    </tr>
                @empty
                    <tr><td style="{{ $cella }}" colspan="6">Nessuna riga nel registro{{ $anno !== null ? ' per il ' . $anno : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if($nomine !== [])
            <div style="font-size: 12pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 12px 0 3px;">Registro di nomina e revoca</div>
            <div style="font-size: 8.5pt; color: #444; margin-bottom: 4px;">Le date della nomina e della revoca di ogni amministratore, con gli estremi (art. 1130 n. 7 c.c.).</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
                <thead>
                    <tr>
                        <th style="{{ $testa }} width: 12%;">Dal</th>
                        <th style="{{ $testa }} width: 11%;">Evento</th>
                        <th style="{{ $testa }} width: 25%;">Amministratore</th>
                        <th style="{{ $testa }} width: 20%;">Da dove</th>
                        <th style="{{ $testa }} width: 32%;">Estremi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($nomine as $n)
                        <tr>
                            <td style="{{ $cella }}">{{ $n['dal'] }}</td>
                            <td style="{{ $cella }}">{{ $n['evento'] }}</td>
                            <td style="{{ $cella }}">{{ $n['amministratore'] }}</td>
                            <td style="{{ $cella }}">{{ $n['da_dove'] }}</td>
                            <td style="{{ $cella }}">{{ $n['estremi'] }}@if($n['annullata'] ?? null)<br><span style="color: #9f1239;">{{ $n['annullata'] }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div style="font-size: 8.5pt; color: #555; margin-top: 10px; line-height: 1.45;">
            Stampato il {{ $stampato_il }} · Impronta della stampa: {{ $impronta }}
        </div>
    @endif
@endsection
