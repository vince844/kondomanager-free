@extends('pdf.base')

@section('title', 'Prospetto degli oneri accessori – ' . $prospetto['unita']['etichetta'])

@section('content')
@php
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $data = fn (?string $iso) => $iso ? \Carbon\Carbon::parse($iso)->format('d/m/Y') : '—';
    $numero = fn (float $n) => rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    $piuPiani = count($prospetto['piani']) > 1;
    $tratti = fn (?array $lista) => $lista ? implode(' + ', array_map(fn ($t) => $data($t['dal']) . '–' . $data($t['al']), $lista)) : '—';
@endphp

{{-- Il documento del proprietario per il conduttore: B3a, 1.11.0-beta.34 (ProspettoOneriAccessori). --}}
<div style="margin-bottom: 10px; border-bottom: 2px solid #1e3a5f; padding-bottom: 10px; padding-top: 5px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        PROSPETTO DEGLI ONERI ACCESSORI — {{ mb_strtoupper($prospetto['unita']['etichetta']) }}
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        &nbsp;|&nbsp;
        (dal {{ $data($prospetto['esercizio']['dal']) }} al {{ $data($prospetto['esercizio']['al']) }})
        &nbsp;|&nbsp;
        Stampato il <strong>{{ now()->format('d/m/Y') }}</strong>
    </div>
</div>

<div style="background: #f5f8fc; border: 1px solid #d1dbe8; padding: 6px 9px; margin-bottom: 12px; font-size: 8pt; color: #333;">
    @foreach($prospetto['intestazione'] as $frase)
        <p style="margin: 0 0 4px 0;">{{ $frase }}</p>
    @endforeach
</div>

