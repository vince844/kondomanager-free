{{--
  I crediti per anzianità (§ 10 del rendiconto): una riga per rata emessa e non pagata alla data, dall'array di `PosizioniDeiCondomini::crediti()`,
  lo stesso della pagina.

  La carta del riepilogo finanziario (§ 7-quinquies di docs/registri_contabili.md: Inter e Fraunces, la palette del fac-simile), in orizzontale, con
  lo stile e la testa comuni delle stampe del rendiconto (`_rendiconto_stile`, `_rendiconto_testa`). Niente firma: è una parte del fascicolo.

  La tabella a blocchi di 100 righe con le celle a classi, come «Rate scadute» (le misure accanto al tetto, in `RendicontoCondominiController`).

  La stampa va ai condòmini: una colonna sola per l'esercizio (quello del piano; la scadenza accanto solo se cade in un altro), senza la nota di legge
  sulle due annate, che resta nella pagina dell'amministratore. La colonna dell'ultima lettera di sollecito c'è se `senza_lettere` non è vero: nel
  fascicolo la sceglie l'amministratore, e di serie non c'è; la stampa da sola la ha.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    // Il meno come nel resto del fascicolo: l'euro prima, «€ -50,00».
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $giorno = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : '';
    $c = $crediti;
    $conLettere = ! ($senza_lettere ?? false);
    $titoloStampa = 'Crediti per anzianità';
    $fasce = ['1_30' => '1–30 giorni', '31_90' => '31–90 giorni', 'oltre_90' => 'oltre 90 giorni'];
    // Le colonne delle cifre larghe quanto un totale a otto cifre in grassetto («€ 64.495.000,00»), che altrimenti va a capo dentro il numero; lo spazio
    // viene dalla persona, dall'unità, dalla rata e dall'ultima lettera.
    $colonne = array_values(array_filter([
        ['Persona', 'left', $conLettere ? 14 : 17], ['Unità', 'left', 5], ['Rata', 'left', $conLettere ? 14 : 20], ['Importo', 'right', 10], ['Scadenza', 'left', 7], ['Ritardo', 'left', 12],
        ['Esercizio', 'left', 8],
        $c['finito'] ? ['Versato dopo', 'right', 9] : null, $c['finito'] ? ['Resta oggi', 'right', 10] : null, $conLettere ? ['Ultima lettera spedita', 'left', 10] : null,
    ]));
    $blocchi = count($c['righe']) === 0 ? [[]] : array_chunk($c['righe'], 100);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $titoloStampa }} – {{ $esercizio->nome }}</title>
@include('pdf.gestionale._rendiconto_stile')
<style>
    {{-- Le celle a classi, non a stili: mPDF tiene in memoria la tabella intera. --}}
    td.cn { text-align: right; white-space: nowrap; font-family: fraunces; }
</style>
</head>
<body>

@include('pdf.gestionale._rendiconto_testa', ['alla' => $c['alla']])

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">Le rate non pagate, per ritardo. {{ ucfirst($c['avvertenze']['data']) }}.</p>

{{-- In testa le cifre che stavano sotto il titolo: le rate non pagate, le scadute e le tre fasce. --}}
<table class="dati">
    <tr>
        <td style="width: 22%;">
            <div class="dato-et">Rate non pagate</div>
            <div class="dato-vl">{{ $euro($c['totali']['importo']) }}</div>
        </td>
        <td style="width: 22%;">
            <div class="dato-et">Di cui scadute</div>
            <div class="dato-vl">{{ $euro($c['totali']['scaduto']) }}</div>
        </td>
        @foreach($fasce as $chiave => $etichetta)
        <td style="width: 18%;">
            <div class="dato-et">{{ $etichetta }}</div>
            <div class="dato-vl">{{ $euro($c['totali']['fasce'][$chiave]) }}</div>
        </td>
        @endforeach
    </tr>
</table>

<div class="chiusura">
    <b>Come leggere questo prospetto.</b> {{ $c['avvertenze']['segno'] }} Le rate sono {{ $c['avvertenze']['data'] }}, una riga per rata emessa e non pagata a quella data. {{ $c['avvertenze']['ordine'] }}
    «Esercizio»: l'esercizio del piano a cui appartiene la rata; sotto, l'anno della scadenza quando è un altro.
    @if($c['avvertenze']['versato_dopo']) {{ $c['avvertenze']['versato_dopo'] }} @endif @if($conLettere) «Ultima lettera spedita»: l'ultima lettera di sollecito spedita a ogni persona, fino a oggi. @endif
    Un pregresso senza un numero di giorni dice la sua origine, e sta oltre i 90 giorni.
</div>

<h2><span class="sec">§ 1</span> Le rate non pagate</h2>
@foreach($blocchi as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table class="p">
    <thead>
        <tr>
            @foreach($colonne as [$etichetta, $allinea, $larghezza])
                <th class="{{ $allinea === 'right' ? 'n' : '' }}" style="width: {{ $larghezza }}%;">{{ $etichetta }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($blocco as $r)
            <tr>
                <td class="c">{{ $r['nome'] }}</td>
                <td class="c">{{ $r['unita'] ?? '—' }}</td>
                <td class="c">{{ $r['piano'] }}, rata {{ $r['numero_rata'] }} <span class="d">· {{ $r['gestione'] }}@if($r['pregresso_nella_rata']) · {{ \App\Services\Gestionale\PosizioniDeiCondomini::NOTA_PREGRESSO_NELLA_RATA }} @endif</span></td>
                <td class="cn">{{ $euro($r['importo']) }}</td>
                <td class="c">{{ $giorno($r['scadenza']) }}</td>
                <td class="c">{{ $r['origine'] !== null && $r['giorni'] === null ? $r['origine'] : ($r['scaduta'] ? $r['giorni'] . ' giorni, ' . $fasce[$r['fascia']] : 'non ancora scaduta') }}</td>
                <td class="c">{{ $r['annata_competenza'] }} @if($r['annata_scadenza'] !== $r['annata_competenza']) <br><span class="d">scadenza: {{ $r['annata_scadenza'] }}</span> @endif</td>
                @if($c['finito'])
                    <td class="cn">{{ $r['versato_dopo'] ? $euro($r['versato_dopo']) : '' }}</td>
                    <td class="cn">{{ $euro($r['resta_oggi']) }}</td>
                @endif
                @if($conLettere)
                    <td class="c">{{ $r['lettera'] ? $r['lettera']['tipo'] . ' del ' . $giorno($r['lettera']['data']) : '' }}</td>
                @endif
            </tr>
        @endforeach
        @if(count($c['righe']) === 0)
            <tr><td colspan="{{ count($colonne) }}" class="d" style="padding: 10px; text-align: center;">Nessuna rata non pagata a questa data.</td></tr>
        @endif
        @if($loop->last)
            <tr class="tot">
                <td colspan="3">Totale</td>
                <td class="n">{{ $euro($c['totali']['importo']) }}</td>
                <td colspan="3"></td>
                @if($c['finito'])
                    <td class="n">{{ $euro($c['totali']['versato_dopo']) }}</td>
                    <td class="n">{{ $euro($c['totali']['resta_oggi']) }}</td>
                @endif
                @if($conLettere)
                    <td></td>
                @endif
            </tr>
        @endif
    </tbody>
</table>
@endforeach

<p class="riservato">{{ $c['avvertenze']['riservato'] }}</p>

</body>
</html>
