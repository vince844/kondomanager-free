{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  «Le presenze e i voti» (`ProspettoDellaSala`): il prospetto della sala con i nomi e i millesimi, che il verbale richiama. Per ogni seduta l'apertura (o la
  prima deserta), presidente e segretario, le presenze con come, entrata e uscita; per ogni votazione l'ora, gli intervenuti, favorevoli, contrari, astenuti
  e chi non ha votato con i nomi e i millesimi, i conflitti, l'esito; le dichiarazioni a verbale. In fondo le impronte e le firme di presidente e segretario.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Le presenze e i voti — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">Le presenze e i voti</h2>
        <div style="font-size: 9.5pt; color: #333; margin-top: 2px;">{{ $titolo }} · {{ $assemblea }}</div>
    </div>
    <div style="font-size: 8.5pt; color: #444; margin-bottom: 8px; line-height: 1.45;">
        Il prospetto della sala, con i nomi e i millesimi, come la sala li ha registrati. Il verbale lo richiama con la sua impronta, scritta in fondo.
    </div>

    @foreach($sedute as $s)
        @include('pdf.gestionale._assemblea_presenze_voti_seduta', ['s' => $s, 'coloreTitoli' => $coloreTitoli])
    @endforeach

    @if($senza_esito_in_sala !== [])
        <div style="font-size: 10pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 10px 0 3px;">I punti senza voto in sala</div>
        @foreach($senza_esito_in_sala as $p)
            <div style="font-size: 9pt; margin-bottom: 2px;">{{ $p['punto'] }}: {{ $p['esito'] }}</div>
        @endforeach
    @endif

    <div style="font-size: 8.5pt; color: #333; margin-top: 12px; line-height: 1.5; page-break-inside: avoid;">
        @foreach($impronte as $f)
            <div>Impronta della fotografia ({{ $f['seduta'] }}): {{ $f['impronta'] }}</div>
        @endforeach
        <div>Impronta di questo prospetto: {{ $impronta }}</div>
        <table style="width: 100%; margin-top: 14mm; font-size: 9pt;">
            <tr>
                <td style="width: 50%;">Il presidente ______________________</td>
                <td style="width: 50%; text-align: right;">Il segretario ______________________</td>
            </tr>
        </table>
    </div>
@endsection
