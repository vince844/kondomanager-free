@extends('pdf.base')

@section('title', 'Libro Giornale – ' . $esercizio->nome)

@section('content')

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 12px; border-bottom: 2px solid #1e3a5f; padding-bottom: 10px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        LIBRO GIORNALE
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        &nbsp;|&nbsp;
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
        &nbsp;|&nbsp;
        Stampato il <strong>{{ now()->format('d/m/Y H:i') }}</strong>
    </div>
</div>

{{--
  ⚠️ **Il perimetro si dichiara, sempre.** `applyFiltri()` applica cinque filtri: se il PDF ne
  mostrasse solo alcuni senza dirlo, un estratto uscirebbe intitolato «LIBRO GIORNALE» con un
  totale parziale che sembra il totale del registro. Su un documento che può finire in assemblea
  o davanti a un CTU è tacere per omissione.
--}}
{{--
  ⚠️ **Niente `Carbon::parse()` qui.** Il controller consegna già `data_da`/`data_a` formattate
  (`self::dataFiltroLeggibile()`) o null: un valore non interpretabile diventava "nessun filtro"
  prima di arrivare a questa vista, non un 500 mentre la rendo. La vista si limita a leggere.
--}}
@php
    $filtriAttivi = [];
    if ($filtri['data_da'] || $filtri['data_a']) {
        $filtriAttivi[] = 'periodo dal '
            .($filtri['data_da'] ?: 'inizio esercizio')
            .' al '
            .($filtri['data_a'] ?: 'fine esercizio');
    }
    if (! empty($filtri['stato']))          { $filtriAttivi[] = 'stato: '.$filtri['stato']; }
    if (! empty($filtri['tipo_movimento'])) { $filtriAttivi[] = 'tipo movimento: '.$filtri['tipo_movimento']; }
    if (! empty($filtri['search']))         { $filtriAttivi[] = 'ricerca: «'.$filtri['search'].'»'; }
@endphp

@if(count($filtriAttivi) > 0)
<div style="margin-bottom: 10px; padding: 6px 9px; border: 1px solid #f0c36d; background: #fdf6e8; font-size: 8pt; color: #7a5c00;">
    <strong>Estratto parziale.</strong> Questo documento non contiene tutte le scritture
    dell'esercizio: sono stati applicati i filtri
    <strong>{{ implode(' · ', $filtriAttivi) }}</strong>.
    I totali in fondo si riferiscono alle sole righe qui riportate.
</div>
@endif

{{--
  ⚠️ Questo NON è il fascicolo di rendiconto (§10.5 di docs/registri_contabili.md): è il registro
  delle scritture in partita doppia, riga per riga. Chi cerca entrate/uscite trova quel registro
  nel punto 1 della sequenza (art. 1130 n. 7 c.c.), non qui.
--}}
<div style="font-size: 7.5pt; color: #888; font-style: italic; margin-bottom: 8px;">
    Registro cronologico delle scritture in partita doppia. Non sostituisce il registro di
    contabilità (entrate e uscite) previsto dall'art. 1130, comma 1, n. 7 c.c.
</div>

