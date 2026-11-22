{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  «Lo stato di esecuzione delle delibere» (`StampaDelloStatoDiEsecuzione`): il documento del punto «Stato di esecuzione delle delibere precedenti», nel
  fascicolo dell'assemblea. Prima le delibere ancora da eseguire, di sempre; poi quelle eseguite dall'ultima ordinaria. Per ognuna l'assemblea e il punto,
  la frase pubblica, le cose da fare con il loro stato. Niente nomi: le frasi pubbliche dei punti e le parole dei tipi delle cose. Le stesse righe
  dell'avviso e del verbale: l'elenco è quello fermato nel punto.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', $titolo . ' — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
        <div style="font-size: 9.5pt; color: #333; margin-top: 2px;">{{ $assemblea }}@if($dal_punto !== null) · punto {{ $dal_punto }}@endif · elenco al {{ $al }}</div>
    </div>
    <div style="font-size: 8.5pt; color: #444; margin-bottom: 10px; line-height: 1.45;">
        Una comunicazione dell'amministratore: non si vota, e il verbale la riporta. L'elenco si ferma il {{ $al }}: l'avviso, il fascicolo e il verbale
        portano le stesse righe.
    </div>

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 6px 0 4px;">Ancora da eseguire · {{ count($aperte) }}</div>
    @if($aperte === [])
        <div style="font-size: 9pt; color: #444; margin-bottom: 6px;">Nessuna delibera da eseguire.</div>
    @endif
    @foreach($aperte as $r)
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 5px; page-break-inside: avoid;">
            <tr>
                <td style="font-size: 9pt; padding: 3px 0; border-top: 0.5px solid #c0ccd8;">
                    <b>{{ $r['delibera'] }}</b> · {{ $r['testo'] }}
                    @foreach($r['cose'] as $c)
                        <div style="font-size: 8.5pt; color: #333; margin-left: 5mm;">– {{ $c['testo'] }}: {{ $c['stato'] }}</div>
                    @endforeach
                </td>
            </tr>
        </table>
    @endforeach

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 10px 0 4px;">
        Eseguite{{ $ultima_ordinaria !== null ? ' dall\'ultima ordinaria, il ' . $ultima_ordinaria : '' }} · {{ count($eseguite) }}
    </div>
    @if($eseguite === [])
        <div style="font-size: 9pt; color: #444; margin-bottom: 6px;">Nessuna delibera eseguita in questo periodo.</div>
    @endif
    @foreach($eseguite as $r)
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 5px; page-break-inside: avoid;">
            <tr>
                <td style="font-size: 9pt; padding: 3px 0; border-top: 0.5px solid #c0ccd8;">
                    <b>{{ $r['delibera'] }}</b> · {{ $r['testo'] }}@if($r['eseguita_il'] !== null) · eseguita il {{ $r['eseguita_il'] }}@endif
                    @foreach($r['cose'] as $c)
                        <div style="font-size: 8.5pt; color: #333; margin-left: 5mm;">– {{ $c['testo'] }}: {{ $c['stato'] }}</div>
                    @endforeach
                </td>
            </tr>
        </table>
    @endforeach
@endsection
