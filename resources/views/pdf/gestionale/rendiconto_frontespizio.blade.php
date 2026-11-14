{{--
  Il frontespizio del fascicolo del rendiconto: l'intestazione dello studio, il condominio e l'esercizio, la data della stampa, i numeri del prospetto
  della gestione, le quote non pagate, i fondi, l'esito dei controlli con la riga fissa, l'indice delle parti con le pagine (e le parti saltate, con il
  perché) e lo spazio per la firma. Legge l'array della sintesi (`SintesiDelRendiconto`) e le registrazioni oltre il confine (`AnnoACavallo`, per dire che
  cosa contiene il risultato): nessuna somma qui. Il foglio va ai condòmini: le frasi della pagina che parlano all'amministratore passano da
  `ParoleDellaStampa`.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    $fmt = fn (?int $c) => $c === null ? 'non disponibile' : '€ '.number_format($c / 100, 2, ',', '.');
    // Il preventivo manca quando la gestione è già passata all'esercizio dopo: quello di quest'anno è nel verbale dell'assemblea.
    $preventivo = fn (?int $c) => $c === null ? 'nel verbale dell\'assemblea' : $fmt($c);
    $dataIt = fn (string $iso) => \Carbon\Carbon::parse($iso)->format('d/m/Y');
    $pr = $sintesi['prospetto'];
    $es = $sintesi['esito'];
    // Le parti con i dati di tutti i condòmini: le posizioni e i crediti per anzianità, il registro (chi ha versato, quanto, quando).
    $riservato = collect($indice)->contains(fn ($v) => in_array($v['parte'], ['condomini', 'registro'], true) && $v['pagine'] > 0);
    $paginaControlli = collect($indice)->firstWhere('parte', 'controlli')['pagina'] ?? null;
    // Le registrazioni oltre il confine: l'effetto sul riepilogo, e quindi sul risultato, nei due versi.
    $anno = $anno_a_cavallo ?? null;
    $effetti = $anno === null ? [] : array_filter(['A' => $anno['effetto']['A']['frase'] ?? null, 'B' => $anno['effetto']['B']['frase'] ?? null]);
    // Lo studio: a sinistra il logo e lo studio, a destra il condominio; senza il nome dello studio, la riga dello studio e il condominio di sempre.
    $conStudio = isset($carta) && $carta->haStudio();
    // Con la carta intestata lo studio è stampato sulla carta: in testa solo il condominio.
    $conCarta = isset($carta) && $carta->cartaIntestata() !== null;
    // I titoli, i loro filetti e la testata hanno il colore della carta dello studio; senza un colore, quello di sempre.
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#0E2238');
    // Le righe strette: il fascicolo riscrive così il frontespizio che con gli spazi di sempre non sta in una pagina (una carta intestata con il margine
    // alto lascia meno spazio). Le parole sono le stesse; gli spazi fra i blocchi e le righe delle tabelle più stretti.
    $stretto = ! empty($compatto);
    // Con le righe strette, i pezzi della stessa parte (le posizioni e i crediti per anzianità; le due tabelle del conguaglio) stanno in una riga sola
    // dell'indice, ognuno con la sua pagina: con tutte le parti l'indice e la firma restano nella prima pagina.
    $righeIndice = [];
    foreach ($indice as $voceIndice) {
        $ultima = count($righeIndice) - 1;
        if ($stretto && $ultima >= 0 && $righeIndice[$ultima]['parte'] === $voceIndice['parte']) {
            $righeIndice[$ultima]['pezzi'][] = $voceIndice;
        } else {
            $righeIndice[] = ['parte' => $voceIndice['parte'], 'pezzi' => [$voceIndice]];
        }
    }
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Rendiconto – {{ $esercizio->nome }}</title>
<style>
    body { font-family: inter; font-size: 8pt; color: #26313F; }
    .studio { font-size: 7.5pt; color: #5A6B7B; border-bottom: 1px solid #D9DEE5; padding-bottom: 6px; margin-bottom: 18px; }
    .studio b { color: {{ $coloreTitoli }}; }
    .ente { font-family: fraunces; font-size: 20pt; color: {{ $coloreTitoli }}; margin: 0; }
    .rif { font-size: 8pt; color: #5A6B7B; margin: 2px 0 {{ $stretto ? 10 : 16 }}px; }
    h1 { font-family: fraunces; font-size: 16pt; color: {{ $coloreTitoli }}; margin: 0 0 2px; }
    .sotto { font-family: fraunces; font-size: 10pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta ?? null, '#2E5C86') }}; margin: 0 0 {{ $stretto ? 8 : 14 }}px; }
    /* Righe strette: il frontespizio, con le righe che spiegano gli esiti e il prospetto, sta in una pagina con la firma. */
    h2 { font-family: fraunces; font-size: 11pt; color: {{ $coloreTitoli }}; margin: {{ $stretto ? 8 : 12 }}px 0 {{ $stretto ? 4 : 5 }}px; padding-bottom: 3px; border-bottom: 1px solid {{ $coloreTitoli }}; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p td { padding: {{ $stretto ? 1 : 2 }}px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    table.p th { font-size: 6.5pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; text-align: left; border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    /* «Pagina» sta sopra i numeri, che sono a destra: `table.p th` è più forte di `.n` e lo teneva a sinistra (Coda 567). */
    table.p th.n { text-align: right; }
    .n { text-align: right; white-space: nowrap; }
    .d { color: #5A6B7B; font-size: 7pt; }
    .esito { padding: {{ $stretto ? 6 : 9 }}px 12px; background: #F4F5F7; border-left: 3px solid #C9A227; }
    .esito b { color: #0E2238; }
    .firma { margin-top: {{ $stretto ? 8 : 18 }}px; width: 100%; }
    .firma td { border: 0; padding: 0; }
</style>
</head>
<body>

{{-- Il piede delle parti del fascicolo, con il nome «Frontespizio»: la data di emissione, la nota legale, il numero di pagina. --}}
<htmlpagefooter name="frPiede">
    @include('pdf._carta.piede_inter', ['giorno' => \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y'), 'parte' => 'Frontespizio'])
</htmlpagefooter>
<sethtmlpagefooter name="frPiede" page="ALL" value="on" />

@if($conStudio)
{{-- `overflow: wrap`: una parola più larga della colonna dello studio (una PEC, un sito) va a capo dove la colonna finisce. --}}
<table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #D9DEE5; margin-bottom: 16px; overflow: wrap;">
    <tr>
        <td style="width: 55%; vertical-align: top; padding: 0 4mm 6px 0; border: 0;">
            <table style="border-collapse: collapse; overflow: wrap;">
                <tr>
                    @if($carta->logo())
                        <td style="vertical-align: top; padding: 0 3mm 0 0; border: 0;"><img src="{{ $carta->logo() }}" style="max-height: 14mm; max-width: 40mm;"></td>
                    @endif
                    <td style="vertical-align: top; padding: 0; border: 0;">
                        <div style="font-family: fraunces; font-size: 11pt; color: {{ $coloreTitoli }};">{{ $carta->nome() }}</div>
                        @foreach($carta->righe() as $riga)
                            <div style="font-size: 7.5pt; color: #5A6B7B;">{{ $riga }}</div>
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 45%; vertical-align: top; text-align: right; padding: 0 0 6px 0; border: 0;">
            <div style="font-family: fraunces; font-size: 14pt; color: {{ $coloreTitoli }};">{{ $condominio->nome }}</div>
            @if($condominio->indirizzo)<div style="font-size: 8pt; color: #5A6B7B;">{{ $condominio->indirizzo }}</div>@endif
            @if($condominio->codice_fiscale)<div style="font-size: 8pt; color: #5A6B7B;">C.F. {{ $condominio->codice_fiscale }}</div>@endif
        </td>
    </tr>
</table>
@else
@unless($conCarta)
<div class="studio">
    @if($studio->studio_nome) <b>{{ $studio->studio_nome }}</b> @endif
    @if($studio->studio_indirizzo) · {{ $studio->studio_indirizzo }} @endif
    @if($studio->studio_comune) · {{ $studio->studio_comune }} @endif
    @if($studio->studio_telefono) · {{ $studio->studio_telefono }} @endif
    @if($studio->studio_email) · {{ $studio->studio_email }} @endif
    @if($studio->studio_pec) · PEC {{ $studio->studio_pec }} @endif
</div>
@endunless

<div class="ente">{{ $condominio->nome }}</div>
<div class="rif">
    @if($condominio->indirizzo) {{ $condominio->indirizzo }} @endif
    @if($condominio->codice_fiscale) · C.F. {{ $condominio->codice_fiscale }} @endif
</div>
@endif

<h1>Rendiconto dell'esercizio</h1>
<p class="sotto">{{ $esercizio->nome }}, dal {{ $dataIt($sintesi['esercizio']['dal']) }} al {{ $dataIt($sintesi['esercizio']['al']) }} · stampato il {{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</p>
@if($riservato)
<p class="esito" style="margin-bottom: 6px;"><b>{{ \App\Services\Gestionale\PosizioniDeiCondomini::RISERVATO }}</b></p>
@endif
<p class="d">{{ $sintesi['stato']['frase_stampa'] ?? $sintesi['stato']['frase'] }}</p>

<h2>I controlli</h2>
{{-- Per chi legge: quanti controlli tornano, chi spiega gli altri, che cosa non sono. Le righe, con le parole della contabilità, sono nella loro pagina. --}}
<div class="esito">
    <b>{{ \App\Services\Gestionale\SintesiDelRendiconto::controlliPerChiLegge($es) }}</b>
    @if($paginaControlli !== null)
    <br><span class="d">Le righe dei controlli, per chi vuole il dettaglio, sono a pagina {{ $paginaControlli }}.</span>
    @endif
</div>

<h2>Il prospetto della gestione</h2>
<table class="p">
    <tr><td>Preventivo{{ $pr['preventivo_solo_ordinarie'] ? ' delle gestioni ordinarie' : '' }}</td><td class="n">{{ $preventivo($pr['preventivo']) }}</td></tr>
    <tr><td>Deliberato con le rate</td><td class="n">{{ $fmt($pr['deliberato']) }}</td></tr>
    <tr><td>Quote dei piani dell'esercizio</td><td class="n">{{ $fmt($pr['quote_piani']) }}</td></tr>
    <tr><td>Quote pagate</td><td class="n">{{ $fmt($pr['quote_pagate']) }}</td></tr>
    <tr><td>Quote non pagate</td><td class="n">{{ $fmt($pr['quote_non_pagate']) }}</td></tr>
    <tr><td>Costi dell'esercizio</td><td class="n">{{ $fmt($pr['costi']) }}</td></tr>
    <tr><td><b>Risultato</b>: quote registrate in questo esercizio {{ $fmt($pr['quote_giornale']) }} − costi {{ $fmt($pr['costi']) }}</td><td class="n"><b>{{ $fmt($pr['risultato']) }}</b> ({{ \App\Services\Gestionale\RiepilogoPerCompetenza::comeRisultato($pr['risultato']) }})</td></tr>
    <tr><td>Liquidità secondo la contabilità, al {{ $dataIt($sintesi['riquadri']['liquidita']['alla']) }}</td><td class="n">{{ $fmt($sintesi['riquadri']['liquidita']['liquidita']) }}</td></tr>
    <tr><td>di cui nei fondi</td><td class="n">{{ $fmt($pr['in_fondi']) }}</td></tr>
</table>
<p class="d">Preventivo: le spese previste, meno le entrate da terzi. Deliberato con le rate: quanto chiedono i piani delle rate approvati. Quote dei piani dell'esercizio: le rate
    di quei piani già emesse.</p>
{{-- Le frasi del deliberato non disponibile o ricostruito, sotto il prospetto, al corpo del testo. --}}
@if($pr['avvertenza_deliberato'] || ! empty($pr['avvertenza_deliberato_ricostruito']))
<p style="font-size: 8pt; margin: 4px 0 0;">@if($pr['avvertenza_deliberato']){{ \App\Services\Gestionale\ParoleDellaStampa::frase($pr['avvertenza_deliberato']) }}@endif @if(! empty($pr['avvertenza_deliberato_ricostruito'])) {{ \App\Services\Gestionale\ParoleDellaStampa::frase($pr['avvertenza_deliberato_ricostruito']) }}@endif</p>
@endif
{{-- Le tre cifre delle spese del fascicolo (il riepilogo, il conguaglio, i costi) legate in una frase. --}}
@if(! empty($pr['spese']))
<p class="d">{{ $pr['spese'] }}</p>
@endif
{{-- Le registrazioni oltre il confine, con le loro cifre, accanto al risultato che cambiano. --}}
@if(isset($effetti['A']))
<p class="d">Il risultato comprende anche le registrazioni scritte in questo esercizio con una data fuori dal suo periodo: {{ lcfirst($effetti['A']) }}</p>
@endif
@if(isset($effetti['B']))
<p class="d">Il risultato non comprende le registrazioni con la data di questo esercizio scritte in un altro: {{ lcfirst($effetti['B']) }}</p>
@endif
<p class="d">{{ \App\Services\Gestionale\ParoleDellaStampa::frase($sintesi['riquadri']['liquidita']['frase']) }}</p>

{{-- L'indice e la firma restano insieme: se non stanno nella pagina vanno insieme sulla seguente, e la firma non resta mai da sola. Il margine del
     titolo dentro il blocco mPDF non lo unisce a quello del paragrafo sopra, e lo aggiungerebbe: lo spazio prima del titolo è il margine del paragrafo. --}}
<div style="page-break-inside: avoid;">
<h2 style="margin-top: 0;">Indice</h2>
<table class="p">
    <thead><tr><th style="width: 18px;">N.</th><th>Parte</th><th class="n">Pagina</th></tr></thead>
    <tbody>
    @foreach($righeIndice as $rigaIndice)
        @php($primo = $rigaIndice['pezzi'][0])
        @php($altri = array_slice($rigaIndice['pezzi'], 1))
        <tr><td class="d">{{ $loop->iteration }}</td><td>{{ $primo['titolo'] }} @if($primo['nota']) <br><span class="d">{{ $primo['nota'] }}</span> @endif
            @foreach($altri as $pezzo) · {{ $pezzo['titolo'] }} <span class="d">({{ $pezzo['nota'] ?? 'pagina '.$pezzo['pagina'] }})</span> @endforeach</td><td class="n">{{ $primo['pagina'] ?? '—' }}</td></tr>
    @endforeach
    </tbody>
</table>

<table class="firma">
    <tr>
        <td style="width: 55%;"></td>
        <td style="text-align: center;">
            L'amministratore<br><br><br>
            ______________________________
        </td>
    </tr>
</table>
</div>

</body>
</html>
