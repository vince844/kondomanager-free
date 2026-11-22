{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{-- La stampa del registro delle modifiche (§14 del progetto della tappa 1, la bozza): le righe del periodo e dei filtri scelti, e in fondo l'impronta della
     stampa e quella dell'ultima riga del periodo, per un'impugnazione o per il passaggio di consegne. I valori scritti dalle persone sempre con {{ }}. --}}
@extends('pdf.base')
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $blocchi = count($righe) === 0 ? [[]] : array_chunk($righe, 100);
@endphp

@section('title', $titolo)

@section('content')
<div style="margin-bottom: 10px; border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $coloreTitoli }};">{{ $titolo }}</h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Chi ha cambiato che cosa e quando. Nessuno lo può modificare o cancellare.
        {{ count($righe) === 1 ? '1 riga' : number_format(count($righe), 0, ',', '.') . ' righe' }}@foreach($filtri as $nome => $valore) &nbsp;|&nbsp; {{ $nome }}: {{ $valore }}@endforeach
    </div>
</div>

<style>
    th.t { padding: 3px 4px; border-bottom: 1px solid #c0ccd8; text-align: left; }
    td.c { padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top; }
</style>
@foreach($blocchi as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table style="width: 100%; border-collapse: collapse; font-size: 8pt;">
    <thead>
        <tr style="background: #eef2f7; color: #1e3a5f;">
            <th class="t" style="width: 11%;">Quando</th>
            <th class="t" style="width: 15%;">Chi</th>
            <th class="t" style="width: 26%;">Su che cosa</th>
            <th class="t" style="width: 48%;">Che cosa è cambiato</th>
        </tr>
    </thead>
    <tbody>
        @foreach($blocco as $r)
            <tr>
                <td class="c">{{ $r['avvenuta_il'] }}</td>
                <td class="c">{{ $r['autore']['nome'] }}@if($r['autore']['ruolo'])<br><span style="color: #666;">{{ $r['autore']['ruolo'] }}</span>@endif</td>
                <td class="c">{{ $r['oggetto']['nome'] }}@if(count($r['condomini']) > 0 && ! isset($condominio))<br><span style="color: #666;">{{ implode(', ', $r['condomini']) }}</span>@endif</td>
                <td class="c">
                    {{ $r['cambiato'] }}
                    @if($r['perche'])<br><span style="color: #666;">Perché: {{ $r['perche'] }}</span>@endif
                    @if($r['annullata_dal_ripristino_del'])<br><span style="color: #9a3412;">Annullata dal ripristino del {{ $r['annullata_dal_ripristino_del'] }}</span>@endif
                    @if($r['oscurata'])<br><span style="color: #666;">Dati oscurati</span>@endif
                    @if($r['fuori_dal_programma'] ?? false)<br><span style="color: #9f1239;">Aggiunta fuori dal programma: la riga non ha la firma del programma</span>@endif
                </td>
            </tr>
        @endforeach
        @if(count($righe) === 0)
            <tr><td colspan="4" style="padding: 10px; text-align: center; color: #777;">Nessuna riga con questi filtri.</td></tr>
        @endif
    </tbody>
</table>
@endforeach

<p style="font-size: 7.5pt; color: #333; margin-top: 10px; border-top: 1px solid #c0ccd8; padding-top: 4px;">
    Stampata il {{ $stampata_il }}. Impronta della stampa (SHA-256 delle righe stampate): {{ $impronta_della_stampa }}.
    @if($ultima_riga)
        Impronta dell'ultima riga del periodo (n. {{ $ultima_riga['id'] }}, del {{ $ultima_riga['quando'] }}): {{ $ultima_riga['impronta'] }}.
    @else
        Nel periodo non c'è ancora una riga sigillata.
    @endif
    Con «Verifica l'integrità» si controlla che le righe non siano state cambiate fuori dal programma.
</p>
<p style="font-size: 7.5pt; color: #555; margin-top: 4px;">
    Contiene dati personali: si mostra a chi ha diritto di conoscerlo (l'assemblea, un'impugnazione, l'amministratore che subentra). Non va esposto in bacheca.
</p>
@endsection
