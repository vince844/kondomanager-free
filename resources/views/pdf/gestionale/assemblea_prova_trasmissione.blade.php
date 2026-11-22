{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  La prova della trasmissione del verbale (`StampeDelVerbale::prova()`), come la prova della convocazione: per ogni persona che lo riceve il perché (assente
  a quali punti, o in videoconferenza tutti), il mezzo, la spedizione, l'esito con il giorno, la prova con l'inizio della sua impronta, fino a quando può
  impugnare; in fondo chi manca, la regola dei trenta giorni e l'impronta della stampa. Foglio orizzontale, testata dello studio o del condominio come le
  altre stampe del gruppo «Rendiconto e assemblea».
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $cella = 'padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;';
    $testa = 'text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8;';
@endphp

@section('title', $titolo . ' — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 13pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 2px;">{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }} · {{ $assemblea }}</div>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="{{ $testa }} width: 13%;">Persona</th>
                <th style="{{ $testa }} width: 7%;">Unità</th>
                <th style="{{ $testa }} width: 15%;">Perché lo riceve</th>
                <th style="{{ $testa }} width: 10%;">Mezzo</th>
                <th style="{{ $testa }} width: 12%;">Spedizione</th>
                <th style="{{ $testa }} width: 12%;">Esito</th>
                <th style="{{ $testa }} width: 15%;">Prova · impronta</th>
                <th style="{{ $testa }} width: 16%;">Può impugnare fino al</th>
            </tr>
        </thead>
        <tbody>
            @forelse($righe as $r)
                <tr>
                    <td style="{{ $cella }}">{{ $r['persona'] }}</td>
                    <td style="{{ $cella }}">{{ $r['unita'] }}</td>
                    <td style="{{ $cella }}">{{ $r['perche'] }}</td>
                    <td style="{{ $cella }}">{{ $r['mezzo'] }}</td>
                    <td style="{{ $cella }}">{{ $r['spedizione'] }}</td>
                    <td style="{{ $cella }}">{{ $r['esito'] }}</td>
                    <td style="{{ $cella }}">{{ $r['prova'] }}</td>
                    <td style="{{ $cella }}">{{ $r['termini'] }}</td>
                </tr>
            @empty
                <tr><td style="{{ $cella }}" colspan="8">Nessuno riceve il verbale.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div style="page-break-inside: avoid; margin-top: 10px;">
        <div style="font-size: 9.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin-bottom: 3px;">Chi manca</div>
        @if($mancano === [])
            <div style="font-size: 8.5pt;">Nessuno: tutti hanno ricevuto il verbale.</div>
        @else
            @foreach($mancano as $m)
                <div style="font-size: 8.5pt; line-height: 1.45;">· {{ $m }}</div>
            @endforeach
        @endif
        <div style="font-size: 8.5pt; color: #555; margin-top: 8px; line-height: 1.4;">{{ $nota }}</div>
        <div style="font-size: 8.5pt; color: #555; margin-top: 4px;">Stampata il {{ $stampata_il }} · Impronta della stampa: {{ $impronta }}</div>
    </div>
@endsection
