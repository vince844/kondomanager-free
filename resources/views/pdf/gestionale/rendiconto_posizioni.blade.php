{{--
  Le posizioni dei condòmini (§ 9 del rendiconto): una riga per persona e unità, dall'array di `PosizioniDeiCondomini::posizioni()`, lo stesso della
  pagina.

  La carta del riepilogo finanziario (§ 7-quinquies di docs/registri_contabili.md: Inter e Fraunces, la palette del fac-simile), in orizzontale, con
  lo stile e la testa comuni delle stampe del rendiconto (`_rendiconto_stile`, `_rendiconto_testa`). Niente firma: è una parte del fascicolo.

  La tabella a blocchi di 100 righe con le celle a classi, come «Rate scadute»: mPDF tiene in memoria la tabella intera (le misure accanto al tetto,
  in `RendicontoCondominiController`).

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    // Il meno come nel resto del fascicolo: l'euro prima, «€ -50,00».
    $euro = fn (int $c) => '€ ' . number_format($c / 100, 2, ',', '.');
    $cella = fn (int $c) => $c === 0 ? '' : $euro($c);
    $p = $posizioni;
    $m = $p['mostra'];
    $titoloStampa = 'Posizioni dei condòmini';
    $colonne = array_values(array_filter([
        ['nome', 'Persona', 'left', 20], ['unita', 'Unità', 'left', 9], ['ordinarie', 'Ordinarie dell\'esercizio', 'right', 10], ['straordinarie', 'Straordinarie dell\'esercizio', 'right', 10],
        ['precedenti', 'Esercizi precedenti', 'right', 10], $m['successivi'] ? ['successivi', 'Esercizi successivi', 'right', 9] : null,
        $m['non_emesse'] ? ['non_emesse', 'Su rate non emesse', 'right', 9] : null, ['crediti', 'Crediti sulle rate', 'right', 9], ['totale', 'Totale', 'right', 10],
        $m['anticipi'] ? ['anticipi', 'Anticipi', 'right', 9] : null,
    ]));
    $blocchi = count($p['righe']) === 0 ? [[]] : array_chunk($p['righe'], 100);
    $rc = $p['riga_crediti'];
    $ra = $p['riga_anticipi'];
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

@include('pdf.gestionale._rendiconto_testa', ['alla' => $p['alla']])

<h1>{{ $titoloStampa }}</h1>
<p class="sotto">{{ ucfirst($p['avvertenze']['data']) }}</p>

<p class="eq" style="margin: -4px 0 6px;">Totale delle posizioni, senza gli anticipi: <b>{{ $euro($p['totali']['totale']) }}</b></p>

<div class="chiusura">
    <b>Come leggere questo prospetto.</b> {{ $p['avvertenze']['segno'] }} Le posizioni sono {{ $p['avvertenze']['data'] }}. {{ $p['avvertenze']['ordine'] }} {{ $p['avvertenze']['esercizio_della_rata'] }}
    I crediti sulle rate (un versamento oltre il dovuto di una rata) hanno il segno meno. {{ $p['avvertenze']['anticipi'] }}
</div>

<h2><span class="sec">§ 1</span> Le posizioni</h2>
@foreach($blocchi as $blocco)
@if(! $loop->first)<pagebreak />@endif
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
            <tr>
                @foreach($colonne as [$chiave, $etichetta, $allinea])
                    @if($chiave === 'nome')
                        <td class="c">{{ $r['nome'] }}</td>
                    @elseif($chiave === 'unita')
                        <td class="c">{{ $r['unita'] ?? '—' }}@if($r['pregresso_nella_rata']) <span class="d">· contiene anche una parte di pregresso</span> @endif</td>
                    @else
                        <td class="cn">{{ $cella($r[$chiave]) }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
        @if(count($p['righe']) === 0)
            <tr><td colspan="{{ count($colonne) }}" class="d" style="padding: 10px; text-align: center;">Nessuna posizione diversa da zero a questa data.</td></tr>
        @endif
        @if($loop->last)
            <tr class="tot">
                <td colspan="2">Totale</td>
                @foreach(array_slice($colonne, 2) as [$chiave])
                    <td class="n">{{ $euro($p['totali'][$chiave]) }}</td>
                @endforeach
            </tr>
        @endif
    </tbody>
</table>
@endforeach

<h2><span class="sec">§ 2</span> Con la situazione patrimoniale</h2>
<p class="eq">Crediti verso i condòmini della situazione patrimoniale <b>{{ $euro($rc['crediti']) }}</b> = posizioni senza gli anticipi <b>{{ $euro($rc['posizioni']) }}</b>
    + spese anticipate per singole unità, non ancora chieste <b>{{ $euro($rc['spese_anticipate']) }}</b>@if($rc['differenza'] !== 0) — differenza <b>{{ $euro($rc['differenza']) }}</b> @endif.</p>
<p class="eq">Anticipi ricevuti dai condòmini <b>{{ $euro($ra['anticipi']) }}</b> = conto «Anticipi ricevuti dai condòmini» <b>{{ $euro($ra['conto']) }}</b>@if($ra['differenza'] !== 0) — differenza <b>{{ $euro($ra['differenza']) }}</b> @endif.</p>

<p class="riservato">{{ $p['avvertenze']['riservato'] }}</p>

</body>
</html>