{{--
  ⚠️ **La tabella si spezza in blocchi, e il motivo è la memoria — misurato.**

  mPDF tiene in memoria l'INTERA tabella per calcolare le larghezze delle colonne: con una
  tabella sola il picco cresce linearmente e la stampa muore di fatal error dentro mPDF (pagina
  bianca, nessuna eccezione applicativa, niente nei log). Misurato su questo stesso template:

  | 6.000 righe        | tabella unica | blocchi da 300 |
  | ------------------ | ------------- | -------------- |
  | picco di memoria   | ~766 MB       | **102 MB**     |
  | tempo              | ~18 s         | ~18 s          |
  | dimensione del PDF | 460 KB        | 462 KB         |

  Con `memory_limit = 128M` — che questo progetto dichiara «esattamente il parco installato»
  (AggiornaComuniCommand.php:20, e altre tre volte nel codice) — la tabella unica moriva a
  **1.000 righe**; a blocchi regge oltre **12.000** (117 MB). Un anno ordinario di condominio
  sta abbondantemente dentro.

  ⚠️ **`<pagebreak />` e non `page-break-before: always`**: la proprietà CSS su `<table>` mPDF la
  ignora (verificato: stesso numero di pagine, giunzione ancora a metà pagina). Senza il salto,
  alla fine di ogni blocco ricompare una riga di intestazione **in mezzo** alla pagina.

  ⚠️ Le larghezze delle colonne sono dichiarate sui `<th>` apposta: senza, ogni blocco essendo
  una tabella a sé le calcolerebbe per conto proprio e le colonne si disallineerebbero fra un
  blocco e l'altro. Con le larghezze fisse restano allineate — verificato a video sulla giunzione.

  Provate e scartate: `packTableData` (rotto in questa versione di mPDF: «Trying to access array
  offset on int» in Mpdf.php:21500), `simpleTables` (−15%, non basta), stili in classi CSS invece
  che inline (−4%: il costo non è il parsing degli stili).
--}}
@foreach(array_chunk($righe, 300) as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background: #eef2f8;">
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; white-space: nowrap; text-align: left; width: 60px;">Data</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: left; width: 80px;">Protocollo</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: left;">Causale</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: left;">Conto contabile</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: right; width: 75px;">Dare</th>
            <th style="padding: 4px 5px; font-size: 6.5pt; font-weight: bold; text-transform: uppercase; color: #555; border-bottom: 1.5px solid #b8c5d6; text-align: right; width: 75px;">Avere</th>
        </tr>
    </thead>
    <tbody>
        @foreach($blocco as $riga)
        <tr style="border-bottom: 0.3px solid #edf2f7; {{ $loop->even ? 'background: #fafbfc;' : '' }}">
            <td style="padding: 4px 5px; color: #444; white-space: nowrap; vertical-align: top;">{{ $riga['data'] }}</td>
            <td style="padding: 4px 5px; font-family: monospace; font-size: 6.5pt; color: #999; vertical-align: top;">{{ $riga['protocollo'] ?? '—' }}</td>
            <td style="padding: 4px 5px; vertical-align: top;">{{ $riga['causale'] }}</td>
            <td style="padding: 4px 5px; vertical-align: top;">
                <div style="font-weight: 600; color: #1e3a5f;">
                    {{ $riga['conto_nome'] }}
                    @if($riga['conto_codice'])
                        <span style="font-size: 6pt; color: #999; font-family: monospace;">({{ $riga['conto_codice'] }})</span>
                    @endif
                </div>
                @if($riga['dettaglio'])
                    <div style="font-size: 6.5pt; color: #666; font-style: italic; margin-top: 1px;">{{ $riga['dettaglio'] }}</div>
                @endif
            </td>
            <td style="padding: 4px 5px; text-align: right; vertical-align: top; font-family: monospace; color: #276749; font-weight: 600; white-space: nowrap;">
                {{ $riga['dare'] !== null ? '€ '.number_format($riga['dare'] / 100, 2, ',', '.') : '' }}
            </td>
            <td style="padding: 4px 5px; text-align: right; vertical-align: top; font-family: monospace; color: #2b6cb0; font-weight: 600; white-space: nowrap;">
                {{ $riga['avere'] !== null ? '€ '.number_format($riga['avere'] / 100, 2, ',', '.') : '' }}
            </td>
        </tr>
        @endforeach
    </tbody>
    {{-- Il totale una volta sola, in fondo all'ultimo blocco: ripeterlo a ogni blocco lo
         farebbe sembrare un subtotale di pagina, che non è. --}}
    @if($loop->last)
    <tfoot>
        <tr style="background: #dce6f1; font-weight: bold; color: #1e3a5f;">
            <td colspan="4" style="padding: 6px 5px; border-top: 2px solid #1e3a5f; text-align: right; white-space: nowrap;">{{ count($filtriAttivi) > 0 ? 'TOTALE (parziale)' : 'TOTALE' }}</td>
            <td style="padding: 6px 5px; border-top: 2px solid #1e3a5f; text-align: right; font-family: monospace; white-space: nowrap;">
                € {{ number_format($totale_dare / 100, 2, ',', '.') }}
            </td>
            <td style="padding: 6px 5px; border-top: 2px solid #1e3a5f; text-align: right; font-family: monospace; white-space: nowrap;">
                € {{ number_format($totale_avere / 100, 2, ',', '.') }}
            </td>
        </tr>
    </tfoot>
    @endif
</table>
@endforeach

{{-- ⚠️ Fuori dal ciclo dei blocchi, non dentro: con zero righe `array_chunk([], 300)` è un
     array vuoto, il `@foreach` non gira nemmeno una volta e senza questo ramo il PDF
     uscirebbe muto — nessuna tabella e nessuna spiegazione. --}}
@if(count($righe) === 0)
<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <tr>
        <td style="padding: 16px; text-align: center; color: #999; font-style: italic;">
            Nessuna scrittura contabile nel periodo selezionato.
        </td>
    </tr>
</table>
@endif

@endsection