@forelse($prospetto['conduttori'] as $c)
    <div style="margin-top: 10px; page-break-inside: avoid;">
        <div style="font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #888; letter-spacing: 0.5px;">{{ $c['ruolo'] }}</div>
        <div style="font-size: 11pt; font-weight: bold; color: #2a8050;">{{ $c['nome'] }}</div>
        <div style="font-size: 8pt; color: #444; margin-bottom: 4px;">
            @if(! empty($c['tratti']))
                {{ $c['ruolo'] === 'Comodatario' ? 'In comodato' : 'Conduttore' }} nell'esercizio: {{ $tratti($c['tratti']) }}.
            @endif
        </div>
        <div style="font-size: 7.5pt; color: #555; font-style: italic; margin-bottom: 6px;">{{ $c['nota_contratto'] }}</div>

        <table style="width: 100%; border-collapse: collapse; font-size: 7.8pt;">
            <thead>
                <tr style="background: #1e3a5f; color: #fff;">
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Voce</th>
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Tabella e quota dell'unità</th>
                    <th style="padding: 4px 5px; text-align: right; color: #ffffff; font-weight: bold;">Quota inquilino</th>
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Competenza</th>
                    <th style="padding: 4px 5px; text-align: right; color: #ffffff; font-weight: bold;">Giorni</th>
                    <th style="padding: 4px 5px; text-align: right; color: #ffffff; font-weight: bold;">Importo</th>
                    <th style="padding: 4px 5px; text-align: left; color: #ffffff; font-weight: bold;">Come</th>
                </tr>
            </thead>
            <tbody>
                @foreach($c['voci'] as $v)
                    <tr style="border-bottom: 1px solid #e3e8ef;">
                        <td style="padding: 3px 5px;">{{ $v['conto'] }}@if($piuPiani)<br><span style="color: #888; font-size: 6.8pt;">{{ $v['piano'] }}</span>@endif</td>
                        <td style="padding: 3px 5px;">
                            @if($v['millesimi'])
                                {{ $v['tabella'] }}<br><span style="color: #888; font-size: 6.8pt;">{{ $numero($v['millesimi']['valore']) }} su {{ $numero($v['millesimi']['somma']) }} {{ $v['millesimi']['unita'] }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td style="padding: 3px 5px; text-align: right;">{{ $v['quota_inquilino'] !== null ? $v['quota_inquilino'] . ' %' : '—' }}</td>
                        <td style="padding: 3px 5px;">{{ $tratti($v['competenza']) }}</td>
                        <td style="padding: 3px 5px; text-align: right;">{{ $v['giorni'] ?? '—' }}</td>
                        <td style="padding: 3px 5px; text-align: right; white-space: nowrap;">{{ $euro($v['importo']) }}</td>
                        <td style="padding: 3px 5px; font-size: 7pt;">{{ $v['modo'] === 'rate' ? 'nelle sue rate' : 'pagata da ' . $v['pagato_da'] }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="background: #f5f8fc; font-weight: bold;">
                    <td colspan="5" style="padding: 4px 5px;">Totale a carico di {{ $c['nome'] }}</td>
                    <td style="padding: 4px 5px; text-align: right; white-space: nowrap;">{{ $euro($c['totale']) }}</td>
                    <td></td>
                </tr>
                @if($c['nelle_sue_rate'] !== 0 && $c['da_rimborsare'] !== 0)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">di cui già nelle sue rate</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt;">{{ $euro($c['nelle_sue_rate']) }}</td>
                        <td></td>
                    </tr>
                @endif
                @if($c['da_rimborsare'] !== 0)
                    <tr>
                        <td colspan="5" style="padding: 2px 5px; font-size: 7.5pt;">di cui da rimborsare a chi le ha pagate</td>
                        <td style="padding: 2px 5px; text-align: right; font-size: 7.5pt; white-space: nowrap;">{{ $euro($c['da_rimborsare']) }}</td>
                        <td></td>
                    </tr>
                @endif
            </tfoot>
        </table>
    </div>
@empty
    <p style="font-size: 9pt; color: #555;">Nessuna voce a carico dell'inquilino su questa unità nei piani rate approvati dell'esercizio.</p>
@endforelse

@if($prospetto['senza_conduttore']['totale'] !== 0)
    <div style="margin-top: 14px; page-break-inside: avoid;">
        <div style="font-size: 9pt; font-weight: bold; color: #1e3a5f; margin-bottom: 3px;">Giorni senza conduttore</div>
        <div style="font-size: 7.5pt; color: #555; margin-bottom: 5px;">Voci a carico dell'inquilino per giorni in cui nessun conduttore risulta registrato: restano a chi le ha pagate.</div>
        <table style="width: 100%; border-collapse: collapse; font-size: 7.8pt;">
            @foreach($prospetto['senza_conduttore']['voci'] as $v)
                <tr style="border-bottom: 1px solid #e3e8ef;">
                    <td style="padding: 3px 5px;">{{ $v['conto'] }}@if($piuPiani) <span style="color: #888;">({{ $v['piano'] }})</span>@endif @if(! empty($v['nota']))<br><span style="color: #888; font-size: 6.8pt;">{{ $v['nota'] }}</span>@endif</td>
                    <td style="padding: 3px 5px; text-align: right;">{{ $v['giorni'] ?? '—' }} giorni</td>
                    <td style="padding: 3px 5px; text-align: right; white-space: nowrap;">{{ $euro($v['importo']) }}</td>
                    <td style="padding: 3px 5px; font-size: 7pt;">pagata da {{ $v['pagato_da'] }}</td>
                </tr>
            @endforeach
            <tr style="background: #f5f8fc; font-weight: bold;">
                <td colspan="2" style="padding: 4px 5px;">Totale che resta a chi ha pagato</td>
                <td style="padding: 4px 5px; text-align: right; white-space: nowrap;">{{ $euro($prospetto['senza_conduttore']['totale']) }}</td>
                <td></td>
            </tr>
        </table>
    </div>
@endif

@if($prospetto['totale_inquilino'] !== 0)
    <p style="font-size: 7.5pt; color: #555; margin-top: 12px;">
        {{-- R22 (Fase 1-bis): il totale del ruolo inquilino si legge diviso, non come debito di un conduttore. --}}
        Totale delle voci che il riparto pone sul ruolo inquilino su questa unità nell'esercizio: <strong>{{ $euro($prospetto['totale_inquilino']) }}</strong>@if($prospetto['senza_conduttore']['totale'] !== 0), di cui {{ $euro($prospetto['totale_conduttori']) }} ai conduttori e {{ $euro($prospetto['senza_conduttore']['totale']) }} che restano a chi le ha pagate @endif.
    </p>
@endif

@endsection
