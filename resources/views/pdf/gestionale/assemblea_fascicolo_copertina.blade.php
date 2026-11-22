{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  La copertina del fascicolo dell'assemblea (`FascicoloDellAssemblea::componi()`): il condominio, l'assemblea con le sue date e il luogo, l'ordine del
  giorno, e l'indice dei documenti con il punto, il nome, le pagine e l'inizio dell'impronta di ognuno. Dopo la copertina il fascicolo porta l'avviso e i
  documenti dei punti, nell'ordine dell'indice.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Fascicolo dell\'assemblea — ' . $condominio->nome)

@section('content')
    <div style="text-align: center; margin: 18mm 0 10mm;">
        <div style="font-size: 10pt; color: #555; letter-spacing: 2px;">FASCICOLO DELL'ASSEMBLEA</div>
        <div style="font-size: 20pt; font-weight: bold; color: {{ $coloreTitoli }}; margin-top: 6px;">{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}</div>
        @if(filled($condominio->indirizzo ?? null))<div style="font-size: 10pt; color: #444; margin-top: 2px;">{{ $condominio->indirizzo }}@if(filled($condominio->comune ?? null)), {{ $condominio->comune }}@endif</div>@endif
        <div style="font-size: 14pt; color: {{ $coloreTitoli }}; margin-top: 10mm;">{{ $titolo }}</div>
        @foreach($sedute as $s)
            <div style="font-size: 10pt; color: #333; margin-top: 3px;">{{ $s['nome'] }}: {{ $s['quando'] }}@if(filled($s['dove'] ?? null)) · {{ $s['dove'] }}@endif</div>
        @endforeach
    </div>

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 6px 0 4px;">Ordine del giorno</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9.5pt; margin-bottom: 10px;">
        @foreach($punti as $p)
            <tr>
                <td style="width: 7mm; padding: 3px 4px 3px 0; vertical-align: top; font-weight: bold;">{{ $p['numero'] }}.</td>
                <td style="padding: 3px 0; vertical-align: top;">{{ $p['testo'] }}</td>
            </tr>
        @endforeach
    </table>

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 10px 0 4px;">I documenti del fascicolo</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 8%;">Punto</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Documento</th>
                <th style="text-align: right; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 10%;">Pagine</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 22%;">Impronta</th>
            </tr>
        </thead>
        <tbody>
            @foreach($indice as $i)
                <tr>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $i['punto'] }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $i['nome'] }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right;">{{ $i['pagine'] }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $i['impronta'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div style="font-size: 8pt; color: #555; margin-top: 10px; line-height: 1.45;">{{ $nota }}</div>
@endsection
