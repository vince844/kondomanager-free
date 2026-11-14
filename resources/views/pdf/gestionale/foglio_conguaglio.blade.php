{{--
  Il foglio della persona: la sua parte delle spese dell'esercizio, unità per unità e voce per voce, e la proposta di conguaglio, dall'array di
  `FogliDelConguaglio::datiDelFoglio()`. Nessuna nota sui passaggi regolati fra le parti e nessun avviso per l'amministratore: sono nella scheda.

  La carta della situazione patrimoniale (§ 7-quinquies di docs/registri_contabili.md: Inter e Fraunces, la palette del fac-simile), in verticale,
  con la carta «per la persona» come l'estratto conto: la testata dello studio o del condominio, la carta intestata quando c'è, la testatina dalla
  seconda pagina. Con la firma, come l'estratto conto: il foglio va alla persona.

  Il conguaglio e il saldo di ogni unità stanno in una tabella che non si spezza; così le righe che spiegano, con la firma in fondo.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    use App\Services\Gestionale\ConguaglioDelRendiconto as C;
    use App\Services\Gestionale\ParoleDelConguaglio as P;
    // Il foglio va al condòmino: le parole sono le sue (`ParoleDelConguaglio`), una cifra che ancora non c'è è «non ancora calcolato».
    $euro = fn (?int $c) => $c === null ? P::NON_CALCOLATO : '€ ' . number_format($c / 100, 2, ',', '.');
    // Il segno dopo il simbolo, come il meno: «€ +100,00», «€ -50,00».
    $segno = fn (?int $c) => $c === null ? P::NON_CALCOLATO : '€ ' . ($c > 0 ? '+' : '') . number_format($c / 100, 2, ',', '.');
    // Nelle note accanto o sotto una cifra l'euro e il numero non vanno a capo fra loro: lo spazio fra i due è uno spazio che non si spezza.
    $euroNota = fn (int $c) => '€'."\u{00A0}".number_format($c / 100, 2, ',', '.');
    $f = $foglio;
    $t = $f['totale'];
    $sp = $f['spiegano'];
    $titoloStampa = 'Consuntivo e proposta di conguaglio';
    $sezione = 0;
    // Quante righe di unità ha il foglio: con una sola, «In tutto» ripeterebbe le sue cifre.
    $righeDiUnita = array_sum(array_map(fn ($g) => count($g['unita']), $f['gestioni'])) + count($f['da_altre_gestioni']);
    $nd = $f['non_divise'] ?? ['divise' => 0, 'non_divise' => 0, 'cosa' => []];
    // Le somme fuori dalle rate (un saldo da un esercizio prima, la coppia di un passaggio) sono in «già da versare»: la riga sotto la cifra lo dice.
    $conFuori = collect($f['gestioni'])->flatMap(fn ($g) => $g['unita'])->contains(fn ($u) => (int) ($u['fuori'] ?? 0) !== 0);
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>{{ $titoloStampa }} – {{ $esercizio->nome }}</title>
@include('pdf.gestionale._rendiconto_stile')
<style>
    table.p { font-size: 7.5pt; }
    table.p th.unita { font-family: fraunces; font-size: 9pt; letter-spacing: 0; text-transform: none; color: #0E2238; font-weight: normal; }
    table.p td.e { border-bottom: 0; }
    {{-- Il filetto sopra le righe dei totali è più largo di quello sotto la riga prima: con i bordi uniti vince il più largo, su tutta la riga. --}}
    table.p tr.k td { border-top: 1.2px solid #0E2238; border-bottom: 0; font-weight: bold; color: #0E2238; }
    table.p tr.di td { color: #5A6B7B; border-bottom: 0; }
    table.p tr.di td.ri { padding-left: 14px; }
</style>
</head>
<body>

@include('pdf.gestionale._rendiconto_testa', ['alla' => $f['data']['alla'] ?? (string) $esercizio->data_fine, 'chi' => $f['persona']['nome']])

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">{{ $f['data']['frase'] }}</p>

{{-- In testa le cifre che contano, con il loro verbo: per chi è il foglio, che cosa deve già, che cosa è solo proposto, e quanto resta se l'assemblea
     approva. Chi legge deve capire da solo che cosa deve già e che cosa è solo proposto. --}}
<table class="dati">
    <tr>
        <td style="width: 28%;">
            <div class="dato-et">Per</div>
            <div class="dato-vl">{{ $f['persona']['nome'] }}</div>
            @if($f['persona']['indirizzo'] !== '')<div class="dato-sub">{{ $f['persona']['indirizzo'] }}</div>@endif
        </td>
        <td style="width: 24%;">
            {{-- L'etichetta segue il segno: un credito non è «da versare». In tabella le cifre restano con il segno, come le spiega il «Come leggere». --}}
            <div class="dato-et">{{ $t['gia'] < 0 ? 'Già a suo credito' : 'Già da versare' }}</div>
            <div class="dato-vl">{{ P::conIlVerbo($t['gia']) }}</div>
            <div class="dato-sub">rate già chieste e non pagate @if($conFuori) e altre somme fuori dalle rate @endif</div>
        </td>
        <td style="width: 24%;">
            <div class="dato-et">Proposta di conguaglio, da approvare</div>
            <div class="dato-vl">{{ $f['non_disponibile'] ? P::NON_CALCOLATO : P::conIlVerbo($t['conguaglio']) }}</div>
            <div class="dato-sub">solo se l'assemblea approva</div>
        </td>
        <td style="width: 24%;">
            <div class="dato-et">Saldo se l'assemblea approva</div>
            <div class="dato-vl">{{ $f['non_disponibile'] ? P::NON_CALCOLATO : P::conIlVerbo($t['saldo']) }}</div>
            @if(! $f['non_disponibile'] && $t['non_emesse'] !== 0)<div class="dato-sub">quando saranno chieste le rate che mancano ({{ $euroNota($t['non_emesse']) }}): {{ P::conIlVerbo($t['saldo'] + $t['non_emesse']) }}</div>@endif
        </td>
    </tr>
</table>

<div class="chiusura">
    <b>Come leggere questo foglio.</b> {{ P::PROPOSTA }} {{ C::FRASE_SEGNO }}
    Le spese dell'esercizio sono la sua parte delle spese di tutto l'anno. Le quote dei piani sono le rate dei piani dell'esercizio, versate o no, comprese
    quelle non ancora chieste. La proposta di conguaglio è la differenza: con il segno meno le quote dei piani sono più delle sue spese, e la differenza è a
    suo credito; con il segno più sono meno, e la differenza è da versare. Già da versare sono le rate già chieste e non pagate, e le altre somme fuori dalle
    rate. {{ C::FRASE_GIORNI }}
</div>

@foreach($f['gestioni'] as $g)
@php $sezione++; @endphp
<h2><span class="sec">§ {{ $sezione }}</span> {{ $g['nome'] }}</h2>
@foreach($g['unita'] as $u)
<table class="p" style="margin-top: {{ $loop->first ? 0 : 10 }}px;">
    <thead>
        <tr>
            <th class="unita" style="width: 40%;">{{ $u['unita'] }}@if($u['giorni'] !== null) <span class="d">({{ $u['giorni'] }} giorni nell'esercizio)</span>@endif</th>
            <th style="width: 22%;">Tabella</th>
            <th class="n" style="width: 22%;">Millesimi</th>
            <th class="n" style="width: 16%;">Importo</th>
        </tr>
    </thead>
    <tbody>
        @foreach($u['voci'] as $v)
            {{-- Una cifra con il meno (un'entrata del condominio, un rimborso) riduce le spese: lo dice accanto. --}}
            <tr><td>{{ $v['voce'] }}</td><td>{{ $v['tabella'] }}</td><td class="n">{{ $v['millesimi'] ?? '' }}</td><td class="nn">{{ $euro($v['importo']) }}@if($v['importo'] < 0) <br><span class="d">(riduce le spese)</span>@endif</td></tr>
        @endforeach
        {{-- La spesa con la competenza scritta sulla fattura: a chi era titolare in quei giorni, anche zero a chi non lo era. --}}
        @foreach($u['competenze_scritte'] ?? [] as $cs)
            <tr class="di"><td class="ri" colspan="3">di cui {{ $cs['voce'] ?? 'una voce' }}, {{ C::giorni($cs['dal'], $cs['al'], (int) ($cs['spese'] ?? 1)) }}: {{ C::comeScritto($cs) }}</td><td class="n">{{ $euro($cs['importo']) }}</td></tr>
        @endforeach
        @if($u['voci'] === [])
            <tr><td colspan="4" class="d">{{ ($g['non_divisa'] ?? false) ? 'La spesa di questa gestione non è ancora divisa: il conguaglio non è ancora calcolato.' : 'Nessuna spesa dell\'esercizio su questa unità.' }}</td></tr>
        @endif
        @if($u['gia_versato_unita'] !== 0)
            <tr><td colspan="3">Già versato dell'unità prima delle rate, tolto dalle voci: già compreso negli importi qui sopra</td><td class="n">{{ $euro(-$u['gia_versato_unita']) }}</td></tr>
        @endif
        <tr class="k"><td colspan="3">Spese dell'esercizio</td><td class="n">{{ ($g['non_divisa'] ?? false) ? 'non ancora divisa' : $euro($u['a']) }}</td></tr>
        <tr><td class="e" colspan="3">+ Spese personali non ancora chieste @foreach($u['personali_righe'] as $p) <span class="d">(del {{ \Carbon\Carbon::parse($p['giorno'])->format('d/m/Y') }}: {{ $euroNota($p['importo']) }})</span>@endforeach</td><td class="e n">{{ $euro($u['personali']) }}</td></tr>
        <tr><td class="e" colspan="3">− Quote dei piani dell'esercizio @if((int) ($u['b']['di_cui_non_emesse'] ?? 0) !== 0) <span class="d">(di cui non ancora emesse {{ $euroNota($u['b']['di_cui_non_emesse']) }})</span>@endif</td><td class="e n">{{ $euro($u['b']['totale']) }}</td></tr>
        @if((int) ($u['b']['coppie'] ?? 0) + (int) ($u['b']['regolato'] ?? 0) !== 0)
            <tr class="di"><td class="ri" colspan="3">di cui la parte del passaggio dell'unità</td><td class="n">{{ $euro((int) $u['b']['coppie'] + (int) $u['b']['regolato']) }}</td></tr>
        @endif
        @if((int) ($u['b']['gia_versato_persona'] ?? 0) !== 0)
            <tr class="di"><td class="ri" colspan="3">di cui già versato prima delle rate</td><td class="n">{{ $euro((int) $u['b']['gia_versato_persona']) }}</td></tr>
        @endif
    </tbody>
</table>
{{-- Il conguaglio e il saldo dell'unità stanno insieme, in una tabella che non si spezza: l'ultima riga non va mai da sola sulla pagina dopo. --}}
<table class="p" style="page-break-inside: avoid;" autosize="1">
    <tbody>
        <tr class="k"><td style="width: 84%;">= Proposta di conguaglio, da approvare</td><td class="n" style="width: 16%;">{{ $segno($u['conguaglio']) }}</td></tr>
        <tr><td class="e">Già da versare: rate già chieste e non pagate</td><td class="e n">{{ $euro($u['posizione']) }}</td></tr>
        @if($u['fuori'] !== 0)
            <tr><td class="e">Già da versare: altre somme fuori dalle rate</td><td class="e n">{{ $euro($u['fuori']) }}</td></tr>
        @endif
        <tr class="k"><td>= Saldo se l'assemblea approva</td><td class="n">{{ $segno($u['saldo']) }}</td></tr>
        {{-- Le rate dei piani non ancora chieste sono nelle quote e non in quello che è già da versare: chieste, il saldo cresce di loro. --}}
        @if($u['non_emesse'] !== 0 && $u['saldo'] !== null)
            <tr><td class="e">Rate dei piani non ancora chieste: quando saranno chieste, il saldo se l'assemblea approva diventa {{ P::conIlVerbo($u['saldo'] + $u['non_emesse']) }}</td><td class="e n">{{ $euro($u['non_emesse']) }}</td></tr>
        @endif
        @if($u['non_disponibile'])
            <tr><td class="e d" colspan="2">{{ P::UNITA_NON_CALCOLATA }}</td></tr>
        @endif
    </tbody>
</table>
@endforeach
@endforeach

@if($f['da_altre_gestioni'] !== [])
@php $sezione++; @endphp
<h2><span class="sec">§ {{ $sezione }}</span> {{ C::NOME_DA_ALTRE }}</h2>
<table class="p">
    <thead><tr><th style="width: 40%;">Unità</th><th class="n">Rate ancora aperte</th><th class="n">Spese personali non chieste</th><th class="n">Saldo</th></tr></thead>
    <tbody>
        @foreach($f['da_altre_gestioni'] as $a)
            <tr><td>{{ $a['unita'] }}</td><td class="n">{{ $euro($a['posizione']) }}</td><td class="n">{{ $euro($a['personali']) }}</td><td class="n">{{ $segno($a['saldo']) }}</td></tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Le spese che il conguaglio non divide ancora, del condominio: quando saranno divise il conguaglio di ognuno può cambiare. --}}
@if($nd['non_divise'] !== 0)
<p class="eq">Le spese dell'esercizio divise fra i condòmini sono {{ $euro($nd['divise']) }}. Altre {{ $euro($nd['non_divise']) }} ({{ implode(', ', $nd['cosa']) }}) non sono ancora divise: quando lo saranno, il conguaglio può cambiare.</p>
@endif

{{-- In tutto, solo con più unità: con una sola le cifre sono quelle della sua tabella. --}}
@if($righeDiUnita > 1)
@php $sezione++; @endphp
<table class="p" style="page-break-inside: avoid;" autosize="1">
    <tbody>
        <tr><td class="titolo" colspan="2"><span class="sec-cella">§ {{ $sezione }}</span> In tutto</td></tr>
        <tr><td style="width: 70%;">Già da versare, in tutto</td><td class="n" style="width: 30%;">{{ $euro($t['gia']) }}</td></tr>
        <tr class="k"><td>Proposta di conguaglio, in tutto (da approvare)</td><td class="n">{{ $f['non_disponibile'] ? P::NON_CALCOLATO : $segno($t['conguaglio']) }}</td></tr>
        <tr class="tot"><td>Saldo se l'assemblea approva, in tutto</td><td class="nn">{{ $f['non_disponibile'] ? P::NON_CALCOLATO : $segno($t['saldo']) }} @if(! $f['non_disponibile'] && $t['non_emesse'] !== 0) <br><span class="d">quando saranno chieste le rate che mancano ({{ $euroNota($t['non_emesse']) }}): {{ P::conIlVerbo($t['saldo'] + $t['non_emesse']) }}</span> @endif</td></tr>
    </tbody>
</table>
@endif

{{-- Le righe che spiegano stanno insieme, con il loro titolo: una tabella sola che non si spezza. --}}
@php $sezione++; @endphp
<table class="p" style="page-break-inside: avoid;" autosize="1">
    <tbody>
        <tr><td class="titolo" colspan="2"><span class="sec-cella">§ {{ $sezione }}</span> Le righe che spiegano</td></tr>
        <tr><td class="d" colspan="2" style="border-bottom: 0; padding-left: 0;">Non sono termini del conguaglio: dicono da dove vengono le cifre.</td></tr>
        @if($sp['anno_prima'] !== 0)<tr><td style="width: 84%;">Saldo dall'esercizio prima, scritto all'inizio di questo esercizio</td><td class="n" style="width: 16%;">{{ $euro($sp['anno_prima']) }}</td></tr>@endif
        <tr><td style="width: 84%;">Versato in cassa nell'esercizio</td><td class="n" style="width: 16%;">{{ $euro($sp['versato_in_cassa']) }}</td></tr>
        @if($sp['usato_un_credito'] !== 0)<tr><td>Pagato usando un credito</td><td class="n">{{ $euro($sp['usato_un_credito']) }}</td></tr>@endif
        @if($sp['gia_versato_prima'] !== 0)<tr><td>Già versato prima delle rate, tolto dalle quote o dalle spese</td><td class="n">{{ $euro($sp['gia_versato_prima']) }}</td></tr>@endif
        {{-- La stessa cifra che sta accanto al saldo: le quote e le spese personali chieste con le rate non ancora emesse. --}}
        @if($sp['non_emesse'] !== 0)<tr><td>Rate dei piani dell'esercizio non ancora chieste: sono nelle quote, non in quello che è già da versare</td><td class="n">{{ $euro($sp['non_emesse']) }}</td></tr>@endif
        @if($sp['non_emesse'] !== 0 && ($sp['non_emesse_personali'] ?? 0) !== 0)<tr class="di"><td class="ri">di cui spese personali chieste con le rate</td><td class="n">{{ $euro($sp['non_emesse_personali']) }}</td></tr>@endif
        @if($sp['gia_versato_non_usato'] !== 0)<tr><td>Già versato registrato per questo esercizio, che le sue rate non hanno usato: non è nel conguaglio</td><td class="n">{{ $euro($sp['gia_versato_non_usato']) }}</td></tr>@endif
        @if(($sp['gia_versato_non_usato_tutti'] ?? 0) !== 0)<tr><td>Già versato registrato per tutti gli esercizi, che le rate di questo non hanno usato: un altro esercizio può usarlo, e non è nel conguaglio</td><td class="n">{{ $euro($sp['gia_versato_non_usato_tutti']) }}</td></tr>@endif
        {{-- La firma dell'amministratore, come nell'estratto conto (il foglio va alla persona), nella stessa tabella che non si spezza: non va mai da sola
             sulla pagina dopo. --}}
        @if(! empty($firma_stampe_absolute_path))
            <tr><td colspan="2" style="border: 0; padding: 18px 0 4px 0; text-align: right; font-size: 7pt; letter-spacing: 1.1pt; text-transform: uppercase; color: #5A6B7B;">L'amministratore</td></tr>
            <tr><td colspan="2" style="border: 0; padding: 0; text-align: right;"><img src="{{ $firma_stampe_absolute_path }}" style="max-width: 150px; max-height: 56px;"></td></tr>
        @endif
    </tbody>
</table>

</body>
</html>
