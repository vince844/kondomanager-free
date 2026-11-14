{{--
  Il conguaglio per persona, la stampa: una riga per persona e unità di ogni gestione, dall'array di `ConguaglioDelRendiconto::righe()` (per la stampa:
  senza le note dei passaggi né gli avvisi, che sono per l'amministratore). Nove colonne, A4 orizzontale.

  Va ai condòmini con il fascicolo: le parole sono le loro (`ParoleDelConguaglio`). Quello che è già da versare (le rate già chieste e non pagate, e le
  altre somme fuori dalle rate) e la proposta di conguaglio, che è solo proposta, stanno in due colonne distinte; il totale è il saldo se l'assemblea approva.

  La carta del riepilogo finanziario (§ 7-quinquies di docs/registri_contabili.md: Inter e Fraunces, la palette del fac-simile), in orizzontale: la
  testata dello studio o del condominio, la testatina dalla seconda pagina, il piede con il nome della parte nel fascicolo. Niente firma: è una
  parte del fascicolo del rendiconto, una lettura dei conti e non un atto.

  Le righe a blocchi di 100, una tabella per blocco, come il dettaglio del riepilogo: mPDF tiene in memoria la tabella intera (le misure accanto al
  tetto, in `RendicontoConguaglioController`). Le ultime due righe e il totale in una tabella che non si spezza: il totale non va mai da solo su una
  pagina.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    use App\Services\Gestionale\ConguaglioDelRendiconto as C;
    use App\Services\Gestionale\ParoleDelConguaglio as P;
    // Il meno come nel resto del fascicolo: l'euro prima, «€ -50,00»; il più sulle cifre a debito delle colonne della proposta e del saldo, dopo il
    // simbolo come il meno: «€ +100,00». Una cifra che ancora non c'è è «non ancora calcolato».
    $euro = fn (?int $c) => $c === null ? P::NON_CALCOLATO : '€ ' . number_format($c / 100, 2, ',', '.');
    $segno = fn (?int $c) => $c === null ? P::NON_CALCOLATO : '€ ' . ($c > 0 ? '+' : '') . number_format($c / 100, 2, ',', '.');
    $cella = fn (?int $c) => $c === null ? P::NON_CALCOLATO : ($c === 0 ? '' : $euro($c));
    // Nelle note sotto una cifra l'euro e il numero non vanno a capo fra loro: lo spazio fra i due è uno spazio che non si spezza.
    $euroNota = fn (int $c) => '€'."\u{00A0}".number_format($c / 100, 2, ',', '.');
    $segnoNota = fn (int $c) => '€'."\u{00A0}".($c > 0 ? '+' : '').number_format($c / 100, 2, ',', '.');
    // Accanto al saldo, le rate dei piani non ancora chieste: quanto diventa il saldo quando saranno chieste.
    $quandoChieste = fn (?int $saldo, ?int $nonEmesse) => (int) $nonEmesse === 0 || $saldo === null ? '' : 'quando saranno chieste le altre rate: '.$segnoNota($saldo + (int) $nonEmesse);
    // Già da versare: le rate già chieste e non pagate, e le altre somme fuori dalle rate.
    $gia = fn (array $r, string $fuori = 'fuori') => ($r['posizione'] ?? null) === null && ($r[$fuori] ?? null) === null ? null : (int) ($r['posizione'] ?? 0) + (int) ($r[$fuori] ?? 0);
    $g = $conguaglio;
    $titoloStampa = 'Conguaglio per persona';
    $colonne = [
        ['unita', 'Unità', 'left', 10], ['nome', 'Soggetto', 'left', 17], ['giorni', 'Giorni', 'right', 5], ['a', 'Spese dell\'esercizio', 'right', 10],
        ['personali', 'Spese personali non chieste', 'right', 9], ['b', 'Quote dei piani', 'right', 11], ['conguaglio', 'Proposta di conguaglio, da approvare', 'right', 13],
        ['gia', 'Già da versare', 'right', 11], ['saldo', 'Saldo se l\'assemblea approva', 'right', 14],
    ];
    $sezioni = [];
    foreach ($g['gestioni'] as $gestione) {
        $sezioni[] = ['titolo' => $gestione['nome'], 'righe' => $gestione['righe'], 'totali' => $gestione['totali'], 'non_calcolabile' => $gestione['non_calcolabile'],
            // L'opera in corso non propone: la frase dice lo speso e il chiesto; l'opera finita su più esercizi, con quali.
            'nota' => $gestione['in_corso']['frase'] ?? ($gestione['opera_finita'] ?? null)];
    }
    if ($g['da_altre_gestioni']['righe'] !== []) {
        $t = $g['da_altre_gestioni']['totali'];
        $sezioni[] = ['titolo' => $g['da_altre_gestioni']['nome'], 'righe' => $g['da_altre_gestioni']['righe'], 'non_calcolabile' => null,
            'totali' => ['a' => 0, 'personali' => $t['personali'], 'b' => 0, 'conguaglio' => $t['conguaglio'], 'posizione' => $t['posizione'], 'fuori_dalle_rate' => 0, 'saldo' => $t['saldo'], 'non_emesse' => 0]];
    }
    $nonAttribuite = [];
    foreach ($g['gestioni'] as $gestione) {
        foreach ($gestione['non_attribuite'] as $n) {
            $nonAttribuite[] = $n + ['gestione' => $gestione['nome']];
        }
    }
    // Le spese non ancora divise fra i condòmini: le cifre di questa stampa non le comprendono, e quando saranno divise il conguaglio può cambiare.
    $fuoriPerChiave = [];
    foreach ($g['fuori_dai_capitoli'] as $f) {
        $fuoriPerChiave[P::chiaveFuoriDaiCapitoli($f['nome']) ?? $f['nome']] = (int) $f['importo'];
    }
    $nonDivise = P::nonDivise((int) array_sum(array_column($nonAttribuite, 'importo')), $fuoriPerChiave);
    // Le righe con la proposta non ancora calcolata, che i totali non contano.
    $nonPronte = 0;
    foreach ($sezioni as $s) {
        foreach ($s['righe'] as $r) {
            $nonPronte += ! $r['subtotale'] && $r['conguaglio'] === null ? 1 : 0;
        }
    }
    $sezione = 0;
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $titoloStampa }} – {{ $esercizio->nome }}</title>
@include('pdf.gestionale._rendiconto_stile')
<style>
    .proposta { font-size: 8pt; font-weight: bold; color: #0E2238; margin: -6px 0 8px; }
    table.p { font-size: 7pt; }
    {{-- Le intestazioni stanno nelle loro colonne («DELL'ESERCIZIO» in una): una parola più larga farebbe rimpicciolire a mPDF la tabella con
         l'intestazione, e non la tabella in fondo, che non la ha. --}}
    table.p th { font-size: 5.8pt; letter-spacing: 0; padding: 4px; }
    table.p td { padding: 3px 4px; }
    table.p tr.sub td { background: #F4F5F7; font-weight: bold; color: #0E2238; }
    table.p tr.tot td { padding: 5px 4px; }
    .d { font-size: 6.8pt; }
    .nota { font-size: 7.5pt; color: #26313F; margin: 0 0 6px; }
</style>
</head>
<body>

@include('pdf.gestionale._rendiconto_testa', ['alla' => $g['data']['alla'] ?? (string) $esercizio->data_fine])

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">{{ $g['data']['frase'] }}</p>
<p class="proposta">{{ P::PROPOSTA }}</p>

<div class="chiusura">
    <b>Come leggere questo prospetto.</b> {{ C::FRASE_SEGNO }}
    Proposta di conguaglio = spese dell'esercizio + spese personali non ancora chieste − quote dei piani. Già da versare: le rate già chieste e non pagate,
    e le altre somme fuori dalle rate. Saldo se l'assemblea approva = già da versare + proposta di conguaglio.
    Le quote dei piani comprendono anche le rate non ancora chieste: sotto la cifra c'è quanto ne manca, e sotto il saldo quanto diventa quando saranno chieste.
    {{ C::FRASE_COMPETENZA }} {{ C::FRASE_GIORNI }} {{ C::FRASE_CENTESIMI }}
    Una riga per persona e unità, in ordine di nome (nel consuntivo per tabella l'ordine è quello delle unità): chi ha più unità ha anche la riga del suo
    totale. Una cella vuota vuol dire zero. «Non ancora calcolato»: l'amministratore completerà la cifra di quella riga.
</div>

@foreach($sezioni as $s)
@php
    $sezione++;
    $blocchi = $s['righe'] === [] ? [[]] : array_chunk($s['righe'], 100);
    // Le ultime due righe e il totale stanno insieme in una tabella che non si spezza: il totale non va mai da solo su una pagina.
    $ultimo = array_pop($blocchi);
    $coda = array_slice($ultimo, -2);
    $blocchi[] = array_slice($ultimo, 0, max(0, count($ultimo) - 2));
    $blocchi = array_values(array_filter($blocchi, fn ($b) => $b !== []));
@endphp
<h2><span class="sec">§ {{ $sezione }}</span> {{ $s['titolo'] }}</h2>
@if($s['non_calcolabile'])
<p class="nota">{{ P::GESTIONE_NON_CALCOLATA }}</p>
@endif
@if(! empty($s['nota']))
<p class="nota">{{ $s['nota'] }}</p>
@endif
@foreach($blocchi as $blocco)
<table class="p">
    <thead>
        <tr>
            @foreach($colonne as [$chiave, $etichetta, $allinea, $larghezza])
                <th class="{{ $allinea === 'right' ? 'n' : '' }}" style="width: {{ $larghezza }}%;">{{ $etichetta }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($blocco as $r)
            @include('pdf.gestionale._conguaglio_per_persona_riga', ['r' => $r])
        @endforeach
    </tbody>
</table>
@endforeach
<table class="p" style="page-break-inside: avoid;" autosize="1">
    @if($blocchi === [])
    <thead>
        <tr>
            @foreach($colonne as [$chiave, $etichetta, $allinea, $larghezza])
                <th class="{{ $allinea === 'right' ? 'n' : '' }}" style="width: {{ $larghezza }}%;">{{ $etichetta }}</th>
            @endforeach
        </tr>
    </thead>
    @endif
    <tbody>
        {{-- Le larghezze delle colonne, come nella tabella sopra: una riga senza altezza. --}}
        <tr>@foreach($colonne as [$chiave, $etichetta, $allinea, $larghezza])<td class="larga" style="width: {{ $larghezza }}%;"></td>@endforeach</tr>
        @foreach($coda as $r)
            @include('pdf.gestionale._conguaglio_per_persona_riga', ['r' => $r])
        @endforeach
        @if($s['righe'] === [])
            <tr><td colspan="9" class="d" style="padding: 8px; text-align: center;">Nessuna persona con una cifra in questa gestione.</td></tr>
        @endif
        <tr class="tot">
            @foreach($colonne as [$chiave, $etichetta, $allinea, $larghezza])
                @if($loop->index === 0)<td>Totale</td>@elseif($loop->index < 3)<td></td>@elseif($chiave === 'saldo')<td class="nn">{{ $segno($s['totali']['saldo']) }} @if($quandoChieste($s['totali']['saldo'], $s['totali']['non_emesse'] ?? 0) !== '') <br><span class="d">{{ $quandoChieste($s['totali']['saldo'], $s['totali']['non_emesse']) }}</span> @endif</td>@elseif($chiave === 'gia')<td class="n">{{ $euro($gia($s['totali'], 'fuori_dalle_rate')) }}</td>@else<td class="n">{{ $chiave === 'conguaglio' ? $segno($s['totali'][$chiave]) : $euro($s['totali'][$chiave]) }}</td>@endif
            @endforeach
        </tr>
    </tbody>
</table>
@endforeach

@if($nonDivise['importo'] !== 0)
<p class="eq">Queste cifre non comprendono {{ $euro($nonDivise['importo']) }} di spese non ancora divise fra i condòmini ({{ implode(', ', $nonDivise['cosa']) }}): quando saranno divise, il conguaglio può cambiare.</p>
@endif

{{-- Le spese che il conguaglio non divide ancora, con il perché per chi legge: la causa, non che cosa deve fare l'amministratore (quello lo dice la scheda). --}}
@if($nonAttribuite !== [] || $g['fuori_dai_capitoli'] !== [])
@php $sezione++; @endphp
<h2><span class="sec">§ {{ $sezione }}</span> Uscite non divise fra i condòmini (per ora)</h2>
<table class="p">
    <thead><tr><th style="width: 30%;">Uscita</th><th class="n" style="width: 12%;">Importo</th><th>Perché non è ancora divisa</th></tr></thead>
    <tbody>
        @foreach($nonAttribuite as $n)
            <tr><td>{{ $n['voce'] }} <span class="d">({{ $n['gestione'] }})</span></td><td class="n">{{ $euro($n['importo']) }}</td><td>{{ P::perche($n['motivo']) }}</td></tr>
        @endforeach
        @foreach($g['fuori_dai_capitoli'] as $f)
            @php [$nomeUscita, $perche] = P::fuoriDaiCapitoli($f['nome']); @endphp
            <tr><td>{{ $nomeUscita }}</td><td class="n">{{ $euro($f['importo']) }}</td><td>{{ $perche }}</td></tr>
        @endforeach
    </tbody>
</table>
@endif

@php $t = $g['condominio']; $sezione++; @endphp
{{-- Una riga sola, con il titolo e le intestazioni in righe del corpo (non in un'intestazione da ripetere), in una tabella che non si spezza; con le
     frasi che la seguono in un blocco solo: l'ultima frase non va da sola sulla pagina dopo. --}}
<div style="page-break-inside: avoid;">
<table class="p" style="page-break-inside: avoid;" autosize="1">
    <tbody>
        <tr><td class="titolo" colspan="7"><span class="sec-cella">§ {{ $sezione }}</span> Il condominio</td></tr>
        <tr>
            <th style="width: 32%;"></th><th class="n" style="width: 10%;">Spese dell'esercizio</th><th class="n" style="width: 9%;">Spese personali non chieste</th>
            <th class="n" style="width: 11%;">Quote dei piani</th><th class="n" style="width: 13%;">Proposta di conguaglio, da approvare</th><th class="n" style="width: 11%;">Già da versare</th>
            <th class="n" style="width: 14%;">Saldo se l'assemblea approva</th>
        </tr>
        <tr class="tot">
            <td>Totale del condominio</td>
            <td class="n">{{ $euro($t['a']) }}</td><td class="n">{{ $euro($t['personali']) }}</td><td class="n">{{ $euro($t['b']) }}</td>
            <td class="n">{{ $segno($t['conguaglio']) }}</td><td class="n">{{ $euro($gia($t, 'fuori_dalle_rate')) }}</td>
            <td class="nn">{{ $segno($t['saldo']) }} @if($quandoChieste($t['saldo'], $t['non_emesse'] ?? 0) !== '') <br><span class="d">{{ $quandoChieste($t['saldo'], $t['non_emesse']) }}</span> @endif</td>
        </tr>
    </tbody>
</table>
@if($nonPronte !== 0)
<p class="eq">Le righe con la proposta non ancora calcolata ({{ $nonPronte }}@if((int) ($t['a_non_disponibili'] ?? 0) !== 0), spese dell'esercizio {{ $euro((int) $t['a_non_disponibili']) }}@endif) non sono nei totali: l'amministratore le completerà.</p>
@endif
{{-- Il secondo momento, in due frasi per chi legge: quanto avranno da versare i condòmini e quanto restituirà il condominio, se l'assemblea approva. --}}
@if(! empty($g['secondo_momento']))
<p class="eq">Se l'assemblea approva, i condòmini avranno da versare {{ $euro($g['secondo_momento']['crediti_dopo']) }}, tra le rate già chieste e i conguagli a debito.
    Il condominio riconoscerà {{ $euro($g['secondo_momento']['a_credito']) }} di conguagli a credito: a chi ha anche rate da versare si tolgono da quelle.@if($nonPronte !== 0) Le righe con la proposta non ancora calcolata non sono contate.@endif</p>
@endif
</div>

</body>
</html>
