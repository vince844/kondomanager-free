@extends('pdf.base')

@section('title', 'Estratto Conto – ' . $anagrafica->nome)

@section('content')

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 12px; border-bottom: 2px solid #1e3a5f; padding-bottom: 12px; padding-top: 5px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        ESTRATTO CONTO — {{ strtoupper($anagrafica->nome) }}
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        &nbsp;|&nbsp;
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
        &nbsp;|&nbsp;
        Stampato il <strong>{{ now()->format('d/m/Y H:i') }}</strong>
    </div>
</div>

{{-- SCHEDA ANAGRAFICA --}}
<table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8.5pt;">
    <tr>
        <td style="width: 60%; vertical-align: top; padding: 6px 8px; background: #f5f8fc; border: 1px solid #d1dbe8; border-radius: 3px;">
            <div style="font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #888; letter-spacing: 0.5px; margin-bottom: 3px;">Anagrafica</div>
            <div style="font-size: 11pt; font-weight: bold; color: #1e3a5f;">{{ $anagrafica->nome }}</div>
            @if($anagrafica->codice_fiscale)
                <div style="font-size: 7.5pt; font-family: monospace; color: #666; margin-top: 2px;">CF: {{ $anagrafica->codice_fiscale }}</div>
            @endif
            @if($anagrafica->email)
                <div style="font-size: 7.5pt; color: #555; margin-top: 1px;">✉ {{ $anagrafica->email }}</div>
            @endif
        </td>
        <td style="width: 40%; vertical-align: top; padding: 6px 8px; background: #f5f8fc; border: 1px solid #d1dbe8; border-left: none;">
            <div style="font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #888; letter-spacing: 0.5px; margin-bottom: 3px;">Unità Immobiliari</div>
            @foreach($anagrafica->immobili as $imm)
                <div style="font-size: 8pt; color: #333; margin-bottom: 1px;">
                    Int. {{ $imm->interno }}{{ $imm->piano ? ' — P. ' . $imm->piano : '' }}
                    <span style="color: #888; font-size: 7pt;">({{ $imm->pivot->tipologia ?? '' }} · {{ $imm->pivot->quota ?? '' }}%)</span>
                </div>
            @endforeach
        </td>
    </tr>
</table>

