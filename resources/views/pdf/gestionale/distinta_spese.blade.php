@extends('pdf.base')

@section('title', 'Distinta delle Spese')

@section('content')

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 18px; border-bottom: 2px solid #1e3a5f; padding-bottom: 10px;">
    <h2 style="margin: 0; padding: 0; font-size: 14pt; color: #1e3a5f; letter-spacing: 0.5px;">
        DISTINTA DELLE SPESE
    </h2>
    <div style="font-size: 9pt; color: #444; margin-top: 4px;">
        Piano dei conti: <strong>{{ $pianoConto->nome }}</strong> &nbsp;|&nbsp;
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
    </div>
</div>

{{-- Con almeno una voce di entrata il documento le mostra col meno e riporta a parte spese, entrate e somma da ripartire. --}}
@php
    $conEntrate = ($totaleEntrate ?? 0) > 0;
    $eEntrata = fn ($voce) => $conEntrate && \App\Models\Gestionale\Conto::segnoDelTipo((string) $voce->tipo) < 0;
    $importoDi = fn ($voce) => $eEntrata($voce) ? -abs($voce->importo ?? 0) : ($voce->importo ?? 0);
    $mostra = fn ($centesimi) => number_format($centesimi / 100, 2, ',', '.');
@endphp

{{-- TABELLA VOCI DI SPESA --}}
<table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
    <thead>
        <tr style="background-color: #dce6f1; color: #1e3a5f;">
            <th style="padding: 6px 8px; text-align: left; border: 1px solid #b0c4de;">{{ $conEntrate ? 'Voce' : 'Voce di spesa' }}</th>
            <th style="padding: 6px 8px; text-align: right; border: 1px solid #b0c4de; width: 120px;">Importo (€)</th>
            <th style="padding: 6px 8px; text-align: right; border: 1px solid #b0c4de; width: 120px;">Totale gruppo (€)</th>
        </tr>
    </thead>
    <tbody>
        @php $totaleDoc = 0; @endphp
        @foreach($conti as $padre)
            @if($padre->is_tecnico) @continue @endif

            {{-- RIGA CAPITOLO PRINCIPALE --}}
            <tr style="background-color: #eef3fa;">
                <td style="padding: 6px 8px; font-weight: bold; color: #1e3a5f; border: 1px solid #b0c4de;" colspan="3">
                    {{ mb_strtoupper($padre->nome) }}
                </td>
            </tr>

            @php $totaleGruppo = 0; @endphp

            @forelse($padre->sottoconti->where('is_tecnico', false) as $sottoconto)
                <tr>
                    <td style="padding: 5px 8px 5px 20px; border-bottom: 1px solid #e2e8f0; border-left: 1px solid #b0c4de; border-right: 1px solid #b0c4de;">
                        {{ $sottoconto->nome }}@if($eEntrata($sottoconto)) (entrata)@endif
                        @if($sottoconto->note)
                            <br><span style="font-size: 7.5pt; color: #666;">{{ $sottoconto->note }}</span>
                        @endif
                    </td>
                    <td style="padding: 5px 8px; text-align: right; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #b0c4de;">
                        {{ $mostra($importoDi($sottoconto)) }}
                    </td>
                    <td style="padding: 5px 8px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #b0c4de;"></td>
                </tr>
                @php $totaleGruppo += $importoDi($sottoconto); @endphp
            @empty
                <tr>
                    <td style="padding: 5px 8px 5px 20px; border-bottom: 1px solid #e2e8f0; border-left: 1px solid #b0c4de; border-right: 1px solid #b0c4de;">
                        {{ $padre->nome }}@if($eEntrata($padre)) (entrata)@endif
                    </td>
                    <td style="padding: 5px 8px; text-align: right; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #b0c4de;">
                        {{ $mostra($importoDi($padre)) }}
                    </td>
                    <td style="padding: 5px 8px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #b0c4de;"></td>
                </tr>
                @php $totaleGruppo += $importoDi($padre); @endphp
            @endforelse

            {{-- RIGA TOTALE GRUPPO --}}
            <tr style="background-color: #f5f8fd;">
                <td style="padding: 5px 8px; text-align: right; border: 1px solid #b0c4de; font-size: 8.5pt; color: #444; font-style: italic;">
                    Totale {{ $padre->nome }}
                </td>
                <td style="padding: 5px 8px; border: 1px solid #b0c4de;"></td>
                <td style="padding: 5px 8px; text-align: right; border: 1px solid #b0c4de; font-weight: bold; color: #1e3a5f;">
                    {{ $mostra($totaleGruppo) }}
                </td>
            </tr>

            <tr><td colspan="3" style="height: 8px;"></td></tr>

            @php $totaleDoc += $totaleGruppo; @endphp
        @endforeach
    </tbody>
