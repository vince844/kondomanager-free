{{--
  Stato patrimoniale — punto 3 di docs/registri_contabili.md (D16–D20) — la stampa.

  Stessa carta del registro di contabilità (§7-quinquies: palette e caratteri del fac-simile del
  forum), in verticale come il fac-simile, perché sono prospetti stretti. Legge la STESSA struttura
  della pagina (`StatoPatrimonialePaginaService::costruisci()`): schermo e foglio non possono divergere.

  ⚠️ Vista standalone, NON estende `pdf.base`: porta la propria carta intestata e i propri
  header/footer; niente firma (D20), è una fotografia calcolata e non un atto.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata (`gg@endif`).
  Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    $fmt = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
    $dataIt = fn (string $iso) => \Carbon\Carbon::parse($iso)->format('d/m/Y');
    $s = $pagina['situazione'];
    $rg = $pagina['risultato_gestione'];
    $rf = $pagina['riepilogo'];
    $lq = $pagina['liquidita'];
    $rc = $pagina['raccordo'];
    $si = $pagina['sintesi'];
    $db = $pagina['debiti'];
    $pg = $pagina['per_gestione'];
    $giornoIt = fn (?string $iso) => $iso ? \Carbon\Carbon::parse($iso)->format('d/m/Y') : '—';
    $allaData = $pagina['stato_esercizio'] === 'chiuso' ? 'alla data del '.$dataIt($pagina['data']) : 'a oggi, '.$dataIt($pagina['data']).': cambia a ogni stampa, finché l\'esercizio è aperto';
    // Le fatture aperte a blocchi di 100 righe, come il Libro Giornale e «Rate scadute»: mPDF tiene in memoria la tabella intera.
    $blocchiFatture = count($db['fatture']) === 0 ? [[]] : array_chunk($db['fatture'], 100);
    // Le stesse parole dell'esito in testa: «N tornano, M non tornano, K da leggere».
    $esitoTesto = ['quadra' => 'torna', 'non_quadra' => 'non torna', 'segnalazione' => 'da leggere'];
    $esitoClasse = ['quadra' => 'ok', 'non_quadra' => 'ko', 'segnalazione' => 'warn'];
    $classeR8 = $lq['esito_r8'] === 'quadra' ? 'ok' : ($lq['esito_r8'] === 'scoperto' ? 'ko' : 'warn');
    // Una parte sola (il fascicolo, le schede): la situazione (§ 1–4), la cassa (§ 5–6) o i controlli (§ 7); senza, la stampa intera.
    $parte = $parte ?? null;
    $mostra = fn (string $x) => $parte === null || $parte === $x;
    $titoloStampa = ['situazione' => 'Situazione patrimoniale', 'cassa' => 'Entrate e uscite per cassa', 'controlli' => 'Controlli del rendiconto'][$parte] ?? 'Stato patrimoniale';
    // I controlli tutti insieme e il loro esito, quando la stampa li ha; altrimenti i sei della pagina.
    $tuttiControlli = $controlli ?? $pagina['controlli'];
    $esito = $esito ?? null;
    $anno = $anno_a_cavallo ?? null;
    // Il foglio va ai condòmini: le frasi della pagina che parlano all'amministratore (il controllo che tocca a lui, il pulsante, il modulo che arriverà) in
    // stampa hanno le loro parole.
    $frase = fn (?string $t) => \App\Services\Gestionale\ParoleDellaStampa::frase($t);
    // Il conto dei riporti dagli esercizi prima: il suo nome dice «fondo», e non lo è.
    $riporti = collect($s['passivo']['gruppi'])->flatMap(fn ($g) => $g['voci'])->firstWhere('ruolo', 'passate_gestioni');
    $parteDelFascicolo = $parte_del_fascicolo ?? null;
    // Lo studio: il blocco in testa alla prima pagina e il suo nome nella testatina; senza, la carta intestata del condominio di sempre. Con la carta
    // intestata dello studio lo studio è stampato sulla carta: la testata ha il condominio e la riga della stampa, la testatina il condominio.
    $conStudio = isset($carta) && $carta->haStudio();
    $conCarta = isset($carta) && $carta->cartaIntestata() !== null;
    // I titoli, i loro filetti e la testata hanno il colore della carta dello studio; senza un colore, quello di sempre.
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#0E2238');
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $titoloStampa }} – {{ $esercizio->nome }}</title>
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
    h2 .sec { font-family: inter; font-size: 7pt; letter-spacing: 0.8pt; color: #5A6B7B; margin-right: 6px; }
    h2 .norma { font-family: inter; font-size: 6.5pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta ?? null, '#2E5C86') }}; float: right; letter-spacing: 0.3pt; font-weight: normal; }
    .intro { font-size: 7.5pt; color: #5A6B7B; margin: 0 0 6px; }
    .dati { width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5; border-bottom: 1px solid #D9DEE5; margin: 8px 0 4px; }
    .dati td { padding: 7px 10px 7px 0; border: 0; vertical-align: top; }
    .dati .et { font-size: 6.5pt; letter-spacing: 0.9pt; text-transform: uppercase; color: #5A6B7B; }
    .dati .vl { font-size: 10pt; color: #0E2238; font-family: fraunces; }
    .dati .sub { font-size: 6.5pt; color: #5A6B7B; }
    table.p { width: 100%; border-collapse: collapse; }
    table.p th { font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #5A6B7B; font-weight: bold; text-align: left; border-bottom: 1px solid #0E2238; padding: 4px 5px; }
    table.p td { padding: 3px 5px; border-bottom: 1px solid #D9DEE5; vertical-align: top; }
    table.p tr.gruppo td { background: #F4F5F7; font-size: 6.2pt; letter-spacing: 0.7pt; text-transform: uppercase; color: #14304C; font-weight: bold; padding-top: 5px; }
    table.p tfoot td { border-top: 1.5px solid #0E2238; border-bottom: 2px double #0E2238; font-weight: bold; color: #0E2238; padding: 5px; }
    .n { text-align: right; white-space: nowrap; font-family: fraunces; }
    /* `table.p th` è più specifico di `.n` e vinceva con text-align: left: il titolo «Verifica»
       usciva a sinistra sopra importi a destra. */
    table.p th.n { font-family: inter; text-align: right; }
    .badge { white-space: nowrap; }
    .neg { color: #96302F; }
    .d { color: #5A6B7B; font-size: 6.5pt; }
    .cod { color: #5A6B7B; font-family: fraunces; margin-right: 6px; }
    .badge { font-size: 5.8pt; font-weight: bold; letter-spacing: 0.5pt; text-transform: uppercase; padding: 1px 5px; }
    .ok { background: #E7F2EC; color: #1F6B45; }
    .ko { background: #F7E4E3; color: #96302F; }
    .warn { background: #FAF2DF; color: #8A6212; }
    .chiusura { margin-top: 6px; padding: 9px 12px; background: #F4F5F7; border-left: 3px solid #C9A227; font-size: 7.2pt; color: #26313F; }
    .chiusura b { color: #0E2238; }
    .avviso { margin-top: 6px; padding: 8px 12px; background: #F4F5F7; border-left: 3px solid #C9A227; font-size: 7.2pt; }
    .avviso b { color: #0E2238; }
    .eq { font-size: 7.5pt; color: #5A6B7B; margin: 4px 0 0; }
    .eq b { color: #0E2238; font-family: fraunces; }
    .blocco { margin-top: 8px; }
    .fut { margin-top: 8px; padding: 8px 12px; background: #FAF2DF; border-left: 3px solid #8A6212; font-size: 7.2pt; color: #7A5A10; }
@if($conCarta && ! $carta->primaPaginaIntera())
    {{-- Con la carta intestata il margine alto è quello della carta, e si allunga sulla testatina: data con `@page`, mPDF la misura già sulla prima
         pagina (accesa nel corpo arriverebbe a pagina cominciata, e la riga della stampa le finirebbe sotto). Il piede sta nella regola, che senza lo
         spegnerebbe dalla seconda pagina. --}}
    @page { header: html_spTestatina; footer: html_spPiede; }
@endif
</style>
</head>
<body>

<htmlpageheader name="spTestatina">
    <table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 0 0 5px 0; font-size: 7.5pt; color: #5A6B7B;">@if($conStudio){{ $carta->nome() }} · @endif<b style="color: {{ $coloreTitoli }};">{{ $condominio->nome }}</b> · {{ $titoloStampa }}</td>
            <td style="border: 0; padding: 0 0 5px 0; text-align: right; font-size: 7.5pt; color: #5A6B7B;">{{ $esercizio->nome }} · al {{ $dataIt($pagina['data']) }}</td>
        </tr>
    </table>
</htmlpageheader>
{{-- Con lo studio, una stampa che comincia con la testatina (le parti del fascicolo) la ha già dalla prima pagina. --}}
@if($conCarta && ! $carta->primaPaginaIntera())
{{-- La testatina e il piede li dà la regola `@page` qui sopra. --}}
@elseif($conStudio && ! $carta->primaPaginaIntera())
<sethtmlpageheader name="spTestatina" page="ALL" value="on" show-this-page="1" />
@else
<sethtmlpageheader name="spTestatina" page="ALL" value="on" />
@endif

<htmlpagefooter name="spPiede">
    @include('pdf._carta.piede_inter', ['giorno' => ($data_emissione_stampe ?? \App\Helpers\DateHelper::adessoUtente())->format('d/m/Y'), 'parte' => $parteDelFascicolo])
</htmlpagefooter>
<sethtmlpagefooter name="spPiede" page="ALL" value="on" />

@if($conStudio || $conCarta)
@component('pdf._carta.testata_inter', ['carta' => $carta, 'condominio' => $condominio, 'att' => $titoloStampa])
<b>{{ $esercizio->nome }}</b> · dal <b>{{ $esercizio->data_inizio->format('d/m/Y') }}</b> al <b>{{ $esercizio->data_fine->format('d/m/Y') }}</b> · Stampato il <b>{{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</b>
@endcomponent
@else
<table class="testata">
    <tr>
        <td>
            <div class="ente">{{ $condominio->nome }}</div>
            <div class="att">{{ $titoloStampa }}</div>
            <div class="rif">
                @if($condominio->indirizzo) {{ $condominio->indirizzo }}<br> @endif
                @if($condominio->codice_fiscale) C.F. {{ $condominio->codice_fiscale }} @endif
            </div>
        </td>
        <td class="doc">
            <b>{{ $esercizio->nome }}</b><br>
            dal <b>{{ $esercizio->data_inizio->format('d/m/Y') }}</b> al <b>{{ $esercizio->data_fine->format('d/m/Y') }}</b><br>
            Stampato il <b>{{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</b>
        </td>
    </tr>
</table>
@endif

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">@if($parte === 'cassa') Dal {{ $dataIt($rf['dal']) }} al {{ $dataIt($rf['al']) }} @elseif($parte === 'controlli') Al {{ $dataIt($pagina['data']) }} @else Situazione al {{ $dataIt($pagina['data']) }} — art. 1130-bis c.c. @endif</p>

@if($esito !== null && $parte !== 'cassa')
<div class="avviso" style="margin-bottom: 6px;">
    <b>I controlli: {{ $esito['frase'] }}.</b> {{ $esito['riga_fissa'] }} {{ $esito['riga_esiti'] }}
</div>
@endif

@if($mostra('situazione'))
<div class="chiusura">
    <b>Come leggere questo prospetto.</b> Ogni saldo è quanto c'era sui conti alla data indicata, con
    dentro tutto ciò che è successo prima, anche negli esercizi precedenti: è una fotografia, non il
    flusso dell'anno. Il risultato di gestione è la differenza fra le quote emesse ai condòmini e i
    costi dell'esercizio — l'avanzo o il disavanzo; che cosa farne lo decide l'assemblea. <b>Le quote emesse stanno
    fra le passività</b>, come debito della gestione verso i condòmini, non fra i ricavi: per questo
    attività e passività non sono uguali, e la quadratura è «attività + costi = passività» — in mezzo
    ci sono i costi non ancora conguagliati, elencati uno per uno.
    @if($riporti) «{{ $riporti['nome'] }}» è quanto riportato dagli esercizi prima, non un fondo accantonato. @endif
    I fondi sono parte dello stesso conto corrente e si contano nella liquidità una volta sola.
    @if($parte === null) Le entrate e uscite per cassa sono le scritture dell'esercizio. I controlli in fondo sono uguaglianze con i numeri veri, e quando non
    tornano dicono che cosa si fa; quelli che il programma non può eseguire non compaiono. @endif
    <br><b>La data:</b> {{ $allaData }}. <b>I debiti</b> sono quelli registrati come fatture. Un debito che non è una fattura (per
    esempio da una sentenza) non compare qui: se c'è, l'amministratore lo dice a parte. Le fatture aperte sono quelle di ogni esercizio con la data
    entro quella della situazione, con il residuo verso il fornitore al netto della ritenuta.
</div>

@if($pagina['stato_esercizio'] === 'futuro')
<div class="fut"><b>Esercizio non ancora iniziato</b> — comincia il {{ $dataIt($pagina['dal']) }}: la situazione è quella di oggi, il riepilogo non ha movimenti.</div>
@endif

<table class="dati">
    <tr>
        <td style="width: 25%;">
            <div class="et">Liquidità</div>
            <div class="vl {{ $si['liquidita'] < 0 ? 'neg' : '' }}">{{ $fmt($si['liquidita']) }}</div>
            @if($si['in_fondi'] !== 0) <div class="sub">di cui nei fondi {{ $fmt($si['in_fondi']) }}</div> @endif
        </td>
        <td style="width: 25%;">
            <div class="et">Crediti verso i condòmini</div>
            <div class="vl {{ $si['crediti_condomini'] < 0 ? 'neg' : '' }}">{{ $fmt($si['crediti_condomini']) }}</div>
            <div class="sub">quote emesse e non incassate, e spese anticipate per singole unità</div>
            @if($si['spese_anticipate'] !== 0) <div class="sub">di cui spese anticipate per singole unità, non ancora chieste: {{ $fmt($si['spese_anticipate']) }}</div> @endif
        </td>
        <td style="width: 25%;">
            <div class="et">Debiti</div>
            <div class="vl {{ ($si['debiti_fornitori'] + $si['debiti_erario']) < 0 ? 'neg' : '' }}">{{ $fmt($si['debiti_fornitori'] + $si['debiti_erario']) }}</div>
            <div class="sub">fornitori {{ $fmt($si['debiti_fornitori']) }} · Erario {{ $fmt($si['debiti_erario']) }}</div>
            @if($db['erario_su_fatture_non_pagate'] !== 0) <div class="sub">di cui {{ $fmt($db['erario_su_fatture_non_pagate']) }} di ritenute su fatture non ancora pagate: fino al pagamento sono un debito verso il fornitore</div> @endif
        </td>
        <td style="width: 25%;">
            <div class="et">Risultato di gestione</div>
            <div class="vl {{ $rg['risultato'] < 0 ? 'neg' : '' }}">{{ $fmt($rg['risultato']) }}{{ $rg['risultato'] === 0 ? '' : ($rg['risultato'] < 0 ? ' (disavanzo)' : ' (avanzo)') }}</div>
            <div class="sub">quote emesse {{ $fmt($rg['quote_emesse']) }} − costi {{ $fmt($rg['costi']) }}</div>
        </td>
    </tr>
</table>

<h2><span class="sec">§ 1</span>Situazione patrimoniale al {{ $dataIt($pagina['data']) }}</h2>
@foreach(['attivo' => 'Attività', 'passivo' => 'Passività'] as $lato => $titolo)
<table class="p blocco">
    <thead><tr><th colspan="2">{{ $titolo }}</th><th class="n">Importo</th></tr></thead>
    <tbody>
    @foreach($s[$lato]['gruppi'] as $g)
        <tr class="gruppo"><td colspan="2">{{ $g['gruppo'] }}</td><td class="n">{{ $fmt($g['totale']) }}</td></tr>
        @foreach($g['voci'] as $v)
        <tr><td colspan="2"><span class="cod">{{ $v['codice'] }}</span> {{ $v['nome'] }}</td><td class="n {{ $v['saldo'] < 0 ? 'neg' : '' }}">{{ $fmt($v['saldo']) }}</td></tr>
        @endforeach
    @endforeach
    @if($lato === 'attivo' && $s['liquidita_non_contabilizzata'] !== 0)
        <tr><td colspan="2" style="background: #FAF2DF; color: #7A5A10;">Saldi di apertura non ancora registrati a giornale</td><td class="n" style="background: #FAF2DF; color: #7A5A10;">{{ $fmt($s['liquidita_non_contabilizzata']) }}</td></tr>
    @endif
    {{-- I costi non ancora conguagliati stanno fra le attività (spese sostenute per conto dei
         condòmini), così le due colonne finiscono sullo stesso numero — idea di Vincenzo al test reale. --}}
    @if($lato === 'attivo' && count($s['costi_voci']) > 0)
        <tr class="gruppo"><td colspan="2" style="background: #FAF2DF; color: #7A5A10;">Costi non ancora conguagliati</td><td class="n" style="background: #FAF2DF; color: #7A5A10;">{{ $fmt($s['costi']) }}</td></tr>
        @foreach($s['costi_voci'] as $v)
        <tr><td colspan="2"><span class="cod">{{ $v['codice'] }}</span> {{ $v['nome'] }}</td><td class="n {{ $v['saldo'] < 0 ? 'neg' : '' }}">{{ $fmt($v['saldo']) }}</td></tr>
        @endforeach
    @endif
    @if($lato === 'passivo' && count($s['ricavi_voci']) > 0)
        <tr class="gruppo"><td colspan="2">Ricavi</td><td class="n">{{ $fmt($s['ricavi']) }}</td></tr>
        @foreach($s['ricavi_voci'] as $v)
        <tr><td colspan="2"><span class="cod">{{ $v['codice'] }}</span> {{ $v['nome'] }}</td><td class="n {{ $v['saldo'] < 0 ? 'neg' : '' }}">{{ $fmt($v['saldo']) }}</td></tr>
        @endforeach
    @endif
    @if(count($s[$lato]['gruppi']) === 0 && count($lato === 'attivo' ? $s['costi_voci'] : $s['ricavi_voci']) === 0)
        <tr><td colspan="3" class="d" style="text-align: center; padding: 8px;">Nessun conto movimentato</td></tr>
    @endif
    </tbody>
    <tfoot>
        @if($lato === 'attivo')
            <tr><td colspan="2">{{ $s['costi'] !== 0 ? 'Totale attività e costi da conguagliare' : 'Totale attività' }}</td><td class="n {{ $s['quadra'] ? '' : 'neg' }}">{{ $fmt($s['attivo']['totale'] + $s['costi']) }}</td></tr>
        @else
            <tr><td colspan="2">{{ $s['ricavi'] !== 0 ? 'Totale passività e ricavi' : 'Totale passività' }}</td><td class="n {{ $s['quadra'] ? '' : 'neg' }}">{{ $fmt($s['passivo']['totale'] + $s['ricavi']) }}</td></tr>
        @endif
    </tfoot>
</table>
@endforeach

<p class="eq"><b>Quadratura: {{ $s['quadra'] ? 'torna' : 'non torna' }}.</b> Attività <b>{{ $fmt($s['attivo']['totale']) }}</b> + Costi <b>{{ $fmt($s['costi']) }}</b> = Passività <b class="{{ $s['quadra'] ? '' : 'neg' }}">{{ $fmt($s['passivo']['totale']) }}</b>@if($s['ricavi'] !== 0) + Ricavi <b>{{ $fmt($s['ricavi']) }}</b>@endif @if(! $s['quadra']) <span class="neg">— sbilancio {{ $fmt($s['sbilancio']) }}</span> @endif · I costi dell'esercizio stanno fra le attività finché non saranno conguagliati: sono spese sostenute per conto dei condòmini; le quote emesse stanno fra le passività come debito della gestione, e con la chiusura d'esercizio costi e quote si compenseranno e resterà il conguaglio.</p>

<h2><span class="sec">§ 2</span>Fatture aperte al {{ $dataIt($db['alla']) }}</h2>
<p class="intro">Le fatture di ogni esercizio con la data entro il {{ $dataIt($db['alla']) }} e non ancora pagate a quella data, con il residuo verso il fornitore (al netto della ritenuta). In ordine di scadenza.</p>
@foreach($blocchiFatture as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table class="p">
    <thead><tr><th style="width: 34%;">Fornitore</th><th style="width: 22%;">Documento</th><th style="width: 14%;">Scadenza</th><th style="width: 14%;"></th><th class="n" style="width: 16%;">Residuo</th></tr></thead>
    <tbody>
    @foreach($blocco as $f)
        <tr>
            <td>{{ $f['fornitore'] }}</td>
            <td>{{ $f['numero'] }} <span class="d">del {{ $giornoIt($f['data']) }}</span></td>
            <td>{{ $giornoIt($f['scadenza']) }}</td>
            <td class="d">{{ implode(', ', array_filter([$f['nota_credito'] ? 'nota di credito' : null, $f['contestata'] ? 'contestata' : null, $f['pregressa'] ? 'pregressa' : null])) }}</td>
            <td class="n {{ $f['nota_credito'] ? 'neg' : '' }}">{{ $fmt($f['nota_credito'] ? -$f['residuo'] : $f['residuo']) }}</td>
        </tr>
    @endforeach
    @if(count($db['fatture']) === 0) <tr><td colspan="5" class="d" style="text-align: center; padding: 8px;">Nessuna fattura aperta a questa data</td></tr> @endif
    </tbody>
    @if($loop->last)
    <tfoot><tr><td colspan="4">Fatture aperte</td><td class="n">{{ $fmt($db['totale_residui']) }}</td></tr></tfoot>
    @endif
</table>
@endforeach
<p class="eq">Fatture aperte <b>{{ $fmt($db['totale_residui']) }}</b>@foreach($db['scarto_voci'] as $v) {{ $v['importo'] < 0 ? '−' : '+' }} {{ $v['voce'] }}@if($v['fatture'] !== []) ({{ implode(', ', $v['fatture']) }})@endif <b>{{ $fmt(abs($v['importo'])) }}</b>@endforeach = debiti verso i fornitori <b>{{ $fmt($db['saldo_fornitori']) }}</b>.</p>
@if($db['saldo_erario'] !== 0)
<p class="eq">Debiti verso l'Erario <b>{{ $fmt($db['saldo_erario']) }}</b>@if($db['erario_su_fatture_non_pagate'] !== 0), di cui <b>{{ $fmt($db['erario_su_fatture_non_pagate']) }}</b> di ritenute su fatture non ancora pagate: fino al pagamento sono un debito verso il fornitore @endif.</p>
@endif

<h2><span class="sec">§ 3</span>Per gestione</h2>
<p class="intro">I costi seguono la gestione della voce; le quote, quella del piano; i debiti, quella della fattura. I costi e le quote sono dell'esercizio; crediti e debiti sono alla data.</p>
<table class="p">
    <thead><tr><th>Gestione</th><th class="n">Costi dell'esercizio</th><th class="n">Quote emesse nell'esercizio</th><th class="n">Crediti verso i condòmini</th><th class="n">Debiti verso fornitori</th></tr></thead>
    <tbody>
    @foreach($pg['righe'] as $r)
        <tr>
            <td>{{ $r['gestione']['nome'] }}@if(! str_contains(mb_strtolower($r['gestione']['nome']), mb_strtolower($r['gestione']['tipo']))) <span class="d">· {{ $r['gestione']['tipo'] }}</span>@endif @if($r['cumulato_opera'])<br><span class="d">cumulato dell'opera dal {{ $giornoIt($r['cumulato_opera']['dal']) }}: {{ $fmt($r['cumulato_opera']['consuntivo']) }}</span>@endif</td>
            <td class="n">{{ $fmt($r['costi']) }}</td><td class="n">{{ $fmt($r['quote_emesse']) }}</td><td class="n">{{ $fmt($r['crediti']) }}</td><td class="n">{{ $fmt($r['debiti']) }}</td>
        </tr>
    @endforeach
    @if($pg['senza_voce'] !== 0 || array_filter($pg['senza_gestione']) !== [] || $pg['differenza_debiti'] !== 0)
        <tr>
            <td class="d">Senza una gestione: commissioni e altre spese non attribuite a una voce, e registrazioni senza gestione @if($pg['differenza_debiti'] !== 0) e la differenza dei debiti (§ 2) @endif</td>
            <td class="n">{{ $fmt($pg['senza_voce']) }}</td><td class="n">{{ $fmt($pg['senza_gestione']['quote_emesse']) }}</td><td class="n">{{ $fmt($pg['senza_gestione']['crediti']) }}</td><td class="n">{{ $fmt($pg['senza_gestione']['debiti'] + $pg['differenza_debiti']) }}</td>
        </tr>
    @endif
    </tbody>
    <tfoot><tr><td>Condominio</td><td class="n">{{ $fmt($pg['totale']['costi']) }}</td><td class="n">{{ $fmt($pg['totale']['quote_emesse']) }}</td><td class="n">{{ $fmt($pg['totale']['crediti']) }}</td><td class="n">{{ $fmt($pg['totale']['debiti']) }}</td></tr></tfoot>
</table>
<p class="d" style="margin: 4px 0 0;">@if($pg['nota_altra_gestione']){{ $pg['nota_altra_gestione'] }} @endif{{ $pg['nota_liquidita'] }}</p>

<h2><span class="sec">§ 4</span>Liquidità: libera e accantonata</h2>
<table class="p blocco">
    <thead><tr><th>Risorsa</th><th class="n">Importo</th></tr></thead>
    <tbody>
        <tr><td>Libera — banca e contanti</td><td class="n {{ $lq['libera'] < 0 ? 'neg' : '' }}">{{ $fmt($lq['libera']) }}</td></tr>
        @foreach($lq['fondi'] as $f)
        <tr><td style="padding-left: 14px;">{{ $f['cassa'] }} <span class="d">· {{ \App\Services\Gestionale\ParoleDellaStampa::fondo($f) }}@if($f['vincolo_registrato'] !== 0) · vincolo assegnato {{ $fmt($f['vincolo_registrato']) }}@if($f['scoperto'] > 0) <span class="neg">— mancano {{ $fmt($f['scoperto']) }}</span> @endif @endif</span></td><td class="n {{ $f['saldo'] < 0 ? 'neg' : '' }}">{{ $fmt($f['saldo']) }}</td></tr>
        @endforeach
        @if(count($lq['fondi']) === 0) <tr><td class="d" style="padding-left: 14px;">Nessun fondo</td><td></td></tr> @endif
        @foreach($lq['altra_voci'] as $a)
        <tr><td style="padding-left: 14px; background: #FAF2DF; color: #7A5A10;">{{ $a['voce'] }}</td><td class="n" style="background: #FAF2DF; color: #7A5A10;">{{ $fmt($a['saldo']) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot><tr><td>Totale liquidità</td><td class="n">{{ $fmt($lq['liquidita_totale']) }}</td></tr></tfoot>
</table>
<table class="p blocco">
    <thead><tr><th>Vincoli dichiarati e casse che li tengono</th><th class="n">Importo</th></tr></thead>
    <tbody>
        <tr><td>Vincoli dichiarati nei contributi versati</td><td class="n">{{ $fmt($lq['vincolo_registrato']) }}</td></tr>
        <tr><td>Vincoli coperti da una cassa fondo</td><td class="n">{{ $fmt($lq['vincolo_su_fondi']) }}</td></tr>
        <tr><td>Vincoli ancora nella liquidità libera</td><td class="n">{{ $fmt($lq['vincolo_non_accantonato']) }}</td></tr>
    </tbody>
    <tfoot><tr><td>Accantonato nelle casse fondo <span class="badge {{ $classeR8 }}">{{ $lq['esito_r8'] === 'quadra' ? $esitoTesto['quadra'] : ($lq['esito_r8'] === 'scoperto' ? 'scoperto' : $esitoTesto['segnalazione']) }}</span></td><td class="n">{{ $fmt($lq['in_fondi']) }}</td></tr></tfoot>
</table>
<p class="d" style="margin: 4px 0 0;">
    @if($lq['esito_r8'] === 'scoperto') Un fondo ha meno di quanto gli è stato assegnato: mancano {{ $fmt($lq['scoperto_fondi']) }}. Accantona con un giroconto dalla banca, o correggi il vincolo dichiarato.
    @elseif($lq['esito_r8'] === 'non_accantonato') {{ $fmt($lq['vincolo_non_accantonato']) }} dichiarati vincolati stanno in banca, non in un fondo: il vincolo esiste, l'accantonamento no.
    @elseif($lq['esito_r8'] === 'segnalazione') {{ $frase(\App\Services\Gestionale\StatoPatrimonialePaginaService::NOTA_VINCOLI_A_OGGI) }}
    @elseif($lq['vincolo_registrato'] === 0) Nessun contributo è dichiarato vincolato: i fondi tengono {{ $fmt($lq['in_fondi']) }} senza vincoli da coprire.
    @else Ogni euro dichiarato vincolato ha una cassa fondo che lo tiene.
    @endif
    Un fondo è parte dello stesso conto corrente: il totale è banca + contanti + fondi, una volta sola.
</p>

@endif

@if($mostra('cassa'))
@if($parte === 'cassa')
<div class="chiusura"><b>Come leggere questo prospetto.</b> Per cassa: il denaro entrato e uscito dalle casse con le scritture dell'esercizio. La disponibilità iniziale è il riporto dell'esercizio prima, con la stessa regola del registro di contabilità; l'apertura di una cassa conta lì, non fra le entrate. <b>La data:</b> {{ $allaData }}. Conta le scritture di questo esercizio, anche quelle con una data dopo la fine: il saldo a una data è nella situazione patrimoniale.</div>
@endif
<h2><span class="sec">§ 5</span>Entrate e uscite per cassa <span class="norma">dal {{ $dataIt($rf['dal']) }} al {{ $dataIt($rf['al']) }}</span></h2>
<table class="p">
    <thead><tr><th>Cassa</th><th class="n">Disponibilità iniziale</th><th class="n">Entrate</th><th class="n">Uscite</th><th class="n">Disponibilità finale</th></tr></thead>
    <tbody>
    @foreach($rf['righe'] as $r)
        <tr @if($r['negativa']) style="background: #F7E4E3;" @endif>
            <td>{{ $r['cassa'] }} <span class="d">· {{ $r['tipo'] }}</span> @if($r['negativa']) <span class="badge ko">sotto zero</span> <span class="d neg">{{ $fmt($r['minimo']) }} il {{ $dataIt($r['minimo_il']) }}</span> @endif</td>
            <td class="n {{ $r['iniziale'] < 0 ? 'neg' : '' }}">{{ $fmt($r['iniziale']) }}</td>
            <td class="n">{{ $fmt($r['entrate']) }}</td>
            <td class="n">{{ $fmt($r['uscite']) }}</td>
            <td class="n {{ $r['finale'] < 0 ? 'neg' : '' }}">{{ $fmt($r['finale']) }}</td>
        </tr>
    @endforeach
    @if(count($rf['righe']) === 0) <tr><td colspan="5" class="d" style="text-align: center; padding: 8px;">Nessuna cassa</td></tr> @endif
    </tbody>
    <tfoot>
        <tr><td>Tutte le casse, flussi con l'esterno</td><td class="n">{{ $fmt($rf['totale']['iniziale']) }}</td><td class="n">{{ $fmt($rf['totale']['entrate']) }}</td><td class="n">{{ $fmt($rf['totale']['uscite']) }}</td><td class="n">{{ $fmt($rf['totale']['finale']) }}</td></tr>
    </tfoot>
</table>
<p class="d" style="margin: 4px 0 0;">
    Ogni riga quadra da sé: iniziale + entrate − uscite = finale.
    @if($rf['giroconti']['entrate'] !== 0) I trasferimenti fra casse ({{ $fmt($rf['giroconti']['entrate']) }}) compaiono nelle righe e non nel totale, che conta i soli flussi con l'esterno. @endif
    @if($rf['stornate']['coppie'] !== 0) {{ $rf['stornate']['coppie'] === 1 ? 'Un movimento stornato' : $rf['stornate']['coppie'].' movimenti stornati' }} nell'esercizio ({{ $fmt($rf['stornate']['importo']) }}) non {{ $rf['stornate']['coppie'] === 1 ? 'conta' : 'contano' }} né fra le entrate né fra le uscite: originale e storno si annullano, e restano leggibili nel registro di contabilità. @endif
    La disponibilità iniziale è quanto c'era il giorno prima dell'inizio dell'esercizio; entrate e uscite sono le scritture dell'esercizio.
</p>

<h2><span class="sec">§ 6</span>Raccordo fra cassa e competenza</h2>
<p class="intro">Perché nell'esercizio la liquidità è cambiata di {{ $fmt($rc['variazione_liquidita']) }} mentre il risultato di gestione è {{ $fmt($rg['risultato']) }}: variazione della liquidità = risultato − aumento dei crediti + aumento dei debiti.</p>
<table class="p">
    <thead><tr><th>Partita</th><th>Natura</th><th class="n">Effetto</th></tr></thead>
    <tbody>
    @foreach($rc['righe'] as $r)
        <tr><td>{{ $r['voce'] }}</td><td class="d">{{ $r['natura'] }}</td><td class="n {{ $r['effetto'] < 0 ? 'neg' : '' }}">{{ $fmt($r['effetto']) }}</td></tr>
    @endforeach
    </tbody>
    <tfoot><tr><td colspan="2">Variazione della liquidità nell'esercizio</td><td class="n {{ $rc['quadra'] ? '' : 'neg' }}">{{ $fmt($rc['variazione_liquidita']) }}</td></tr></tfoot>
</table>

@endif

@if($mostra('controlli'))
<h2><span class="sec">§ 7</span>I controlli</h2>
<table class="p">
    {{-- La verifica come si legge: «€ 2.000,00 = € 2.000,00», non «primo membro / secondo membro»
         (gergo matematico, incomprensibile a video — Vincenzo). Il segno è quello vero del controllo. --}}
    <thead><tr><th style="width: 18px;">N.</th><th>Controllo</th><th class="n" style="width: 190px;">Verifica</th><th style="width: 84px; text-align: right;">Esito</th></tr></thead>
    <tbody>
    @foreach($tuttiControlli as $c)
        @php $segno = $c['segno'] ?? ($c['esito'] === 'non_quadra' ? '≠' : '='); @endphp
        <tr>
            <td class="cod">{{ $loop->iteration }}</td>
            <td><b>{{ $c['nome'] }}</b> — {{ $c['titolo'] }}<br><span class="d">{{ $frase($c['nota']) }}</span>@if($c['rimedio'])<br><span class="d"><b>Che cosa si fa:</b> {{ $frase($c['rimedio']) }}</span>@endif</td>
            {{-- Trappola di Blade: «zero@else» non è una direttiva (la @ preceduta da una lettera non viene compilata). Spazi attorno. --}}
            <td class="n">{{ $c['id'] === 'CN' ? $c['sinistra'].' '.($c['sinistra'] === 1 ? 'cassa' : 'casse').' sotto zero' : ($c['id'] === 'AC' ? $c['sinistra'].' '.($c['sinistra'] === 1 ? 'registrazione' : 'registrazioni') : ($c['id'] === 'GE' ? $c['sinistra'].' '.($c['sinistra'] === 1 ? 'fattura' : 'fatture') : $fmt($c['sinistra']).' '.$segno.' '.$fmt($c['destra']))) }}</td>
            <td style="text-align: right;"><span class="badge {{ $esitoClasse[$c['esito']] }}">{{ $esitoTesto[$c['esito']] }}</span></td>
        </tr>
    @endforeach
    </tbody>
</table>

@if($anno !== null && $anno['numero'] > 0)
<h2><span class="sec">§ 7.1</span>Registrazioni oltre il confine dell'esercizio</h2>
<p class="intro">{{ $frase($anno['frase_norma']) }} @if($anno['effetto']['A']['frase']) {{ $anno['effetto']['A']['frase'] }} @endif @if($anno['effetto']['B']['frase']) {{ $anno['effetto']['B']['frase'] }} @endif</p>
<table class="p">
    <thead><tr><th>Verso</th><th>Tipo</th><th class="n">Registrazioni</th><th class="n">In rosso</th><th class="n">Importo</th></tr></thead>
    <tbody>
    @foreach($anno['per_tipo'] as $t)
        <tr><td>{{ $t['verso'] === 'A' ? 'scritte qui, con la data fuori' : 'con la data di questo esercizio, scritte in un altro' }}</td><td>{{ $frase($t['etichetta']) }}</td><td class="n">{{ $t['numero'] }}</td><td class="n">{{ $t['rosse'] }}</td><td class="n">{{ $fmt($t['importo']) }}</td></tr>
    @endforeach
    </tbody>
</table>
<table class="p blocco">
    <thead><tr><th>Data</th><th>Sta nell'esercizio</th><th>Protocollo</th><th>Tipo</th><th class="n">Importo</th><th>Nota</th></tr></thead>
    <tbody>
    @foreach($anno['righe'] as $r)
        <tr><td>{{ $dataIt($r['data']) }}</td><td>{{ $r['esercizio'] }}</td><td>{{ $r['protocollo'] }}</td><td>{{ $frase($r['etichetta']) }} @if($r['rossa'] || $r['categoria'] === 'fattura_pregressa') <span class="badge {{ $r['rossa'] ? 'ko' : 'warn' }}">{{ $r['categoria'] === 'fattura_pregressa' ? 'pregressa' : 'cambia il riepilogo' }}</span> @endif</td><td class="n">{{ $fmt($r['importo']) }}</td><td class="d">{{ $r['nota'] }}</td></tr>
    @endforeach
    </tbody>
</table>
@if($anno['righe_filtrate'] > count($anno['righe']))
<p class="d" style="margin: 4px 0 0;">Le prime {{ count($anno['righe']) }} di {{ $anno['righe_filtrate'] }}; l'elenco intero si chiede all'amministratore.</p>
@endif
@foreach($anno['rimedi'] as $rimedio)
<p class="d" style="margin: 4px 0 0;">{{ $frase($rimedio) }}</p>
@endforeach
@endif

@endif

@if($parte === null)
<div class="avviso">
    <b>Che cosa questo prospetto non contiene.</b> L'elenco dei condòmini che devono soldi, solo il totale: l'elenco per persona è nella
    pagina «Rate scadute» dei movimenti, con lo scaduto di ognuno senza togliere il credito. Le posizioni di ognuno e i crediti per anzianità hanno la
    loro stampa, nella scheda «Condòmini» del rendiconto. Il confronto con l'estratto conto della banca: si fa a parte. Quote e costi si accumulano dal
    primo esercizio, e il cumulato da conguagliare è {{ $fmt($rg['cumulato']) }}. Non ha conti di ricavo, per questo non dichiara mai i conti «in pari».
    La fotografia è a oggi per l'esercizio aperto e alla data di fine per uno chiuso.
</div>
@endif

</body>
</html>
