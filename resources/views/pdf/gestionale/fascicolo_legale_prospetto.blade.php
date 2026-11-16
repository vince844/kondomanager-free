{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  Le rate non versate del fascicolo per il legale (`01 Rate non versate.pdf`): le rate scadute e non versate della persona che l'amministratore ha messo
  nel fascicolo, una riga per rata: il piano e la sua delibera, la rata e l'unità, la scadenza, l'esercizio e la data dei sei mesi, la cifra non versata;
  sotto la riga l'accordo di rientro, se la parte è nel fascicolo. In fondo il totale. Le cifre in centesimi, le date `Y-m-d`: `$righe` e `$cifre` sono la
  lettura del server (`FascicoloLegale::prospetto`). Dice solo ciò che è dentro; niente di effetto legale.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $euro = fn (int $cents) => "€\u{00A0}" . number_format($cents / 100, 2, ',', '.');
    $giorno = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : '';
    $cella = 'padding: 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top;';
    $testa = 'padding: 4px; border-bottom: 1px solid #c0ccd8; text-align: left;';
    $quante = $cifre['rate'] === 1 ? '1 rata' : $cifre['rate'] . ' rate';
@endphp

@section('title', 'Rate non versate — ' . $persona['nome'])

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 13pt; color: {{ $coloreTitoli }}; letter-spacing: 0.5px;">Rate non versate</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 3px;">
            <strong>{{ $persona['nome'] }}</strong>@if($persona['unita'] !== []) — {{ implode(', ', $persona['unita']) }}@endif
        </div>
        <div style="font-size: 9pt; color: #444; margin-top: 2px;">Le rate scadute al {{ $giorno($oggi) }} e non versate che sono in questo fascicolo: {{ $quante }}, {{ $euro($cifre['totale']) }}.</div>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="{{ $testa }} width: 34%;">Rata e piano</th>
                <th style="{{ $testa }} width: 11%;">Unità</th>
                <th style="{{ $testa }} width: 12%;">Scadenza</th>
                <th style="{{ $testa }} width: 27%;">Esercizio e data dei sei mesi</th>
                <th style="{{ $testa }} width: 16%; text-align: right;">Non versato</th>
            </tr>
        </thead>
        <tbody>
            @foreach($righe as $r)
                <tr>
                    <td style="{{ $cella }}">
                        {{ $r['nome'] }}
                        <div style="font-size: 7.5pt; color: #555;">{{ $r['delibera'] }}</div>
                        @if($r['accordo_testo'])
                            <div style="font-size: 7.5pt; color: #555;">{{ $r['accordo_testo'] }}</div>
                        @endif
                    </td>
                    <td style="{{ $cella }}">{{ $r['unita'] ?? '' }}</td>
                    <td style="{{ $cella }}">{{ $giorno($r['scadenza']) }}</td>
                    <td style="{{ $cella }}">
                        @if($r['sei_mesi'])
                            {{ $r['sei_mesi']['esercizio'] }}<div style="font-size: 7.5pt; color: #555;">sei mesi: {{ $giorno($r['sei_mesi']['data']) }}</div>
                        @else
                            <span style="color: #555;">senza la data dei sei mesi</span>
                        @endif
                    </td>
                    <td style="{{ $cella }} text-align: right; white-space: nowrap;">{{ $euro((int) $r['residuo']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4" style="padding: 5px 4px; text-align: right; font-weight: bold;">Totale</td>
                <td style="padding: 5px 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro($cifre['totale']) }}</td>
            </tr>
        </tbody>
    </table>

    @if(count($cifre['esercizi']) > 1)
        <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 10px;">
            @foreach($cifre['esercizi'] as $e)
                <tr>
                    <td style="padding: 2px 4px; color: #444;">
                        {{ $e['data'] ? 'di cui dell\'esercizio ' . preg_replace('/^esercizio\s+/iu', '', $e['nome']) . ' (data dei sei mesi: ' . $giorno($e['data']) . ')' : 'di cui senza la data dei sei mesi' }}
                    </td>
                    <td style="padding: 2px 4px; text-align: right; white-space: nowrap; width: 16%;">{{ $euro($e['cifra']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endsection