</table>

{{-- TOTALI FINALI --}}
<table style="width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 9.5pt;">
    <tr style="background-color: #dce6f1; font-weight: bold; color: #1e3a5f;">
        <td style="padding: 7px 8px; border: 2px solid #1e3a5f;">TOTALE PREVENTIVO</td>
        <td style="padding: 7px 8px; text-align: right; border: 2px solid #1e3a5f; width: 140px;">
            € {{ $mostra($totalePreventivo) }}
        </td>
    </tr>
    @if($conEntrate)
    <tr style="background-color: #eef3fa; font-weight: bold; color: #1e3a5f;">
        <td style="padding: 7px 8px; border: 1px solid #b0c4de;">ENTRATE</td>
        <td style="padding: 7px 8px; text-align: right; border: 1px solid #b0c4de;">
            € {{ $mostra($totaleEntrate) }}
        </td>
    </tr>
    <tr style="background-color: #dce6f1; font-weight: bold; color: #1e3a5f;">
        <td style="padding: 7px 8px; border: 2px solid #1e3a5f;">DA RIPARTIRE (spese meno entrate)</td>
        <td style="padding: 7px 8px; text-align: right; border: 2px solid #1e3a5f;">
            € {{ $mostra($totaleDaRipartire) }}
        </td>
    </tr>
    @endif
    @if($totaleSopravvenienze > 0)
    <tr style="background-color: #fdf6e8; font-weight: bold; color: #7a5c00;">
        <td style="padding: 7px 8px; border: 1px solid #b0c4de;">SOPRAVVENIENZE</td>
        <td style="padding: 7px 8px; text-align: right; border: 1px solid #b0c4de;">
            € {{ number_format($totaleSopravvenienze / 100, 2, ',', '.') }}
        </td>
    </tr>
    <tr style="background-color: #e8f0f8; font-weight: bold; font-size: 10.5pt; color: #1e3a5f;">
        <td style="padding: 7px 8px; border: 2px solid #1e3a5f;">TOTALE GENERALE ({{ $conEntrate ? 'da ripartire' : 'preventivo' }} + sopravvenienze)</td>
        <td style="padding: 7px 8px; text-align: right; border: 2px solid #1e3a5f;">
            € {{ $mostra(($conEntrate ? $totaleDaRipartire : $totalePreventivo) + $totaleSopravvenienze) }}
        </td>
    </tr>
    @endif
</table>

{{-- NOTE LEGALI --}}
<div style="margin-top: 24px; font-size: 7.5pt; color: #666; border-top: 1px solid #ccc; padding-top: 8px;">
    Documento generato ai sensi dell'art. 1135 c.c. e dell'art. 1130-bis c.c. (rendiconto condominiale).<br>
    Gli importi sono espressi in Euro (€) e si riferiscono al preventivo di spesa approvato dall'assemblea.
    @if($conEntrate)
        <br>Le entrate si sottraggono alle spese: il preventivo da ripartire fra i condòmini è la differenza.
    @endif
</div>

@endsection
