{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  Il foglio della sala (`FoglioDellaSala`): la sala sulla carta, da stampare il giorno prima. In testa le sedute dell'avviso da segnare, l'apertura con
  presidente e segretario, il numero legale; le teste con le unità, i millesimi, «Presente o delega a» (le deleghe ricevute già scritte), entrata, uscita
  e firma; i conduttori quando un punto li chiama; le deleghe ricevute e le righe per quelle che arrivano in sala; per ogni punto favorevoli, contrari,
  astenuti, l'ora e l'esito. Dopo, «Registra dal foglio della sala» riporta tutto nel programma con le ore scritte qui.
  Compilato con quello che c'è (a sala aperta): in testa «Compilato fino alle …», e i segni che la sala aveva già, in grigio e in grassetto (`$segnato`),
  distinti dalle caselle vuote da scrivere a penna.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $cella = 'padding: 4px; border: 1px solid #b8c4d2; vertical-align: top;';
    $testa = 'text-align: left; padding: 4px; border: 1px solid #b8c4d2; background: #eef2f7; color: #1e3a5f; font-weight: bold;';
    $vuoto = '&nbsp;';
    $segnato = 'background: #e4e9f0; font-weight: bold; color: #1f2937;';
@endphp

@section('title', 'Foglio della sala — ' . $condominio->nome)

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 5px; margin-bottom: 8px;">
        <h2 style="margin: 0; font-size: 14pt; color: {{ $coloreTitoli }};">Foglio della sala</h2>
        <div style="font-size: 9.5pt; color: #333; margin-top: 2px;">{{ $titolo }} · {{ $assemblea }}</div>
    </div>
    @if(filled($compilato))
        <div style="font-size: 9.5pt; border: 1px solid #b8c4d2; padding: 5px 7px; margin-bottom: 8px; line-height: 1.45;">
            <b>{{ $compilato }}</b>, con ciò che la sala aveva segnato fino a quell'ora.
            <div><span style="{{ $segnato }} padding: 0 4px;">In grigio</span> ciò che è già nel programma: non si registra di nuovo. Le caselle bianche si scrivono a penna, con l'ora.</div>
        </div>
    @endif
    <div style="font-size: 9pt; color: #333; margin-bottom: 8px; line-height: 1.45;">
        Se la rete o la corrente mancano, la sala si tiene su questo foglio. Le ore scritte qui decidono chi conta per ogni voto: chi entra dopo un voto non
        conta per quel voto, chi è uscito nemmeno.
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 6px;">
        @foreach($sedute as $s)
            <tr>
                <td style="{{ $cella }} width: 68%;">☐ <b>{{ $s['nome'] }}</b> · {{ $s['quando'] }}@if(filled($s['dove'])) · {{ $s['dove'] }}@endif</td>
                @if(filled($s['segnato'] ?? null))
                    <td style="{{ $cella }} {{ $segnato }}">{{ $s['segnato'] }}</td>
                @else
                    <td style="{{ $cella }}">deserta alle ________</td>
                @endif
            </tr>
        @endforeach
        <tr>
            @if(filled($apertura))
                <td style="{{ $cella }}" colspan="2">
                    <span style="{{ $segnato }}">{{ $apertura['seduta'] }} aperta alle {{ $apertura['aperta'] }} · Presidente {{ $apertura['presidente'] }} · Segretario {{ $apertura['segretario'] }}</span>
                    &nbsp; Chiusa alle ________
                </td>
            @else
                <td style="{{ $cella }}" colspan="2">
                    Aperta alle ________ &nbsp; Presidente ______________________ &nbsp; Segretario ______________________ &nbsp; Chiusa alle ________
                </td>
            @endif
        </tr>
    </table>
    <div style="font-size: 9pt; color: #333; margin-bottom: 10px; line-height: 1.45;">
        <b>{{ $totali }}</b>, i titolari del {{ $giorno_dei_titolari }}: all'apertura il programma li rilegge. {{ $numero_legale }}
        Una persona è una testa anche con più unità; un'unità in comproprietà ha un solo rappresentante.
    </div>

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 4px 0;">I condòmini</div>
    <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
        <thead>
            <tr>
                <th style="{{ $testa }} width: 28%;">Condòmino</th>
                <th style="{{ $testa }} width: 11%; text-align: right;">Millesimi</th>
                <th style="{{ $testa }} width: 22%;">Presente o delega a</th>
                <th style="{{ $testa }} width: 10%;">Entrata</th>
                <th style="{{ $testa }} width: 10%;">Uscita</th>
                <th style="{{ $testa }} width: 19%;">Firma</th>
            </tr>
        </thead>
        <tbody>
            @foreach($teste as $t)
                <tr>
                    <td style="{{ $cella }} padding-top: 6px; padding-bottom: 6px;">
                        {{ $t['nome'] }}
                        <div style="font-size: 8pt; color: #555;">{{ $t['unita'] }}</div>
                        @if(filled($t['nota']))<div style="font-size: 8pt; color: #555;">{{ $t['nota'] }}</div>@endif
                    </td>
                    <td style="{{ $cella }} text-align: right;">{{ $t['millesimi'] }}</td>
                    @if(filled($t['presenza'] ?? null))
                        <td style="{{ $cella }} {{ $segnato }}">{{ $t['presenza']['chi'] }}</td>
                        <td style="{{ $cella }} {{ $segnato }}">{{ $t['presenza']['entrata'] }}</td>
                        <td style="{{ $cella }}@if(filled($t['presenza']['uscita'])) {{ $segnato }}@endif">@if(filled($t['presenza']['uscita'])){{ $t['presenza']['uscita'] }}@else{!! $vuoto !!}@endif</td>
                    @else
                        <td style="{{ $cella }}">@if(filled($t['delega']))<span style="font-style: italic;">{{ $t['delega'] }}</span>@else{!! $vuoto !!}@endif</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    @endif
                    <td style="{{ $cella }}">{!! $vuoto !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($conduttori !== [])
        <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 12px; page-break-inside: avoid;">
            <thead>
                <tr>
                    <td colspan="5" style="padding: 0 0 4px;">
                        <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }};">I conduttori</div>
                        <div style="font-size: 8.5pt; color: #444;">Non contano per il numero legale: votano o intervengono solo sui punti che li chiamano.</div>
                    </td>
                </tr>
                <tr>
                    <th style="{{ $testa }} width: 39%;">Conduttore</th>
                    <th style="{{ $testa }} width: 22%;">Presente</th>
                    <th style="{{ $testa }} width: 10%;">Entrata</th>
                    <th style="{{ $testa }} width: 10%;">Uscita</th>
                    <th style="{{ $testa }} width: 19%;">Firma</th>
                </tr>
            </thead>
            <tbody>
                @foreach($conduttori as $c)
                    <tr>
                        <td style="{{ $cella }} padding-top: 6px; padding-bottom: 6px;">
                            {{ $c['nome'] }}
                            <div style="font-size: 8pt; color: #555;">{{ $c['unita'] }} · {{ $c['frase'] }}</div>
                        </td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 12px 0 4px;">Le deleghe ricevute</div>
    @if($deleghe === [])
        <div style="font-size: 9pt; color: #333;">Nessuna delega ricevuta in studio al {{ $stampato_il }}.</div>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
            <thead>
                <tr>
                    <th style="{{ $testa }} width: 40%;">Chi delega a chi</th>
                    <th style="{{ $testa }} width: 13%;">Ricevuta</th>
                    <th style="{{ $testa }} width: 25%;">Il quinto</th>
                    <th style="{{ $testa }} width: 22%;">Stato</th>
                </tr>
            </thead>
            <tbody>
                @foreach($deleghe as $d)
                    <tr>
                        <td style="{{ $cella }}">{{ $d['chi'] }}</td>
                        <td style="{{ $cella }}">{{ $d['ricevuta'] }}</td>
                        <td style="{{ $cella }}">{{ $d['quinto'] ?? '—' }}</td>
                        <td style="{{ $cella }}">{{ $d['stato'] }}@if(filled($d['motivo']))<div style="font-size: 8pt; color: #555;">{{ $d['motivo'] }}</div>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-top: 12px; page-break-inside: avoid;">
        <thead>
            <tr>
                <td colspan="4" style="padding: 0 0 4px;">
                    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }};">Le deleghe che arrivano in sala</div>
                    <div style="font-size: 8.5pt; color: #444;">Con più di venti condòmini il delegato non può rappresentare più di un quinto dei condòmini e del valore; l'amministratore non può ricevere deleghe (art. 67 c. 1 e c. 5 disp. att. c.c.).</div>
                </td>
            </tr>
            <tr>
                <th style="{{ $testa }} width: 32%;">Chi delega</th>
                <th style="{{ $testa }} width: 32%;">A chi</th>
                <th style="{{ $testa }} width: 12%;">Ora</th>
                <th style="{{ $testa }} width: 24%;">Firma del delegato</th>
            </tr>
        </thead>
        <tbody>
            @foreach($deleghe_in_sala as $d)
                <tr>
                    <td style="{{ $cella }} {{ $segnato }} height: 8mm;">{{ $d['chi'] }}</td>
                    <td style="{{ $cella }} {{ $segnato }}">{{ $d['a_chi'] }}</td>
                    <td style="{{ $cella }} {{ $segnato }}">{{ $d['ora'] }}</td>
                    <td style="{{ $cella }}">{!! $vuoto !!}</td>
                </tr>
            @endforeach
            @for($i = 0; $i < $righe_deleghe_in_sala; $i++)
                <tr>
                    <td style="{{ $cella }} height: 8mm;">{!! $vuoto !!}</td>
                    <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    <td style="{{ $cella }}">{!! $vuoto !!}</td>
                </tr>
            @endfor
        </tbody>
    </table>

    <div style="font-size: 10.5pt; font-weight: bold; color: {{ $coloreTitoli }}; margin: 14px 0 4px;">I voti</div>
    <div style="font-size: 8.5pt; color: #444; margin-bottom: 6px;">Per ogni voto: l'ora, chi è favorevole, contrario o astenuto con il numero e i millesimi, l'esito. Chi è in conflitto d'interessi vota e si segna con la nota.</div>
    @foreach($punti as $p)
        <table style="width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 8px; page-break-inside: avoid;">
            <tr>
                <td style="padding: 4px 0;" colspan="4">
                    <b>{{ $p['numero'] }}. {{ $p['testo'] }}</b>
                    <div style="font-size: 8pt; color: #555;">{{ $p['come'] }}</div>
                </td>
            </tr>
            @if($p['votazioni'] === [])
                @if(filled($p['trattato'] ?? null))
                    <tr><td style="{{ $cella }} {{ $segnato }}" colspan="4">{{ $p['trattato'] }}</td></tr>
                @else
                    <tr><td style="{{ $cella }}" colspan="4">☐ Trattato alle ________</td></tr>
                @endif
            @endif
            @foreach($p['votazioni'] as $voto)
                @php($fatto = $voto['segnato'] ?? null)
                @if(filled($voto['oggetto']))
                    <tr><td style="padding: 3px 0 1px; font-weight: bold; color: {{ $coloreTitoli }};" colspan="4">{{ $voto['oggetto'] }}</td></tr>
                @endif
                <tr>
                    <th style="{{ $testa }} width: 14%;">&nbsp;</th>
                    <th style="{{ $testa }} width: 58%;">Chi (i nomi)</th>
                    <th style="{{ $testa }} width: 12%;">Quanti</th>
                    <th style="{{ $testa }} width: 16%;">Millesimi</th>
                </tr>
                @if(filled($fatto))
                    @foreach(['favorevoli' => 'Favorevoli', 'contrari' => 'Contrari', 'astenuti' => 'Astenuti'] as $chiave => $parola)
                        <tr>
                            <td style="{{ $cella }}">{{ $parola }}</td>
                            <td style="{{ $cella }} {{ $segnato }} height: 11mm;">{{ $fatto[$chiave]['nomi'] }}</td>
                            <td style="{{ $cella }} {{ $segnato }}">{{ $fatto[$chiave]['quanti'] }}</td>
                            <td style="{{ $cella }} {{ $segnato }}">{{ $fatto[$chiave]['millesimi'] }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="{{ $cella }}" colspan="4"><span style="{{ $segnato }}">Votato alle {{ $fatto['ora'] }}@if(filled($fatto['conflitto'])) · In conflitto d'interessi {{ $fatto['conflitto'] }}@endif · Esito {{ $fatto['esito'] }}</span>@if($fatto['esito'] === 'da confermare') &nbsp; Esito confermato ______________________ @endif</td>
                    </tr>
                @else
                    <tr>
                        <td style="{{ $cella }}">Favorevoli</td>
                        <td style="{{ $cella }} height: 11mm;">☐ Tutti i presenti, tranne i contrari e gli astenuti</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    </tr>
                    <tr>
                        <td style="{{ $cella }}">Contrari</td>
                        <td style="{{ $cella }} height: 11mm;">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    </tr>
                    <tr>
                        <td style="{{ $cella }}">Astenuti</td>
                        <td style="{{ $cella }} height: 11mm;">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                        <td style="{{ $cella }}">{!! $vuoto !!}</td>
                    </tr>
                    <tr>
                        <td style="{{ $cella }}" colspan="4">Votato alle ________ &nbsp; In conflitto d'interessi ______________________ &nbsp; Esito ______________________</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endforeach

    <div style="font-size: 8.5pt; color: #444; margin-top: 10px; line-height: 1.5; page-break-inside: avoid;">
        <div>Dopo l'assemblea, nel passo «In sala» dell'assemblea, «Registra dal foglio della sala» riporta nel programma presenze, deleghe e voti con le ore di questo foglio; il diario lo dice.</div>
        <div>Le deleghe ricevute fino al {{ $stampato_il }} sono già scritte: se ne arriva un'altra, ristampa il foglio.</div>
        @foreach($avvisi as $avviso)
            <div>{{ $avviso }}</div>
        @endforeach
    </div>
@endsection
