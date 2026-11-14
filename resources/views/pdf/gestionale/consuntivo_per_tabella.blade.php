{{--
  Il consuntivo per tabella: la gemella della stampa del preventivo per tabella (`riparto_tabelle`), con la spesa dell'esercizio al posto del
  preventivo. Per ogni gestione, una riga per unità e soggetto, e per ogni tabella i millesimi dell'unità e la parte della spesa; in fondo il già
  versato tolto (una colonna sua, come nel preventivo) e il totale, che è la colonna «Spese dell'esercizio» del conguaglio. Dall'array di
  `ConguaglioDelRendiconto::perTabella()`.

  La carta del riepilogo finanziario (§ 7-quinquies di docs/registri_contabili.md: Inter e Fraunces, la palette del fac-simile), in orizzontale: la
  testata dello studio o del condominio, la testatina dalla seconda pagina, il piede con il nome della parte nel fascicolo. Niente firma: è una
  parte del fascicolo del rendiconto.

  Le tabelle in blocchi bilanciati fino a otto, come la gemella; le righe a blocchi di 100, una tabella per blocco (mPDF tiene in memoria la tabella
  intera). Le ultime due righe, i totali e le note della gestione in una tabella che non si spezza: i totali non vanno mai da soli su una pagina, e le
  note restano accanto ai totali che spiegano.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    use App\Services\Gestionale\ConguaglioDelRendiconto as C;
    use App\Services\Gestionale\ParoleDelConguaglio as P;
    $euro =fn (?int $c) => $c === null ? '—' : '€ ' . number_format($c / 100, 2, ',', '.');
    $cella = fn (?int $c) => $c === null || $c === 0 ? '—' : $euro($c);
    $mill = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.');
    $k = $consuntivo;
    $titoloStampa = 'Consuntivo per tabella';
    $sezione = 0;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $titoloStampa }} – {{ $esercizio->nome }}</title>
