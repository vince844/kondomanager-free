{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  La lettera della trasmissione del verbale a una persona (`StampeDelVerbale::lettera()`), sul modello comune: la testata (lo studio, la carta intestata o
  il condominio) è quella di `pdf.base`. In alto, allineati al margine destro come nella convocazione (decisione 260), il mezzo e il destinatario (con l'indirizzo per la raccomandata e la consegna a mano), poi il giorno,
  l'oggetto, il verbale con il numero, i punti per cui la persona lo riceve come assente con i trenta giorni (art. 1137 c. 2 c.c.), e in videoconferenza la
  trasmissione a tutti. Una pagina: la copia sottoscritta segue, unita al PDF. La firma dell'amministratore è quella delle stampe, se c'è.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
@endphp

@section('title', 'Trasmissione del verbale n. ' . $verbale->numero . ' — ' . $condominio->nome)

@section('content')
    <table style="width: 100%; border-collapse: collapse; margin: 2mm 0 8mm;">
        <tr>
            <td style="padding: 0; vertical-align: top; text-align: right;">
                @if(filled($destinatario['mezzo_parola']))
                    <div style="font-size: 9pt; font-weight: bold; color: {{ $coloreTitoli }}; margin-bottom: 2px;">{{ $destinatario['mezzo_parola'] }}</div>
                @endif
                <div style="font-size: 11pt; font-weight: bold; color: #222; line-height: 1.3;">{{ $destinatario['nome'] ?? '' }}</div>
                @if(filled($indirizzo))<div style="font-size: 9.5pt; color: #333; margin-top: 2px; line-height: 1.4;">{{ $indirizzo }}</div>@endif
                @if(filled($destinatario['unita'] ?? null))<div style="font-size: 9pt; color: #444; margin-top: 2px;">{{ $destinatario['unita'] }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="font-size: 10pt; text-align: right; margin-bottom: 6mm;">{{ $giorno }}</div>

    <div style="font-size: 10.5pt; font-weight: bold; margin-bottom: 5mm;">{{ $oggetto }}</div>

    <div style="font-size: 10.5pt; line-height: 1.5; text-align: justify;">
        <p style="margin: 0 0 6px 0;">Gentile {{ $destinatario['nome'] ?? '' }},</p>
        <p style="margin: 0 0 6px 0;">{{ $corpo }}</p>
        @foreach((array) ($destinatario['righe'] ?? []) as $riga)
            <p style="margin: 0 0 6px 0;">{{ $riga }}</p>
        @endforeach
        @if(filled($tutti))
            <p style="margin: 0 0 6px 0;">{{ $tutti }}</p>
        @endif
        <p style="margin: 0 0 6px 0;">Cordiali saluti.</p>
    </div>

    @if(empty($firma_stampe_absolute_path))
        <table style="width: 100%; margin-top: 10mm; font-size: 10.5pt;">
            <tr>
                <td style="width: 55%;"></td>
                <td style="width: 45%; text-align: right;">
                    L'amministratore
                    @if($amministratore !== 'L\'amministratore')
                        <br><b>{{ $amministratore }}</b>
                    @endif
                </td>
            </tr>
        </table>
    @endif
@endsection
