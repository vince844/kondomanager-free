{{--
  Il libro mastro dell'esercizio — tutti i mastrini in un foglio solo (D21.6, chiesto da Vincenzo a
  video: «se volessi stamparli tutti in una sola volta?»). Ogni conto comincia su una pagina nuova
  e usa lo stesso blocco del mastrino singolo (_mastrino_conto.blade.php); la nota «Come leggere»
  sta una volta sola, in testa.

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
    $parziale = false;
    $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
    $dal = \Carbon\Carbon::parse($periodo['dal'])->format('d/m/Y');
    $al = \Carbon\Carbon::parse($periodo['al'])->format('d/m/Y');
    $futuro = $periodo['stato'] === 'futuro';
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Libro mastro – {{ $esercizio->nome }}</title>
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

<table class="testata">
    <tr>
        <td>
            <div class="ente">{{ $condominio->nome }}</div>
            <div class="att">Libro mastro</div>
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

<h1>Libro mastro</h1>
<p class="sotto">I mastrini di tutti i conti movimentati — {{ count($mastrini) }} {{ count($mastrini) === 1 ? 'conto' : 'conti' }}, @if($futuro)fotografia al {{ $al }}: l'esercizio comincia il {{ $dal }} @else dal {{ $dal }} al {{ $al }} @endif</p>

<div class="chiusura">
    <b>Come leggere questo libro mastro.</b>
    Un conto per sezione, nell'ordine del piano dei conti: ci sono i conti con almeno una riga nel
    periodo o con un riporto diverso da zero. Ogni sezione parte dal <b>riporto</b> — quanto c'era
    sul conto il giorno prima del periodo — e l'ultimo saldo progressivo di ogni conto è quello della
    situazione patrimoniale al {{ $al }}. Il saldo è nel verso naturale di ciascun conto: dare meno
    avere per attività e costi, avere meno dare per passività e ricavi; un importo negativo è contro
    natura. <b>Le operazioni stornate restano</b>, marcate come tali (art. 2219 c.c.).
</div>

<table class="dati">
    <tr>
        <td style="width: 34%;">
            <div class="et">Conti</div>
            <div class="vl">{{ count($mastrini) }}</div>
        </td>
        <td style="width: 33%;">
            <div class="et">Movimenti</div>
            <div class="vl">{{ number_format($righe_totali, 0, ',', '.') }}</div>
        </td>
        <td style="width: 33%;">
            <div class="et">Indice</div>
            <div style="font-size: 7pt; color: #26313F;">
                @foreach($mastrini as $m){{ $m['conto']['codice'] }} {{ $m['conto']['nome'] }}@if(! $loop->last) · @endif @endforeach
            </div>
        </td>
    </tr>
</table>

@foreach($mastrini as $m)
{{-- Il primo conto segue la copertina sulla stessa pagina; dal secondo in poi uno per pagina. --}}
@if(! $loop->first)<pagebreak />@endif
@include('pdf.gestionale._mastrino_conto', [
    'con_nota' => false,
    'conto' => $m['conto'],
    'periodo' => $m['periodo'],
    'righe' => $m['righe'],
    'riporto' => $m['riporto'],
    'riporto_al' => $m['riporto_al'],
    'totale_righe' => $m['totale_righe'],
    'totale_dare' => $m['totale_dare'],
    'totale_avere' => $m['totale_avere'],
    'saldo_finale' => $m['saldo_finale'],
    'saldo_alla_data' => $m['saldo_alla_data'],
    'data_riferimento' => $m['data_riferimento'],
    'altri_esercizi' => $m['altri_esercizi'],
    'riporto_proprie' => $m['riporto_proprie'],
    'postdatate' => $m['postdatate'],
    'apertura_non_registrata' => $m['apertura_non_registrata'],
    'parziale' => false,
])
@endforeach

@if(count($mastrini) === 0)
<table class="registro">
    <tr><td class="vuoto">Nessun conto movimentato nel periodo.</td></tr>
</table>
@endif

</body>
</html>
