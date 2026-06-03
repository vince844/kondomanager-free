@extends('pdf.base')

@section('title', 'Scadenziario Rate')

@section('content')

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 14px; border-bottom: 2px solid #1e3a5f; padding-bottom: 8px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        SCADENZIARIO RATE
        @if($modalita === 'anagrafica') — PER CONDÒMINO
        @elseif($modalita === 'immobile') — PER UNITÀ IMMOBILIARE
        @else — PER CONDÒMINO E PER UNITÀ IMMOBILIARE
        @endif
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Piano rate: <strong>{{ $pianoRate->nome }}</strong> &nbsp;|&nbsp;
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
        &nbsp;|&nbsp;
        Stato: <strong>{{ ucfirst($pianoRate->stato->value ?? $pianoRate->stato) }}</strong>
        &nbsp;|&nbsp;
        N° rate: <strong>{{ $pianoRate->numero_rate }}</strong>
    </div>
</div>

{{-- =========================================================== --}}
{{-- TABELLA PER ANAGRAFICA                                       --}}
{{-- =========================================================== --}}
@if($matriceAnagrafica !== null)
    @if($modalita === 'entrambi')
        <h3 style="font-size: 10pt; color: #1e3a5f; margin: 0 0 6px 0; border-left: 4px solid #1e3a5f; padding-left: 8px;">
            Prospetto per Condòmino
        </h3>
    @endif

    @if(empty($matriceAnagrafica))
        <p style="color: #888; font-style: italic; text-align: center; margin: 20px 0;">
            Nessuna quota registrata per questo piano rate.
        </p>
    @else
        @include('pdf.gestionale._tabella_scadenziario', [
            'matrice'    => $matriceAnagrafica,
            'colonneRate'=> $colonneRate,
            'labelColonna' => 'Condòmino / Intestatario',
            'hasSottoEtichetta' => false,
        ])

        <div style="font-size: 7pt; color: #666; margin-top: 4px;">
            * Ogni condòmino può avere più unità immobiliari: l'importo mostrato è la somma di tutte le sue quote.
        </div>
    @endif
@endif

{{-- =========================================================== --}}
{{-- SEPARATORE TRA LE DUE SEZIONI (solo in modalità "entrambi") --}}
{{-- =========================================================== --}}
@if($modalita === 'entrambi' && $matriceAnagrafica !== null && $matriceImmobile !== null)
    <div style="page-break-after: always;"></div>

    {{-- Ripeto header del documento sulla seconda pagina --}}
    <div style="margin-bottom: 14px; border-bottom: 2px solid #1e3a5f; padding-bottom: 8px;">
        <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
            SCADENZIARIO RATE — PER CONDÒMINO E PER UNITÀ IMMOBILIARE
        </h2>
        <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
            Piano rate: <strong>{{ $pianoRate->nome }}</strong> &nbsp;|&nbsp;
            Esercizio: <strong>{{ $esercizio->nome }}</strong>
        </div>
    </div>
@endif

{{-- =========================================================== --}}
{{-- TABELLA PER IMMOBILE                                         --}}
{{-- =========================================================== --}}
@if($matriceImmobile !== null)
    @if($modalita === 'entrambi')
        <h3 style="font-size: 10pt; color: #1e3a5f; margin: 0 0 6px 0; border-left: 4px solid #1e3a5f; padding-left: 8px;">
            Prospetto per Unità Immobiliare
        </h3>
    @endif

    @if(empty($matriceImmobile))
        <p style="color: #888; font-style: italic; text-align: center; margin: 20px 0;">
            Nessuna unità immobiliare trovata in questo piano rate.
        </p>
    @else
        @include('pdf.gestionale._tabella_scadenziario', [
            'matrice'    => $matriceImmobile,
            'colonneRate'=> $colonneRate,
            'labelColonna' => 'Unità Immobiliare / Intestatario',
            'hasSottoEtichetta' => true,
        ])

        <div style="font-size: 7pt; color: #666; margin-top: 4px;">
            * Ogni riga rappresenta una singola unità immobiliare.
            Il nome del condòmino indicato è il primo intestatario registrato.
        </div>
    @endif
@endif

{{-- NOTE DELIBERA --}}
@if($pianoRate->data_delibera_assemblea)
<div style="margin-top: 14px; padding: 6px 8px; background-color: #f5f8fc; border-left: 3px solid #1e3a5f; font-size: 7.5pt; color: #444;">
    Piano rate approvato con delibera assembleare del
    <strong>{{ $pianoRate->data_delibera_assemblea->format('d/m/Y') }}</strong>
    @if($pianoRate->numero_verbale)
        – Verbale n. <strong>{{ $pianoRate->numero_verbale }}</strong>
    @endif
</div>
@endif

{{-- NOTE LEGALI --}}
<div style="margin-top: 12px; font-size: 7pt; color: #666; border-top: 1px solid #ccc; padding-top: 6px;">
    Documento redatto ai sensi dell'art. 1135 c.c. e dell'art. 63 disp. att. c.c.
    (riscossione dei contributi condominiali). Il mancato pagamento entro la scadenza indicata può comportare
    l'applicazione degli interessi legali ai sensi dell'art. 1284 c.c.
</div>

@endsection
