{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  Il preventivo fermato per l'assemblea, la distinta delle voci (`VersioniDelPreventivo::ferma()`): le voci di spesa con l'importo e le tabelle con cui
  si ripartiscono, il totale, il riparto per tabella, il numero della versione e l'impronta delle cifre. Le cifre arrivano in centesimi e si scrivono in
  euro solo qui, con `MoneyHelper::format()` come le altre stampe. Le rate per condòmino e i riparti sono le stampe del piano, conservate con la versione.
--}}
@php
    $euro = fn (int $cents) => \App\Helpers\MoneyHelper::format($cents);
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $titolo_stampa = 'Preventivo ' . $anno;
    $percento = fn (float $c) => rtrim(rtrim(number_format($c, 2, ',', '.'), '0'), ',') . ' %';
@endphp

@section('title', 'Preventivo ' . $anno . ' — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">Preventivo {{ $anno }}</h2>
        <div style="font-size: 9pt; color: #444; margin-top: 2px;">{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }}@if(filled($cifre['gestione'] ?? null)) · gestione «{{ $cifre['gestione'] }}»@endif · versione {{ $numero }} fermata il {{ $fermato_il }} per l'assemblea</div>
    </div>

    <div style="font-size: 10pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 6px 0 4px;">Le voci di spesa</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Voce</th>
                <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Si ripartisce con</th>
                <th style="text-align: right; padding: 4px; border-bottom: 1px solid #c0ccd8;">Importo</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cifre['voci'] as $v)
                <tr>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $v['nome'] }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ implode(', ', array_map(fn ($t) => $t['nome'] . ($t['coefficiente'] < 100 ? ' (' . $percento($t['coefficiente']) . ')' : ''), $v['tabelle'])) ?: '—' }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right; white-space: nowrap;">{{ $euro((int) $v['importo']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="padding: 4px; text-align: right; font-weight: bold;">Totale</td>
                <td style="padding: 4px; text-align: right; font-weight: bold; white-space: nowrap;">{{ $euro((int) $cifre['totale']) }}</td>
            </tr>
        </tbody>
    </table>

    @if(($cifre['per_tabella'] ?? []) !== [])
        <div style="font-size: 10pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 12px 0 4px;">Il riparto per tabella</div>
        <table style="width: 60%; border-collapse: collapse; font-size: 9pt;">
            @foreach($cifre['per_tabella'] as $t)
                <tr>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $t['tabella'] }}</td>
                    <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right; white-space: nowrap;">{{ $euro((int) $t['importo']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div style="font-size: 8pt; color: #555; margin-top: 14px; line-height: 1.45;">È il preventivo che ricevono i condòmini con la convocazione: se cambia, si ferma una versione nuova, e questa resta. Le rate per condòmino e il riparto per capitolo sono nelle stampe del piano, conservate con la versione.</div>
    <div style="font-size: 8.5pt; color: #555; margin-top: 4px;">Impronta delle cifre {{ $impronta }}</div>
@endsection
