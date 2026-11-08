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
    $esitoTesto = ['quadra' => 'torna', 'non_quadra' => 'non torna', 'segnalazione' => 'da leggere'];
    // Le parti con i dati di tutti i condòmini: le posizioni e i crediti per anzianità, il registro (chi ha versato, quanto, quando).
    $riservato = collect($indice)->contains(fn ($v) => in_array($v['parte'], ['condomini', 'registro'], true) && $v['pagine'] > 0);
    $paginaControlli = collect($indice)->firstWhere('parte', 'controlli')['pagina'] ?? null;
    // Le registrazioni oltre il confine: l'effetto sul riepilogo, e quindi sul risultato, nei due versi.
    $anno = $anno_a_cavallo ?? null;
    $effetti = $anno === null ? [] : array_filter(['A' => $anno['effetto']['A']['frase'] ?? null, 'B' => $anno['effetto']['B']['frase'] ?? null]);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Rendiconto – {{ $esercizio->nome }}</title>
<style>
    body { font-family: inter; font-size: 8pt; color: #26313F; }
    .studio { font-size: 7.5pt; color: #5A6B7B; border-bottom: 1px solid #D9DEE5; padding-bottom: 6px; margin-bottom: 18px; }
    .studio b { color: #0E2238; }
    .ente { font-family: fraunces; font-size: 20pt; color: #0E2238; margin: 0; }
    .rif { font-size: 8pt; color: #5A6B7B; margin: 2px 0 16px; }
    h1 { font-family: fraunces; font-size: 16pt; color: #0E2238; margin: 0 0 2px; }
    .sotto { font-family: fraunces; font-size: 10pt; color: #2E5C86; margin: 0 0 14px; }
    /* Righe strette: il frontespizio, con le righe che spiegano gli esiti e il prospetto, sta in una pagina con la firma. */
    h2 { font-family: fraunces; font-size: 11pt; color: #0E2238; margin: 12px 0 5px; padding-bottom: 3px; border-bottom: 1px solid #0E2238; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p td { padding: 2px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    table.p th { font-size: 6.5pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; text-align: left; border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    .n { text-align: right; white-space: nowrap; }
    .d { color: #5A6B7B; font-size: 7pt; }
    .esito { padding: 9px 12px; background: #F4F5F7; border-left: 3px solid #C9A227; }
    .esito b { color: #0E2238; }
    .firma { margin-top: 18px; width: 100%; }
    .firma td { border: 0; padding: 0; }
</style>
</head>
<body>

<div class="studio">
    @if($studio->studio_nome) <b>{{ $studio->studio_nome }}</b> @endif
    @if($studio->studio_indirizzo) · {{ $studio->studio_indirizzo }} @endif
    @if($studio->studio_comune) · {{ $studio->studio_comune }} @endif
    @if($studio->studio_telefono) · {{ $studio->studio_telefono }} @endif
    @if($studio->studio_email) · {{ $studio->studio_email }} @endif
    @if($studio->studio_pec) · PEC {{ $studio->studio_pec }} @endif
</div>

<div class="ente">{{ $condominio->nome }}</div>
<div class="rif">
    @if($condominio->indirizzo) {{ $condominio->indirizzo }} @endif
    @if($condominio->codice_fiscale) · C.F. {{ $condominio->codice_fiscale }} @endif
</div>

<h1>Rendiconto dell'esercizio</h1>
<p class="sotto">{{ $esercizio->nome }}, dal {{ $dataIt($sintesi['esercizio']['dal']) }} al {{ $dataIt($sintesi['esercizio']['al']) }} · stampato il {{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</p>
@if($riservato)
<p class="esito" style="margin-bottom: 6px;"><b>{{ \App\Services\Gestionale\PosizioniDeiCondomini::RISERVATO }}</b></p>
@endif
<p class="d">{{ $sintesi['stato']['frase'] }}</p>

<h2>I controlli</h2>
<div class="esito">
    <b>{{ $es['frase'] }}.</b> {{ $es['riga_fissa'] }} {{ $es['riga_esiti'] }}
    <br>
    @foreach($es['righe'] as $riga)
        @if($riga['esito'] !== 'quadra')
            {{-- Il perché in poche parole, dove il controllo lo ha; per le registrazioni oltre il confine, l'effetto sul riepilogo. --}}
            @php($perche = empty($riga['breve']) ? '' : ' ('.$riga['breve'].')')
            @php($effetto = $riga['id'] === 'AC' && $effetti !== [] ? ' '.implode(' ', $effetti) : '')
            <span class="d">{{ $riga['nome'] }}: {{ $esitoTesto[$riga['esito']] }}{{ $perche }}.{{ $effetto }}</span>
        @endif
    @endforeach
    @if($paginaControlli !== null)
    <br><span class="d">Il perché di ognuno è nei controlli, a pagina {{ $paginaControlli }}.</span>
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
    <tr><td><b>Risultato</b>: quote registrate in questo esercizio {{ $fmt($pr['quote_giornale']) }} − costi {{ $fmt($pr['costi']) }}</td><td class="n"><b>{{ $fmt($pr['risultato']) }}</b> ({{ $pr['risultato'] === 0 ? 'in pareggio' : ($pr['risultato'] < 0 ? 'disavanzo' : 'avanzo') }})</td></tr>
    <tr><td>Liquidità secondo la contabilità, al {{ $dataIt($sintesi['riquadri']['liquidita']['alla']) }}</td><td class="n">{{ $fmt($sintesi['riquadri']['liquidita']['liquidita']) }}</td></tr>
    <tr><td>di cui nei fondi</td><td class="n">{{ $fmt($pr['in_fondi']) }}</td></tr>
</table>
<p class="d">Preventivo: le spese previste, meno le entrate da terzi. Deliberato con le rate: quanto chiedono i piani delle rate approvati. Quote dei piani dell'esercizio: le rate
    di quei piani già emesse.@if($pr['avvertenza_deliberato']) {{ $pr['avvertenza_deliberato'] }}@endif</p>
@if(isset($effetti['A']))
<p class="d">Il risultato comprende anche le registrazioni scritte in questo esercizio con una data fuori dal suo periodo: le cifre sono qui sopra, accanto al controllo
    «Registrazioni oltre il confine dell'esercizio».</p>
@endif
@if(isset($effetti['B']))
<p class="d">Il risultato non comprende le registrazioni con la data di questo esercizio scritte in un altro: le cifre sono qui sopra, accanto al controllo
    «Registrazioni oltre il confine dell'esercizio».</p>
@endif
<p class="d">{{ \App\Services\Gestionale\ParoleDellaStampa::frase($sintesi['riquadri']['liquidita']['frase']) }}</p>

<h2>Indice</h2>
<table class="p">
    <thead><tr><th style="width: 18px;">N.</th><th>Parte</th><th class="n">Pagina</th></tr></thead>
    <tbody>
    @foreach($indice as $v)
        <tr><td class="d">{{ $loop->iteration }}</td><td>{{ $v['titolo'] }} @if($v['nota']) <br><span class="d">{{ $v['nota'] }}</span> @endif</td><td class="n">{{ $v['pagina'] ?? '—' }}</td></tr>
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

</body>
</html>
