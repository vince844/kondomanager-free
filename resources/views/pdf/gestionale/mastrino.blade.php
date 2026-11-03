{{--
  Il mastrino di un conto contabile — la stampa (docs/registri_contabili.md, D21.6).

  Stessa carta intestata e stesso sistema tipografico del registro di contabilità e dello Stato
  patrimoniale (§7-quinquies): non estende `pdf.base`, per non far convivere due identità sullo
  stesso foglio. Le misure su mPDF (grid → table, letter-spacing sì, tabular-nums inutile perché
  Fraunces ha già cifre tabulari, tabella a blocchi da 300 righe) sono quelle già fatte per il
  registro: vedi il blocco in testa a registro_contabilita.blade.php.

  Un mastrino ha la forma classica: riporto, righe con dare e avere, saldo progressivo nel verso
  naturale del conto, totali a doppio filetto. Il saldo NON si somma in fondo: è dichiarato una
  volta, con la sua data, nella striscia dei dati — stessa scelta del registro.
--}}
@php
    $filtriAttivi = [];
    if ($filtri['data_da'] && $filtri['data_a']) {
        $filtriAttivi[] = 'periodo dal '.$filtri['data_da'].' al '.$filtri['data_a'];
    } elseif ($filtri['data_da']) {
        $filtriAttivi[] = 'periodo dal '.$filtri['data_da'].' alla fine del periodo';
    } elseif ($filtri['data_a']) {
        $filtriAttivi[] = 'periodo dall\'inizio del periodo al '.$filtri['data_a'];
    }
    if ($filtri['search']) { $filtriAttivi[] = 'ricerca: «'.$filtri['search'].'»'; }
    $parziale = count($filtriAttivi) > 0;
    $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
    $dal = \Carbon\Carbon::parse($periodo['dal'])->format('d/m/Y');
    $al = \Carbon\Carbon::parse($periodo['al'])->format('d/m/Y');
    // La data del riporto la dà il servizio: il giorno prima del periodo, oppure oggi per un
    // esercizio non ancora cominciato (dove il riporto è la fotografia a oggi).
    $giornoPrima = \Carbon\Carbon::parse($riporto_al)->format('d/m/Y');
    $futuro = $periodo['stato'] === 'futuro';
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Mastrino {{ $conto['codice'] }} {{ $conto['nome'] }} – {{ $esercizio->nome }}</title>
<style>
    body { font-family: inter; font-size: 7pt; color: #26313F; }

    .fascia { background: #FAF4E2; border: 1px solid #E0D19C; color: #7A5A10;
              font-size: 7.5pt; padding: 6px 9px; margin-bottom: 12px; }
    .fascia b { letter-spacing: 1pt; text-transform: uppercase; }

    .testata { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0E2238; }
    .testata td { padding: 0 0 10px 0; vertical-align: top; border: 0; }
    .ente { font-family: fraunces; font-size: 15pt; color: #0E2238; }
    .att { font-size: 7pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #5A6B7B; }
    .rif { font-size: 7.5pt; color: #5A6B7B; }
    .doc { text-align: right; font-size: 7.5pt; color: #5A6B7B; }
    .doc b { color: #0E2238; }

    h1 { font-family: fraunces; font-size: 17pt; color: #0E2238; margin: 14px 0 1px; padding: 0; }
    .sotto { font-family: fraunces; font-size: 10pt; color: #2E5C86; margin: 0 0 10px; }

    .dati { width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5;
            border-bottom: 1px solid #D9DEE5; margin-bottom: 14px; }
    .dati td { padding: 8px 10px 8px 0; border: 0; vertical-align: top; }
    .dati .et { font-size: 7pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #5A6B7B; }
    .dati .vl { font-size: 9.5pt; color: #0E2238; font-family: fraunces; }

    table.registro { width: 100%; border-collapse: collapse; }
    table.registro th { font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase;
                        color: #5A6B7B; font-weight: bold; text-align: left;
                        border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    table.registro td { padding: 3px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    .n { text-align: right; white-space: nowrap; font-family: fraunces; }
    th.n { font-family: inter; }
    .num { text-align: right; color: #5A6B7B; font-family: fraunces; white-space: nowrap; }
    .neg { color: #96302F; }
    .d { color: #5A6B7B; font-size: 6pt; }
    .badge { font-size: 5.8pt; font-weight: bold; letter-spacing: 0.5pt; text-transform: uppercase;
             padding: 1px 5px; background: #F4EAEA; color: #96302F; }
    .badge-es { background: #FAF4E2; color: #7A5A10; }
    tr.riporto td { background: #F4F5F7; font-style: italic; color: #5A6B7B; }

    table.registro tfoot td { border-top: 1.5px solid #0E2238; border-bottom: 2px double #0E2238;
                              font-weight: bold; color: #0E2238; padding: 7px; }

    .chiusura { margin-top: 4px; padding: 10px 12px; background: #F4F5F7;
                border-left: 3px solid #C9A227; font-size: 7.5pt; color: #26313F;
                margin-bottom: 14px; }
    .chiusura b { color: #0E2238; }
    .avviso { margin-top: 4px; padding: 8px 12px; background: #FAF4E2; border-left: 3px solid #C9A227;
              font-size: 7.5pt; color: #7A5A10; margin-bottom: 10px; }

    .vuoto { padding: 20px; text-align: center; color: #5A6B7B; font-style: italic; }
</style>
</head>
<body>

<htmlpageheader name="mastrinoTestatina">
    <table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 0 0 5px 0; font-size: 7.5pt; color: #5A6B7B;">
                <b style="color: #0E2238;">{{ $condominio->nome }}</b> · Mastrino {{ $conto['codice'] }} {{ $conto['nome'] }}
            </td>
            <td style="border: 0; padding: 0 0 5px 0; text-align: right; font-size: 7.5pt; color: #5A6B7B;">
                {{ $esercizio->nome }}@if($parziale) · estratto parziale @endif
            </td>
        </tr>
    </table>
</htmlpageheader>
<sethtmlpageheader name="mastrinoTestatina" page="ALL" value="on" />

<htmlpagefooter name="mastrinoPiede">
    <table style="width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 5px 0 0 0; font-size: 7pt; color: #5A6B7B;">
                Documento emesso il {{ ($data_emissione_stampe ?? now())->format('d/m/Y') }}
                @if(!empty($nota_legale_stampe))
                    <br>{!! nl2br(e($nota_legale_stampe)) !!}
                @endif
            </td>
            <td style="border: 0; padding: 5px 0 0 0; text-align: right; font-size: 7pt; color: #5A6B7B;">
                Pagina {PAGENO} di {nbpg}
            </td>
        </tr>
    </table>
</htmlpagefooter>
<sethtmlpagefooter name="mastrinoPiede" page="ALL" value="on" />

@if($parziale)
<div class="fascia">
    <b>Estratto parziale</b> · questo documento non contiene tutti i movimenti del conto nel periodo:
    sono stati applicati i filtri
    <b style="letter-spacing: 0; text-transform: none;">{{ implode(' · ', $filtriAttivi) }}</b>.
    I totali in fondo si riferiscono ai soli movimenti qui riportati; il riporto, il numero
    d'operazione e il saldo progressivo restano invece quelli del periodo intero.
</div>
@endif

<table class="testata">
    <tr>
        <td>
            <div class="ente">{{ $condominio->nome }}</div>
            <div class="att">Mastrino del conto</div>
            <div class="rif">
                {{ $condominio->indirizzo }}@if($condominio->codice_fiscale)<br>C.F. {{ $condominio->codice_fiscale }}@endif
            </div>
        </td>
        <td class="doc">
            <b>{{ $esercizio->nome }}</b><br>
            @if($futuro)comincia il <b>{{ $dal }}</b>, fotografia al <b>{{ $al }}</b>@else dal <b>{{ $dal }}</b> al <b>{{ $al }}</b>@endif<br>
            Stampato il <b>{{ now()->format('d/m/Y') }}</b>
        </td>
    </tr>
</table>

@include('pdf.gestionale._mastrino_conto', ['con_nota' => true])

</body>
</html>