{{-- RIEPILOGO SALDI (4 card) --}}
<table style="width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 8pt;">
    <tr>
        {{-- Saldo iniziale --}}
        <td style="width: 25%; padding: 6px 8px; border: 1px solid #d1dbe8; border-radius: 3px; background: #f9fafb;">
            <div style="font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #999; letter-spacing: 0.5px; margin-bottom: 2px;">Saldo iniziale</div>
            <div style="font-size: 10pt; font-weight: bold; color: {{ $saldoInizialeCents > 0 ? '#c53030' : ($saldoInizialeCents < 0 ? '#276749' : '#4a5568') }};">
                {{ $stats['saldo_iniziale'] }}
            </div>
            <div style="font-size: 6pt; font-weight: bold; text-transform: uppercase; margin-top: 1px; color: {{ $saldoInizialeCents > 0 ? '#c53030' : ($saldoInizialeCents < 0 ? '#276749' : '#718096') }};">
                {{ $saldoInizialeCents > 0 ? 'A DEBITO' : ($saldoInizialeCents < 0 ? 'A CREDITO' : 'PAREGGIO') }}
            </div>
        </td>
        <td style="width: 3px;"></td>
        {{-- Totale addebiti --}}
        <td style="width: 25%; padding: 6px 8px; border: 1px solid #d1dbe8; border-radius: 3px; background: #f9fafb;">
            <div style="font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #999; letter-spacing: 0.5px; margin-bottom: 2px;">Totale addebiti</div>
            <div style="font-size: 10pt; font-weight: bold; color: #1a202c;">{{ $stats['totale_addebiti'] }}</div>
            <div style="font-size: 6pt; text-transform: uppercase; margin-top: 1px; color: #aaa;">Rate emesse</div>
        </td>
        <td style="width: 3px;"></td>
        {{-- Totale versato --}}
        <td style="width: 25%; padding: 6px 8px; border: 1px solid #d1dbe8; border-radius: 3px; background: #f9fafb;">
            <div style="font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #999; letter-spacing: 0.5px; margin-bottom: 2px;">Totale versato</div>
            <div style="font-size: 10pt; font-weight: bold; color: #276749;">{{ $stats['totale_versamenti'] }}</div>
            <div style="font-size: 6pt; text-transform: uppercase; margin-top: 1px; color: #aaa;">Incassi registrati</div>
        </td>
        <td style="width: 3px;"></td>
        {{-- Saldo finale --}}
        @php $saldoRaw = $stats['saldo_raw']; @endphp
        <td style="width: 25%; padding: 6px 8px; border: 1px solid {{ $saldoRaw > 0 ? '#fc8181' : ($saldoRaw < 0 ? '#68d391' : '#d1dbe8') }}; border-radius: 3px; background: {{ $saldoRaw > 0 ? '#fff5f5' : ($saldoRaw < 0 ? '#f0fff4' : '#f9fafb') }};">
            <div style="font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: {{ $saldoRaw > 0 ? '#c53030' : ($saldoRaw < 0 ? '#276749' : '#999') }}; letter-spacing: 0.5px; margin-bottom: 2px;">Saldo finale</div>
            <div style="font-size: 11pt; font-weight: bold; color: {{ $saldoRaw > 0 ? '#c53030' : ($saldoRaw < 0 ? '#276749' : '#4a5568') }};">
                {{ $stats['saldo_finale'] }}
            </div>
            <div style="font-size: 6pt; font-weight: bold; text-transform: uppercase; margin-top: 1px; color: {{ $saldoRaw > 0 ? '#c53030' : ($saldoRaw < 0 ? '#276749' : '#718096') }};">
                {{ $saldoRaw > 0 ? 'DA VERSARE' : ($saldoRaw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
            </div>
        </td>
    </tr>
</table>

{{-- TITOLO SEZIONE MOVIMENTI --}}
<div style="font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #1e3a5f; border-bottom: 1px solid #cbd5e0; padding-bottom: 3px; margin-bottom: 5px; display: flex; justify-content: space-between;">
    Movimenti contabili
    <span style="font-weight: normal; text-transform: none; color: #888; font-size: 7.5pt;">{{ $esercizio->nome }}</span>
</div>

{{-- TABELLA MOVIMENTI --}}
<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background: #eef2f8;">
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; white-space: nowrap; text-align: left; width: 70px;">Data</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: left; width: 90px;">Protocollo</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: left;">Descrizione</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: right; width: 80px;">Addebiti<br><span style="font-weight: normal; font-size: 5.5pt; text-transform: none;">(Dare)</span></th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: right; width: 80px;">Pagamenti<br><span style="font-weight: normal; font-size: 5.5pt; text-transform: none;">(Avere)</span></th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: right; width: 85px; background: #e8eef7;">Saldo</th>
        </tr>
    </thead>
    <tbody>
        {{-- Riga Saldo Iniziale --}}
        <tr style="background: #fffbeb; border-bottom: 0.3px solid #e2e8f0;">
            <td style="padding: 4px 5px; color: #666; white-space: nowrap;">{{ $esercizio->data_inizio->format('d/m/Y') }}</td>
            <td style="padding: 4px 5px; font-family: monospace; font-size: 6.5pt; color: #aaa;">—</td>
            <td style="padding: 4px 5px; font-weight: 600; color: #1e3a5f;">Saldo iniziale esercizio</td>
            <td style="padding: 4px 5px; text-align: right; color: #ccc;">—</td>
            <td style="padding: 4px 5px; text-align: right; color: #ccc;">—</td>
            <td style="padding: 4px 5px; text-align: right; font-family: monospace; font-weight: bold; background: #f5f7fa; color: {{ $saldoInizialeCents > 0 ? '#c53030' : ($saldoInizialeCents < 0 ? '#276749' : '#718096') }};">
                {{ $stats['saldo_iniziale'] }}
            </td>
        </tr>

        {{-- Righe Movimenti --}}
        @foreach($timeline as $riga)
        <tr style="border-bottom: 0.3px solid #edf2f7; {{ $loop->even ? 'background: #fafbfc;' : '' }}">
            <td style="padding: 4px 5px; color: #444; white-space: nowrap; vertical-align: top;">{{ $riga['data'] }}</td>
            <td style="padding: 4px 5px; font-family: monospace; font-size: 6.5pt; color: #999; vertical-align: top;">{{ $riga['protocollo'] ?? '—' }}</td>
            <td style="padding: 4px 5px; vertical-align: top;">
                <div style="font-weight: 600; color: #1e3a5f;">
                    {{ $riga['descrizione'] }}
                    @if($riga['gestione'])
                        <span style="font-size: 6pt; background: #f1f5f9; color: #555; border-radius: 2px; padding: 0 3px; margin-left: 3px; font-weight: normal;">{{ $riga['gestione'] }}</span>
                    @endif
                </div>
                @if($riga['note'])
                    <div style="font-size: 6.5pt; color: #3182ce; font-style: italic; margin-top: 1px;">{{ $riga['note'] }}</div>
                @endif
                @foreach($riga['dettagli'] ?? [] as $det)
                    <div style="font-size: 6.5pt; color: #666; margin-top: 1px;">
                        • {{ $det['text'] }}
                        @if(!empty($det['status']))
                            @php
                                $statoStyles = [
                                    'pagata'             => 'background:#f0fff4;color:#276749;border:0.3px solid #9ae6b4;',
                                    'parzialmente_pagata'=> 'background:#fffaf0;color:#7b341e;border:0.3px solid #fbd38d;',
                                    'partial'            => 'background:#fffaf0;color:#7b341e;border:0.3px solid #fbd38d;',
                                    'da_pagare'          => 'background:#fff5f5;color:#9b2c2c;border:0.3px solid #feb2b2;',
                                    'pending'            => 'background:#fff5f5;color:#9b2c2c;border:0.3px solid #feb2b2;',
                                    'credito'            => 'background:#ebf8ff;color:#2b6cb0;border:0.3px solid #90cdf4;',
                                    'credito_puro'       => 'background:#ebf8ff;color:#2b6cb0;border:0.3px solid #90cdf4;',
                                    'stornata'           => 'background:#f7fafc;color:#718096;border:0.3px solid #cbd5e0;',
                                    'stornato'           => 'background:#f7fafc;color:#718096;border:0.3px solid #cbd5e0;',
                                ];
                                $statoLabels = [
                                    'pagata'             => 'PAGATA',
                                    'parzialmente_pagata'=> 'PARZIALE',
                                    'partial'            => 'PARZIALE',
                                    'da_pagare'          => 'NON PAGATA',
                                    'pending'            => 'NON PAGATA',
                                    'credito'            => 'COMPENSATA',
                                    'credito_puro'       => 'CREDITO',
                                    'stornata'           => 'STORNATA',
                                    'stornato'           => 'STORNATA',
                                ];
                                $stile = $statoStyles[$det['status']] ?? '';
                                $label = $statoLabels[$det['status']] ?? strtoupper($det['status']);
                            @endphp
                            @if($stile)
                                <span style="font-size: 5.5pt; font-weight: bold; padding: 0 2.5px; border-radius: 1.5px; {{ $stile }}">{{ $label }}</span>
                            @endif
                        @endif
                    </div>
                @endforeach
            </td>
            <td style="padding: 4px 5px; text-align: right; vertical-align: top; white-space: nowrap;">
                @if($riga['dare'] > 0)
                    <span style="color: #1a202c; font-weight: 600;">€ {{ number_format($riga['dare'] / 100, 2, ',', '.') }}</span>
                @else
                    <span style="color: #ccc;">—</span>
                @endif
            </td>
            <td style="padding: 4px 5px; text-align: right; vertical-align: top; white-space: nowrap;">
                @if($riga['avere'] > 0)
                    <span style="color: #276749; font-weight: bold;">€ {{ number_format($riga['avere'] / 100, 2, ',', '.') }}</span>
                @else
                    <span style="color: #ccc;">—</span>
                @endif
            </td>
            <td style="padding: 4px 5px; text-align: right; vertical-align: top; background: #f5f7fa; white-space: nowrap;">
                @php $saldoRiga = $riga['saldo']; @endphp
                <span style="font-family: monospace; font-weight: bold; color: {{ $saldoRiga > 0 ? '#c53030' : ($saldoRiga < 0 ? '#276749' : '#718096') }};">
                    € {{ number_format(abs($saldoRiga) / 100, 2, ',', '.') }}
                    {{ $saldoRiga > 0 ? '▲' : ($saldoRiga < 0 ? '▼' : '') }}
                </span>
            </td>
        </tr>
        @endforeach

        @if(count($timeline) === 0)
        <tr>
            <td colspan="6" style="padding: 20px; text-align: center; color: #aaa; font-style: italic;">
                Nessun movimento registrato per questo esercizio.
            </td>
        </tr>
        @endif
    </tbody>
    <tfoot>
        <tr style="border-top: 2px solid #1e3a5f;">
            <td colspan="3" style="padding: 6px 5px;"></td>
            <td style="padding: 6px 5px; text-align: right; vertical-align: bottom;">
                <div style="font-size: 6pt; text-transform: uppercase; color: #888; font-weight: 600; letter-spacing: 0.5px;">Totale addebiti</div>
                <div style="font-size: 8.5pt; font-weight: bold; font-family: monospace; color: #1a202c;">{{ $stats['totale_addebiti'] }}</div>
            </td>
            <td style="padding: 6px 5px; text-align: right; vertical-align: bottom;">
                <div style="font-size: 6pt; text-transform: uppercase; color: #888; font-weight: 600; letter-spacing: 0.5px;">Totale versato</div>
                <div style="font-size: 8.5pt; font-weight: bold; font-family: monospace; color: #276749;">{{ $stats['totale_versamenti'] }}</div>
            </td>
            @php $saldoRaw = $stats['saldo_raw']; @endphp
            <td style="padding: 6px 5px; text-align: right; vertical-align: bottom; background: #f5f8fc; border-left: 1px solid #cbd5e0;">
                <div style="font-size: 6.5pt; text-transform: uppercase; color: #1e3a5f; font-weight: 800; letter-spacing: 0.5px;">Saldo finale</div>
                <div style="font-size: 11pt; font-weight: bold; font-family: monospace; color: {{ $saldoRaw > 0 ? '#c53030' : ($saldoRaw < 0 ? '#276749' : '#4a5568') }};">
                    {{ $stats['saldo_finale'] }}
                </div>
                <div style="font-size: 5.5pt; font-weight: bold; text-transform: uppercase; color: {{ $saldoRaw > 0 ? '#c53030' : ($saldoRaw < 0 ? '#276749' : '#718096') }};">
                    {{ $saldoRaw > 0 ? 'DA VERSARE' : ($saldoRaw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                </div>
            </td>
        </tr>
    </tfoot>
</table>

{{-- NOTE LEGALI --}}
<div style="margin-top: 12px; font-size: 6.5pt; color: #888; border-top: 0.5px solid #ccc; padding-top: 5px;">
    Documento redatto ai sensi dell'art. 1135 c.c. (gestione condominiale) e dell'art. 63 disp. att. c.c.
    (riscossione dei contributi condominiali). Il mancato pagamento entro la scadenza indicata può comportare
    l'applicazione degli interessi legali ai sensi dell'art. 1284 c.c.
</div>

@endsection
