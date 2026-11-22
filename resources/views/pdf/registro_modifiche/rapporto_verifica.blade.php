{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{-- Il rapporto della verifica dell'integrità (scena 5 della bozza): la frase, quando è stata fatta, le righe che non tornano con il motivo. Lo si dà a chi gestisce
     il server. --}}
@extends('pdf.base')
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', $titolo)

@section('content')
<div style="margin-bottom: 10px; border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">Verifica fatta il {{ $verificata_il }}.</div>
</div>

<p style="font-size: 10pt; margin: 6px 0 10px;"><strong>{{ $frase }}</strong></p>

@if(count($righe) > 0)
<table style="width: 100%; border-collapse: collapse; font-size: 8.5pt;">
    <thead>
        <tr style="background: #eef2f7; color: #1e3a5f;">
            <th style="padding: 3px 4px; text-align: left; width: 10%;">Riga</th>
            <th style="padding: 3px 4px; text-align: left; width: 14%;">Quando</th>
            <th style="padding: 3px 4px; text-align: left; width: 18%;">Chi</th>
            <th style="padding: 3px 4px; text-align: left; width: 28%;">Su che cosa</th>
            <th style="padding: 3px 4px; text-align: left; width: 30%;">Che cosa non torna</th>
        </tr>
    </thead>
    <tbody>
        @foreach($righe as $r)
            <tr>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['riga_id'] ?? '—' }}@if($r['sequenza'] !== null) (n. {{ $r['sequenza'] }} della catena)@endif</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['quando'] ?? '—' }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['chi'] ?? '—' }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['oggetto'] ?? 'La riga non è più nel registro' }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $r['motivo'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

<p style="font-size: 8pt; color: #555; margin-top: 10px;">
    La catena delle impronte e la firma di ogni riga, fatta con una chiave che sta sul server e non nel database, rendono visibile una modifica fatta fuori
    dal programma, per esempio direttamente nel database; non la impediscono, e chi ha anche la chiave del server può riscrivere tutto. Questo rapporto
    va a chi gestisce il server, che può cercare quando e da dove è stata fatta.
</p>
@endsection
