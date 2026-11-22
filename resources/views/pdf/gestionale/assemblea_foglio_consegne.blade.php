{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  Il foglio delle consegne a mano della convocazione (`ConsegneDellaConvocazione`): chi riceve l'avviso a mano, con la colonna del giorno e quella della
  firma di chi lo riceve. Firmato, si scansiona e si carica una volta: vale per tutte le sue righe, ognuna con il suo giorno.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Foglio delle consegne a mano — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 13pt; color: {{ $coloreTitoli }};">Foglio delle consegne a mano</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 2px;">{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }} · {{ $assemblea }}</div>
    </div>
    <div style="font-size: 9pt; color: #333; margin-bottom: 10px; line-height: 1.45;">{{ $frase }}</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 32%;">Persona</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 18%;">Unità</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 18%;">Il giorno</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8; width: 32%;">Firma di chi riceve</th>
            </tr>
        </thead>
        <tbody>
            @foreach($righe as $r)
                <tr>
                    <td style="padding: 10px 4px; border-bottom: 1px solid #c0ccd8;">{{ $r['persona'] }}</td>
                    <td style="padding: 10px 4px; border-bottom: 1px solid #c0ccd8;">{{ $r['unita'] }}</td>
                    <td style="padding: 10px 4px; border-bottom: 1px solid #c0ccd8;">____ / ____ / ______</td>
                    <td style="padding: 10px 4px; border-bottom: 1px solid #c0ccd8;">&nbsp;</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div style="font-size: 8pt; color: #555; margin-top: 10px;">{{ $piede }}</div>
@endsection