@include('pdf.gestionale._rendiconto_stile')
<style>
    h2 .blocco { font-family: inter; font-size: 7pt; color: #5A6B7B; }
    table.p { line-height: 1.15; }
    table.p th { font-size: 5.8pt; letter-spacing: 0.4pt; text-align: right; padding: 3px; }
    table.p th.s { text-align: left; }
    table.p th.centro { text-align: center; }
    table.p th.tab { text-align: center; color: #14304C; border-bottom: 1px solid #D9DEE5; border-left: 1px solid #D9DEE5; }
    table.p th.ml { border-left: 1px solid #D9DEE5; }
    table.p td { padding: 2px 3px; }
    table.p td.ml { border-left: 1px solid #D9DEE5; }
    table.p td.mill { color: #5A6B7B; }
    table.p tr.tot td { padding: 4px 3px; }
    table.p td.nota { border: 0; padding: 5px 0 0 0; font-size: 7.5pt; color: #5A6B7B; }
    .d { font-size: inherit; }
    .nota-gestione { font-size: 7.5pt; color: #26313F; margin: 0 0 6px; }
</style>
</head>
<body>

@include('pdf.gestionale._rendiconto_testa', ['alla' => $k['data']['alla'] ?? (string) $esercizio->data_fine])

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">{{ $k['data']['frase'] }}</p>

<div class="chiusura">
    <b>Come leggere questo prospetto.</b> Le spese dell'esercizio, divise come il preventivo: per ogni tabella i millesimi dell'unità e la parte della spesa.
    {{ C::FRASE_COMPETENZA }} {{ C::FRASE_GIORNI }} {{ C::FRASE_CENTESIMI }} Il totale di ogni riga è la colonna «Spese dell'esercizio» del conguaglio per persona.
    Ruolo: P proprietario, NP nudo proprietario, U usufruttuario, I inquilino.
</div>

@foreach($k['gestioni'] as $gestione)
@php
    $sezione++;
    $tabelle = $gestione['tabelle'];
    $nTab = count($tabelle);
    // Blocchi da otto al massimo, bilanciati (dieci = 5 + 5), come la gemella.
    $nBlocchi = max(1, (int) ceil($nTab / 8));
    while ($nBlocchi < $nTab && $nTab - ($nBlocchi - 1) * (int) ceil($nTab / $nBlocchi) > 6) {
        $nBlocchi++;
    }
    $perBlocco = $nTab > 0 ? (int) ceil($nTab / $nBlocchi) : 8;
    $font = $perBlocco > 6 ? '6.5pt' : '7pt';
    $chunksTabelle = $nTab > 0 ? array_chunk($tabelle, $perBlocco, true) : [[]];
    $conVersato = $gestione['gia_versato'] !== 0;
    // Le note della gestione, accanto ai totali che spiegano.
    $note = array_values(array_filter([
        $gestione['non_attribuite'] !== 0 ? 'Non attribuite a nessuno: '.$euro($gestione['non_attribuite']).' (le uscite che nessuno paga, con il perché, sono nel conguaglio per persona).' : null,
        ($gestione['a_non_disponibili'] ?? 0) !== 0 ? 'Nel totale, di cui nelle righe del conguaglio non disponibili: '.$euro($gestione['a_non_disponibili']).'. Il conguaglio per persona non le conta nei suoi totali.' : null,
    ]));
@endphp
@foreach($chunksTabelle as $iBlocco => $tabelleBlocco)
    @php
        $ultimoBlocco = $loop->last;
        $nTabB = count($tabelleBlocco);
        // Nell'ultimo blocco ci sono anche il già versato e il totale: l'unità e il soggetto cedono spazio alle tabelle, e i millesimi e gli euro dei totali
        // restano su una riga («1.000,00», «€ 296.296,20»).
        [$wUnita, $wNome, $wRuolo] = $ultimoBlocco ? [8, 13, 4] : [10, 17, 4];
        $wFisse = $wUnita + $wNome + $wRuolo + ($ultimoBlocco ? ($conVersato ? 7 : 0) + 8 : 0);
        $wTab = $nTabB > 0 ? (100 - $wFisse) / $nTabB : 50;
        $etichettaQuota = $wTab < 13 ? 'Mill.' : 'Millesimi';
        $colonne = 3 + 2 * $nTabB + ($ultimoBlocco ? 1 + ($conVersato ? 1 : 0) : 0);
        // Le larghezze di ogni colonna, per la riga senza altezza della tabella in fondo.
        $larghezze = [$wUnita, $wNome, $wRuolo];
        foreach ($tabelleBlocco as $t) {
            $larghezze[] = $wTab * 0.4;
            $larghezze[] = $wTab * 0.6;
        }
        if ($ultimoBlocco) {
            if ($conVersato) {
                $larghezze[] = 7;
            }
            $larghezze[] = 8;
        }
        $righeBlocchi = $gestione['righe'] === [] ? [[]] : array_chunk($gestione['righe'], 100);
        // Le ultime due righe e i totali stanno insieme in una tabella che non si spezza.
        $ultime = array_pop($righeBlocchi);
        $coda = array_slice($ultime, -2);
        $righeBlocchi[] = array_slice($ultime, 0, max(0, count($ultime) - 2));
        $righeBlocchi = array_values(array_filter($righeBlocchi, fn ($b) => $b !== []));
    @endphp
    <h2><span class="sec">§ {{ $sezione }}</span> {{ $gestione['nome'] }}@if(count($chunksTabelle) > 1) <span class="blocco">· tabelle, blocco {{ $iBlocco + 1 }} di {{ count($chunksTabelle) }}</span>@endif</h2>
    {{-- Il perché del motore è per l'amministratore, con l'istruzione, e sta nella scheda: qui, come nel conguaglio per persona, la frase per chi legge. --}}
    @if($iBlocco === 0 && $gestione['non_calcolabile'])
    <p class="nota-gestione">{{ P::GESTIONE_NON_CALCOLATA }}</p>
    @endif
    @foreach($righeBlocchi as $righe)
    <table class="p" style="font-size: {{ $font }};">
        <thead>@include('pdf.gestionale._consuntivo_per_tabella_testa')</thead>
        <tbody>
            @foreach($righe as $r)
                @include('pdf.gestionale._consuntivo_per_tabella_riga', ['r' => $r])
            @endforeach
        </tbody>
    </table>
    @endforeach
    <table class="p" style="font-size: {{ $font }}; page-break-inside: avoid;" autosize="1">
        @if($righeBlocchi === [])
        <thead>@include('pdf.gestionale._consuntivo_per_tabella_testa')</thead>
        @endif
        <tbody>
            {{-- Le larghezze delle colonne, come nella tabella sopra: una riga senza altezza. --}}
            <tr>@foreach($larghezze as $w)<td class="larga" style="width: {{ $w }}%;"></td>@endforeach</tr>
            @foreach($coda as $r)
                @include('pdf.gestionale._consuntivo_per_tabella_riga', ['r' => $r])
            @endforeach
            @if($gestione['righe'] === [])
                <tr><td colspan="{{ $colonne }}" class="d" style="text-align: center; padding: 8px;">Nessuna spesa divisa in questa gestione.</td></tr>
            @endif
            <tr class="tot">
                <td colspan="3">{{ $ultimoBlocco ? 'Totali' : 'Totali delle colonne di questo blocco' }}</td>
                @foreach($tabelleBlocco as $tid => $t)
                    <td class="n ml">{{ $mill($t['somma_valori']) }}</td>
                    <td class="n">{{ $euro($gestione['totali'][$tid] ?? 0) }}</td>
                @endforeach
                @if($ultimoBlocco)
                    @if($conVersato)<td class="n ml">{{ $euro($gestione['gia_versato']) }}</td>@endif
                    <td class="n ml">{{ $euro($gestione['totale']) }}</td>
                @endif
            </tr>
            @if($ultimoBlocco)
                @foreach($note as $nota)
                    <tr><td colspan="{{ $colonne }}" class="nota">{{ $nota }}</td></tr>
                @endforeach
            @endif
        </tbody>
    </table>
@endforeach
@endforeach

</body>
</html>
