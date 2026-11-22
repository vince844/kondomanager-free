{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  Una seduta di «Le presenze e i voti» (`ProspettoDellaSala`): il nome e il giorno, l'apertura (o la deserta), presidente e segretario, la scelta del
  presidente all'apertura, le presenze con come, entrata e uscita; ogni votazione con l'ora, favorevoli, contrari, astenuti e chi non ha votato con i nomi e
  i millesimi, i conflitti, l'esito; le dichiarazioni a verbale. La usano il prospetto della sala e l'elenco in calce del verbale (`assemblea_verbale`).
  Riceve `$s` (una voce di `sedute`) e `$coloreTitoli`.
--}}
@php
    $cella = 'padding: 3px 4px; border-bottom: 1px solid #d5dde7; vertical-align: top;';
    $testa = 'text-align: left; padding: 4px; border-bottom: 1px solid #b8c4d2; background: #eef2f7; color: #1e3a5f; font-weight: bold;';
@endphp
<div style="font-size: 11pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 10px 0 2px;">{{ $s['nome'] }} · {{ $s['quando'] }}</div>
<div style="font-size: 9pt; color: #222; line-height: 1.45;">
    <b>{{ ucfirst($s['stato']) }}</b>@if($s['dal_foglio']) · registrata dal foglio della sala @endif
    @if(filled($s['presidente']) || filled($s['segretario']))
        <div>Presidente: {{ $s['presidente'] ?? '—' }} · Segretario: {{ $s['segretario'] ?? '—' }}</div>
    @endif
    @if(filled($s['costituzione']))<div>Il condominio in questa seduta: {{ $s['costituzione'] }}.</div>@endif
    @if(filled($s['scelta']))<div>{{ $s['scelta'] }}</div>@endif
    @foreach($s['deleghe_rifiutate'] ?? [] as $frase)<div>{{ $frase }}</div>@endforeach
</div>

@if($s['presenze'] !== [])
    <div style="font-size: 10pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 8px 0 3px;">Le presenze</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr>
                <th style="{{ $testa }} width: 30%;">Chi</th>
                <th style="{{ $testa }} width: 17%;">Come</th>
                <th style="{{ $testa }} width: 10%;">Entrata</th>
                <th style="{{ $testa }} width: 10%;">Uscita</th>
                <th style="{{ $testa }} width: 33%;">Note</th>
            </tr>
        </thead>
        <tbody>
            @foreach($s['presenze'] as $p)
                <tr>
                    <td style="{{ $cella }}">{{ $p['nome'] }}</td>
                    <td style="{{ $cella }}">{{ $p['come'] }}</td>
                    <td style="{{ $cella }}">{{ $p['entrata'] }}</td>
                    <td style="{{ $cella }}">{{ $p['uscita'] }}</td>
                    <td style="{{ $cella }}">{{ $p['nota'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@foreach($s['votazioni'] as $v)
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 8px; page-break-inside: avoid;">
        <tr><td style="padding: 3px 0; font-weight: bold;" colspan="3">{{ $v['titolo'] }}</td></tr>
        @if(filled($v['trattato']))
            <tr><td style="{{ $cella }}" colspan="3">{{ $v['trattato'] }}</td></tr>
        @else
            @foreach($v['righe'] as $i => $riga)
                @if($i === 0)<tr><td style="padding: 1px 0 3px; color: #333;" colspan="3">{{ $riga }}</td></tr>@endif
            @endforeach
            @foreach($v['gruppi'] as $g)
                <tr>
                    <td style="{{ $cella }} width: 14%; font-weight: bold;">{{ $g['nome'] }}</td>
                    <td style="{{ $cella }} width: 16%;">{{ $g['quanti'] }}</td>
                    <td style="{{ $cella }}">{{ $g['nomi'] }}</td>
                </tr>
            @endforeach
            @foreach($v['righe'] as $i => $riga)
                @if($i > 0)<tr><td style="padding: 2px 0; color: #333;" colspan="3">{{ $riga }}</td></tr>@endif
            @endforeach
        @endif
    </table>
@endforeach

@if($s['dichiarazioni'] !== [])
    <div style="font-size: 10pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 8px 0 3px;">Le dichiarazioni a verbale</div>
    @foreach($s['dichiarazioni'] as $d)
        <div style="font-size: 9pt; margin-bottom: 3px; line-height: 1.45;"><b>{{ $d['chi'] }}</b>, {{ $d['punto'] }}: «{{ $d['testo'] }}»</div>
    @endforeach
@endif
