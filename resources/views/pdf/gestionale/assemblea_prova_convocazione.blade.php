{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  La prova della convocazione di un'assemblea (`ProvaDellaConvocazione::dati()`): per ogni convocato il mezzo, la spedizione, l'esito, il giorno che conta,
  se è dentro i termini, la prova con l'inizio della sua impronta e la riga del registro delle modifiche del suo esito; in fondo chi manca e l'impronta
  della stampa. Foglio orizzontale, testata dello studio o del condominio come le altre stampe del gruppo «Rendiconto e assemblea».
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $titolo_stampa = $titolo;
@endphp

@section('title', $titolo . ' — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 13pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 2px;">{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }} · {{ $assemblea }}</div>
    </div>
    @if($termine)
        <div style="font-size: 8.5pt; color: #333; margin-bottom: 8px;">{{ $termine }}</div>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 14%;">Persona</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 8%;">Unità</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 9%;">Mezzo</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 14%;">Spedizione</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 12%;">Esito</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 8%;">Il giorno che conta</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 6%;">Nei termini</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 16%;">Prova · impronta</th>
                <th style="text-align: left; padding: 3px; border-bottom: 1px solid #c0ccd8; width: 13%;">Riga del registro</th>
            </tr>
        </thead>
        <tbody>
            @foreach($righe as $r)
                <tr>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['persona'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['unita'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['mezzo'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['spedizione'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['esito'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['conta'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top; font-weight: bold; color: {{ $r['in_tempo'] === 'no' ? '#9f1239' : ($r['in_tempo'] === 'sì' ? '#065f46' : '#555') }};">{{ $r['in_tempo'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['prova'] }}</td>
                    <td style="padding: 3px; border-bottom: 1px solid #e3e8ef; vertical-align: top;">{{ $r['registro'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="page-break-inside: avoid; margin-top: 10px;">
        <div style="font-size: 9.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin-bottom: 3px;">Chi manca</div>
        @if($mancano === [])
            <div style="font-size: 8.5pt;">Nessuno: tutti i convocati risultano raggiunti nei termini.</div>
        @else
            @foreach($mancano as $m)
                <div style="font-size: 8.5pt; line-height: 1.45;">· {{ $m }}</div>
            @endforeach
        @endif
        <div style="font-size: 8.5pt; color: #555; margin-top: 8px; line-height: 1.4;">{{ $nota }}</div>
        <div style="font-size: 8.5pt; color: #555; margin-top: 4px;">Stampata il {{ $stampata_il }} · impronta della stampa {{ $impronta }}</div>
    </div>
@endsection
