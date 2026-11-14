{{--
  Il riepilogo finanziario per competenza dell'esercizio: la stampa. Legge lo stesso array della pagina (`RiepilogoPerCompetenza::perEsercizio()`): nessuna
  somma qui. Il dettaglio per spesa (una riga per fattura o movimento, con la sua voce) è a scelta, in fondo.

  Stessa carta della situazione patrimoniale; in orizzontale, per le otto colonne. Le righe del dettaglio a blocchi di 100, come il Libro Giornale:
  mPDF tiene in memoria la tabella intera.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    $fmt = fn (?int $c) => $c === null ? '—' : '€ '.number_format($c / 100, 2, ',', '.');
    $dataIt = fn (string $iso) => \Carbon\Carbon::parse($iso)->format('d/m/Y');
    $r = $riepilogo;
    $perche = fn (string $stato) => ['conservato' => '', 'non_conservato' => '—', 'solo_deliberato' => 'solo il deliberato', 'nessun_piano' => 'nessun piano approvato'][$stato] ?? '';
    // Il deliberato dei piani di prima ricostruito ha la cifra (Coda 557), con l'asterisco se la voce è divisa con gli importi di oggi.
    $Parole = \App\Services\Gestionale\ParoleDellaStampa::class;
    $Riepilogo = \App\Services\Gestionale\RiepilogoPerCompetenza::class;
    // Le frasi del deliberato di una gestione, sotto la sua tabella: «ricostruito» (con quella dell'asterisco, se una voce la porta) o «non disponibile»
    // (con il perché di un piano cambiato con «Sposta spesa»). Con le parole per i condòmini.
    $frasiDelDeliberato = function (array $b) use ($Parole, $Riepilogo): array {
        $asterisco = collect($b['spese'])->contains(fn ($c) => ! empty($c['deliberato_diviso_oggi']) || collect($c['voci'] ?? [])->contains(fn ($v) => ! empty($v['deliberato_diviso_oggi'])));

        return match ($b['deliberato_stato'] ?? null) {
            'ricostruito' => array_values(array_filter([$Parole::frase($Riepilogo::FRASE_DELIBERATO_RICOSTRUITO), $asterisco ? $Parole::frase($Riepilogo::FRASE_DELIBERATO_DIVISO) : null])),
            'non_conservato' => [$Parole::frase($Riepilogo::FRASE_DELIBERATO_NON_DISPONIBILE.(! empty($b['deliberato_spostato']) ? ' '.$Riepilogo::FRASE_DELIBERATO_SPOSTATO : ''))],
            default => [],
        };
    };
    $colonna = fn (array $v, string $c) => in_array($v[$c.'_stato'] ?? 'conservato', ['conservato', 'ricostruito'], true)
        ? $fmt($v[$c]).($c === 'deliberato' && ! empty($v['deliberato_diviso_oggi']) ? ' *' : '')
        // Il preventivo che le voci non portano più (portano quello dell'anno dopo) è nel verbale dell'assemblea.
        : ($c === 'preventivo' && $v[$c.'_stato'] === 'non_conservato' ? 'nel verbale dell\'assemblea' : $perche($v[$c.'_stato']));
    $nomeRiga = ['ordinarie' => 'Quote ordinarie', 'straordinarie' => 'Quote straordinarie', 'esercizi_precedenti' => 'Esercizi precedenti'];
    $dettaglio = $dettaglio ?? false;
    // Il dettaglio per spesa, una riga per fattura o movimento con la sua voce, nell'ordine della pagina; le righe sono quelle dell'array, nessuna somma.
    $righeDettaglio = [];
    if ($dettaglio) {
        foreach ($r['blocchi'] as $b) {
            foreach ([...$b['spese'], ...$b['entrate']] as $c) {
                foreach ($c['voci'] ?? [] as $v) {
                    foreach ($v['dettaglio'] ?? [] as $d) {
                        $righeDettaglio[] = ['voce' => $c['nome'].' · '.$v['nome']] + $d;
                    }
                }
                foreach ($c['dettaglio'] ?? [] as $d) {
                    $righeDettaglio[] = ['voce' => $c['nome']] + $d;
                }
            }
        }
    }
    $blocchiDettaglio = array_chunk($righeDettaglio, 100);
    // Le parti dei costi che ci sono solo a volte, in fila dopo le fatture contestate: il punto attaccato all'ultima cifra.
    $altriCosti = array_values(array_filter([
        $r['riconciliazione']['non_classificati'] ? ['costi non classificati', $r['riconciliazione']['non_classificati'], ''] : null,
        $r['riconciliazione']['differenza'] ? ['una differenza di', $r['riconciliazione']['differenza'], ' da guardare nel Libro Giornale'] : null,
    ]));
    // Lo studio: il blocco in testa alla prima pagina e il suo nome nella testatina; senza, la carta intestata del condominio di sempre.
    $conStudio = isset($carta) && $carta->haStudio();
    // I titoli, i loro filetti e la testata hanno il colore della carta dello studio; senza un colore, quello di sempre.
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#0E2238');
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Riepilogo finanziario – {{ $esercizio->nome }}</title>
<style>
    body { font-family: inter; font-size: 7.5pt; color: #26313F; }
    .testata { width: 100%; border-collapse: collapse; border-bottom: 2px solid {{ $coloreTitoli }}; }
    .testata td { padding: 0 0 10px 0; vertical-align: top; border: 0; }
    .ente { font-family: fraunces; font-size: 15pt; color: {{ $coloreTitoli }}; }
    .att { font-size: 7pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #5A6B7B; }
    .rif { font-size: 7.5pt; color: #5A6B7B; }
    .doc { text-align: right; font-size: 7.5pt; color: #5A6B7B; }
    .doc b { color: #0E2238; }
    h1 { font-family: fraunces; font-size: 17pt; color: {{ $coloreTitoli }}; margin: 14px 0 1px; padding: 0; }
    .sotto { font-family: fraunces; font-size: 10pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta ?? null, '#2E5C86') }}; margin: 0 0 10px; }
    h2 { font-family: fraunces; font-size: 11pt; color: {{ $coloreTitoli }}; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid {{ $coloreTitoli }}; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p th { font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; text-align: left; border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    table.p th.n { text-align: right; }
    table.p td { padding: 3px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    table.p tr.cap td { font-weight: bold; color: #0E2238; }
    table.p tr.voce td.nome { padding-left: 14px; }
    table.p tr.det td { color: #5A6B7B; font-size: 6.5pt; }
    table.p tr.det td.nome { padding-left: 26px; }
    table.p tfoot td { border-top: 1.5px solid #0E2238; border-bottom: 2px double #0E2238; font-weight: bold; color: #0E2238; padding: 5px; }
    .n { text-align: right; white-space: nowrap; }
    .d { color: #5A6B7B; font-size: 7pt; }
    {{-- Le frasi del deliberato sotto la tabella della gestione: al corpo del testo, non a quello delle note piccole. --}}
    .frase-deliberato { color: #26313F; margin: 5px 0 0; }
    .chiusura { margin-top: 6px; padding: 9px 12px; background: #F4F5F7; border-left: 3px solid #C9A227; font-size: 8pt; color: #26313F; }
    .chiusura b { color: #0E2238; }
    .eq { font-size: 8pt; color: #5A6B7B; margin: 4px 0 0; }
    .eq b { color: #0E2238; }
    .avviso { margin: 6px 0; padding: 8px 12px; background: #FAF2DF; border-left: 3px solid #8A6212; font-size: 7.2pt; color: #7A5A10; }
</style>
</head>
<body>

<htmlpageheader name="rfTestatina">
    <table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 0 0 5px 0; font-size: 7.5pt; color: #5A6B7B;">@if($conStudio){{ $carta->nome() }} · @endif<b style="color: {{ $coloreTitoli }};">{{ $condominio->nome }}</b> · Riepilogo finanziario</td>
            <td style="border: 0; padding: 0 0 5px 0; text-align: right; font-size: 7.5pt; color: #5A6B7B;">{{ $esercizio->nome }} · al {{ $dataIt($r['alla']) }}</td>
        </tr>
    </table>
</htmlpageheader>
{{-- Con lo studio, una stampa che comincia con la testatina (le parti del fascicolo) la ha già dalla prima pagina. --}}
@if($conStudio && ! $carta->primaPaginaIntera())
<sethtmlpageheader name="rfTestatina" page="ALL" value="on" show-this-page="1" />
@else
<sethtmlpageheader name="rfTestatina" page="ALL" value="on" />
@endif

<htmlpagefooter name="rfPiede">
    @include('pdf._carta.piede_inter', ['giorno' => \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y'), 'parte' => $parte_del_fascicolo ?? null])
</htmlpagefooter>
<sethtmlpagefooter name="rfPiede" page="ALL" value="on" />

@if($conStudio)
@component('pdf._carta.testata_inter', ['carta' => $carta, 'condominio' => $condominio, 'att' => 'Riepilogo finanziario'])
<b>{{ $esercizio->nome }}</b> · dal <b>{{ $dataIt($r['esercizio']['dal']) }}</b> al <b>{{ $dataIt($r['esercizio']['al']) }}</b> · Stampato il <b>{{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</b>
@endcomponent
@else
<table class="testata">
    <tr>
        <td>
            <div class="ente">{{ $condominio->nome }}</div>
            <div class="att">Riepilogo finanziario</div>
            <div class="rif">
                @if($condominio->indirizzo) {{ $condominio->indirizzo }}<br> @endif
                @if($condominio->codice_fiscale) C.F. {{ $condominio->codice_fiscale }} @endif
            </div>
        </td>
        <td class="doc">
            <b>{{ $esercizio->nome }}</b><br>
            dal <b>{{ $dataIt($r['esercizio']['dal']) }}</b> al <b>{{ $dataIt($r['esercizio']['al']) }}</b><br>
            Stampato il <b>{{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</b>
        </td>
    </tr>
</table>
@endif

<h1>Riepilogo finanziario</h1>
<p class="sotto">Per competenza, {{ $r['avvertenze']['data'] }}</p>

<div class="chiusura">
    <b>Come leggere questo prospetto.</b> Per ogni spesa: il previsto (preventivo), il chiesto con le rate (deliberato), lo speso (consuntivo), lo speso dell'anno prima,
    la differenza fra speso e chiesto (scostamento: con il meno si è speso meno), il pagato e il da pagare ai fornitori. Le spese sono raccolte per capitolo; ogni
    capitolo ha le sue voci.
    <b>Per competenza:</b> le spese di questo esercizio, pagate o no, per capitolo; il pagato e il da pagare sono
    {{ $r['avvertenze']['data'] }}. <b>Il segno:</b> gli importi dei condòmini sono positivi quando devono, negativi quando hanno un credito.
    {{ $r['avvertenze']['pagato_diviso'] }} Il pagato comprende la ritenuta d'acconto quando è versata allo Stato con l'F24; fino ad allora è nel da pagare.
    {{ $r['avvertenze']['perimetro_da_pagare'] }}
    Le fatture contestate stanno fuori dai capitoli, in una riga loro, finché sono contestate; le commissioni bancarie non attribuite a una spesa e i costi non classificati
    anche. «Scritto sul capitolo» è la spesa scritta sul capitolo stesso e non su una sua voce. Le entrate da terzi riducono le spese, come il piano riduce
    già le quote. Le quote pagate sono versate o compensate con un credito; «pagato in più» è quanto è stato pagato oltre il costo di una fattura, e non
    riduce il da pagare delle altre; il credito riportato da un esercizio prima non è fra le pagate finché non chiude una quota.
    Quando la gestione è già passata all'esercizio dopo, il preventivo di quest'anno è nel verbale dell'assemblea.
    @if($r['avvertenze']['anno_prima']) La colonna «Anno prima» è vuota: è il primo esercizio registrato. @endif
</div>

@if(! empty($avviso))
<div class="avviso">{{ $avviso }}</div>
@endif

@foreach($r['blocchi'] as $b)
<h2>{{ $b['gestione']['nome'] }}</h2>
@if($b['cumulato_opera'])
<p class="eq">Cumulato dell'opera @if($b['cumulato_opera']['dal']) dal {{ $dataIt($b['cumulato_opera']['dal']) }} @endif: <b>{{ $fmt($b['cumulato_opera']['consuntivo']) }}</b>.</p>
@endif
<table class="p">
    <thead><tr><th>Spese</th><th class="n">Preventivo</th><th class="n">Deliberato con le rate{{ ($b['deliberato_stato'] ?? null) === 'ricostruito' ? ' (ricostruito)' : '' }}</th><th class="n">Consuntivo</th><th class="n">Anno prima</th><th class="n">Scostamento</th><th class="n">Pagato</th><th class="n">Da pagare</th></tr></thead>
    <tbody>
    @foreach($b['spese'] as $c)
        <tr class="cap">
            <td class="nome">{{ $c['nome'] }}</td><td class="n">{{ $colonna($c, 'preventivo') }}</td><td class="n">{{ $colonna($c, 'deliberato') }}</td>
            <td class="n">{{ $fmt($c['consuntivo']) }}</td><td class="n">{{ $fmt($c['anno_prima']) }}</td><td class="n">{{ $fmt($c['scostamento']) }}</td>
            <td class="n">{{ $fmt($c['pagato']) }}</td>
            <td class="n">{{ $fmt($c['da_pagare']) }}@if($c['ritenute_da_versare']) <br><span class="d">di cui ritenute da versare {{ $fmt($c['ritenute_da_versare']) }}</span> @endif @if($c['pagato_in_piu']) <br><span class="d">pagato in più {{ $fmt($c['pagato_in_piu']) }}</span> @endif</td>
        </tr>
        @foreach($c['voci'] ?? [] as $v)
            <tr class="voce">
                <td class="nome">{{ $v['nome'] }}</td><td class="n">{{ $colonna($v, 'preventivo') }}</td><td class="n">{{ $colonna($v, 'deliberato') }}</td>
                <td class="n">{{ $fmt($v['consuntivo']) }}</td><td class="n">{{ $fmt($v['anno_prima']) }}</td><td class="n">{{ $fmt($v['scostamento']) }}</td>
                <td class="n">{{ $fmt($v['pagato']) }}</td><td class="n">{{ $fmt($v['da_pagare']) }}</td>
            </tr>
        @endforeach
    @endforeach
    @if(count($b['spese']) === 0) <tr><td colspan="8" class="d" style="text-align: center; padding: 8px;">Nessuna voce di spesa</td></tr> @endif
    </tbody>
    <tfoot>
        <tr><td>Totale spese</td><td class="n">{{ $fmt($b['totale_spese']['preventivo']) }}</td><td class="n">{{ $fmt($b['totale_spese']['deliberato']) }}</td><td class="n">{{ $fmt($b['totale_spese']['consuntivo']) }}</td>
            <td class="n">{{ $fmt($b['totale_spese']['anno_prima']) }}</td><td></td><td class="n">{{ $fmt($b['totale_spese']['pagato']) }}</td><td class="n">{{ $fmt($b['totale_spese']['da_pagare']) }}</td></tr>
    </tfoot>
</table>
{{-- Le spese per singole unità chieste con le rate non sono di nessuna voce: dopo il totale delle spese, che non le conta, con le quote deliberate. --}}
@if(! empty($b['spese_personali']))
<p class="eq" data-riga="spese-personali">Deliberato con le rate: Totale spese <b>{{ $fmt($b['totale_spese']['deliberato']) }}</b>@if(($b['totale_entrate']['deliberato'] ?? 0) != 0) − entrate da terzi <b>{{ $fmt($b['totale_entrate']['deliberato']) }}</b>@endif
    + Spese personali chieste con le rate <b>{{ $fmt($b['spese_personali']) }}</b> <span class="d">(di una sola unità, in nessuna voce)</span> = Quote deliberate <b>{{ $fmt($b['quote'][0]['deliberate'] ?? null) }}</b>.</p>
@endif
@foreach($frasiDelDeliberato($b) as $frase)
<p class="frase-deliberato" style="font-size: 8pt;">{{ $frase }}</p>
@endforeach

@if(count($b['entrate']) > 0)
<table class="p" style="margin-top: 8px;">
    <thead><tr><th>Entrate da terzi</th><th class="n">Preventivo</th><th class="n">Incassato</th><th class="n">Anno prima</th></tr></thead>
    <tbody>
    @foreach($b['entrate'] as $e)
        <tr><td>{{ $e['nome'] }}</td><td class="n">{{ $colonna($e, 'preventivo') }}</td><td class="n">{{ $fmt($e['incassato']) }}</td><td class="n">{{ $fmt($e['anno_prima']) }}</td></tr>
        @if($e['consuntivo'])
        <tr class="det"><td class="nome" colspan="4">Spese scritte sulla voce di entrata: {{ $fmt($e['consuntivo']) }}, pagate {{ $fmt($e['pagato']) }}, da pagare {{ $fmt($e['da_pagare']) }}</td></tr>
        @endif
    @endforeach
    </tbody>
</table>
@endif

@if(count($b['quote']) > 0)
<table class="p" style="margin-top: 8px;">
    <thead><tr><th>Quote dei condòmini</th><th class="n">Deliberate</th><th class="n">Emesse</th><th class="n">Pagate</th><th class="n">Non pagate</th><th class="n">di cui scadute</th><th class="n">Crediti sulle rate</th><th class="n">Credito riportato</th></tr></thead>
    <tbody>
    @foreach($b['quote'] as $q)
        <tr><td>{{ $nomeRiga[$q['riga']] }}</td><td class="n">{{ $fmt($q['deliberate']) }}</td>
            <td class="n">{{ $fmt($q['emesse']) }}</td><td class="n">{{ $fmt($q['pagate']) }}</td><td class="n">{{ $fmt($q['non_pagate']) }}</td><td class="n">{{ $fmt($q['scadute']) }}</td>
            <td class="n">{{ $fmt($q['crediti']) }}</td><td class="n">{{ $q['credito_riportato'] ? $fmt($q['credito_riportato']) : '—' }}</td></tr>
    @endforeach
    </tbody>
</table>
@endif
@endforeach

{{-- Le uscite fuori dai capitoli e il risultato stanno insieme: la riga dei costi del risultato somma le prime, e il risultato non resta da solo su una
     pagina. --}}
<div style="page-break-inside: avoid;">
<h2>Fuori dai capitoli</h2>
<table class="p">
    <thead><tr><th></th><th class="n">Consuntivo</th><th class="n">Pagato</th><th class="n">Da pagare</th></tr></thead>
    <tbody>
        <tr><td>Fatture contestate <span class="d">({{ $r['fuori_capitoli']['contestate']['numero'] }}): fuori dai capitoli finché sono contestate</span></td><td class="n">{{ $fmt($r['fuori_capitoli']['contestate']['consuntivo']) }}</td><td class="n">{{ $fmt($r['fuori_capitoli']['contestate']['pagato']) }}</td><td class="n">{{ $fmt($r['fuori_capitoli']['contestate']['da_pagare']) }}</td></tr>
        <tr><td>Commissioni bancarie non attribuite a una spesa <span class="d">({{ $r['fuori_capitoli']['commissioni_senza_voce']['numero'] }})</span></td><td class="n">{{ $fmt($r['fuori_capitoli']['commissioni_senza_voce']['consuntivo']) }}</td><td class="n">{{ $fmt($r['fuori_capitoli']['commissioni_senza_voce']['pagato']) }}</td><td class="n">{{ $fmt($r['fuori_capitoli']['commissioni_senza_voce']['da_pagare']) }}</td></tr>
        @if($r['fuori_capitoli']['non_classificati']['consuntivo'])
        <tr><td>Costi non classificati <span class="d">({{ $r['fuori_capitoli']['non_classificati']['numero'] }}): righe di costo senza voce</span></td><td class="n">{{ $fmt($r['fuori_capitoli']['non_classificati']['consuntivo']) }}</td><td class="n">{{ $fmt($r['fuori_capitoli']['non_classificati']['pagato']) }}</td><td class="n">{{ $fmt($r['fuori_capitoli']['non_classificati']['da_pagare']) }}</td></tr>
        @endif
    </tbody>
</table>
<p class="d" style="margin: 4px 0 0;">Le fatture di un anno passato registrate come pregresse non sono costi di questo esercizio e non compaiono qui, tranne la parte non coperta
    dai saldi iniziali, se c'è: quella è un costo di questo esercizio, e sta fra le spese.</p>

<h2>Il risultato</h2>
<p class="eq">Risultato: quote registrate in questo esercizio <b>{{ $fmt($r['risultato']['quote_emesse']) }}</b> − costi <b>{{ $fmt($r['risultato']['costi']) }}</b> = <b>{{ $fmt($r['risultato']['risultato']) }}</b> ({{ \App\Services\Gestionale\RiepilogoPerCompetenza::comeRisultato($r['risultato']['risultato']) }}).</p>
<p class="eq">Costi dell'esercizio <b>{{ $fmt($r['riconciliazione']['costi']) }}</b> = spese per capitolo meno entrate <b>{{ $fmt($r['riconciliazione']['capitoli_netti']) }}</b>
    + commissioni bancarie non attribuite a una spesa <b>{{ $fmt($r['riconciliazione']['commissioni_senza_voce']) }}</b> + fatture contestate <b>{{ $fmt($r['riconciliazione']['contestate']) }}</b>@foreach($altriCosti as [$prima, $importo, $dopo]) + {{ $prima }} <b>{{ $fmt($importo) }}</b>{{ $dopo }}@endforeach.</p>
<p class="eq">Quote dei piani dell'esercizio <b>{{ $fmt($r['raccordo_quote']['quote_piani']) }}</b> = quote registrate in questo esercizio <b>{{ $fmt($r['raccordo_quote']['quote_giornale']) }}</b>
    + rate di questi piani emesse in un altro esercizio <b>{{ $fmt($r['raccordo_quote']['piani_emesse_altrove']) }}</b> − rate di altri piani emesse in questo <b>{{ $fmt($r['raccordo_quote']['altri_emesse_qui']) }}</b>@if($r['raccordo_quote']['altre_differenze']) + <b>{{ $fmt($r['raccordo_quote']['altre_differenze']) }}</b> di debiti riportati dentro le rate @endif.</p>
<p class="eq">Le quote pagate, versate o compensate con un credito, sono <b>{{ $fmt($r['quote']['totale']['pagate']) }}</b>; i versamenti dei condòmini nel registro di contabilità di questo esercizio sono <b>{{ $fmt($r['quote']['totale']['versamenti_registro']) }}</b>.
    Le non pagate sono contate senza togliere i crediti sulle rate: <b>{{ $fmt($r['quote']['totale']['non_pagate']) }}</b>, e accanto <b>{{ $fmt($r['quote']['totale']['crediti']) }}</b> di crediti sulle rate.</p>
</div>

@if($dettaglio)
<h2>Dettaglio per spesa</h2>
@foreach($blocchiDettaglio as $blocco)
<table class="p">
    @if($loop->first)
    <thead><tr><th>Voce</th><th>Data</th><th>Documento</th><th>Fornitore e descrizione</th><th class="n">Consuntivo</th><th class="n">Pagato</th><th class="n">Da pagare</th></tr></thead>
    @endif
    <tbody>
    @foreach($blocco as $d)
        <tr><td>{{ $d['voce'] }}</td><td>{{ $dataIt($d['data']) }}</td><td>{{ $d['numero'] }}</td><td>{{ $d['fornitore'] }} @if($d['fornitore'] && $d['descrizione']) · @endif {{ $d['descrizione'] }}</td>
            <td class="n">{{ $fmt($d['consuntivo']) }}</td><td class="n">{{ $fmt($d['pagato']) }}</td><td class="n">{{ $fmt($d['da_pagare']) }}</td></tr>
    @endforeach
    </tbody>
</table>
@endforeach
@if(count($righeDettaglio) === 0) <p class="d">Nessuna spesa nell'esercizio.</p> @endif
@endif

</body>
</html>
