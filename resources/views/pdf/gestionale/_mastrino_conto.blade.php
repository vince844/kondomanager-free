{{--
  Il blocco di un conto: titolo, nota di lettura, avvisi, striscia dei dati e tabella. È incluso
  dalla stampa del singolo mastrino (mastrino.blade.php) e, una volta per conto, dal libro mastro
  (libro_mastro.blade.php): stessa forma in tutti e due i fogli, per costruzione.

  Variabili attese: $conto, $periodo, $righe, $riporto, $riporto_al, $totale_righe, $totale_dare,
  $totale_avere, $saldo_finale, $saldo_alla_data, $data_riferimento, $altri_esercizi,
  $riporto_proprie, $postdatate, $apertura_non_registrata, $parziale, più $con_nota (la nota «Come
  leggere» sta una volta sola nel libro mastro, in testa).
--}}
@php
    $euro = fn (int $c) => '€ '.number_format($c / 100, 2, ',', '.');
    $dal = \Carbon\Carbon::parse($periodo['dal'])->format('d/m/Y');
    $al = \Carbon\Carbon::parse($periodo['al'])->format('d/m/Y');
    $giornoPrima = \Carbon\Carbon::parse($riporto_al)->format('d/m/Y');
    $futuro = $periodo['stato'] === 'futuro';
@endphp
<h1>{{ $conto['nome'] }} <span style="font-size: 10pt; color: #5A6B7B; font-family: inter; font-weight: normal;">conto {{ $conto['codice'] }}</span></h1>
<p class="sotto">Mastrino del conto — dare, avere e saldo progressivo @if($futuro)fotografia al {{ $al }}: l'esercizio comincia il {{ $dal }} @else dal {{ $dal }} al {{ $al }} @endif</p>

@if($con_nota ?? true)
<div class="chiusura">
    <b>Come leggere questo mastrino.</b>
    Contiene le righe di partita doppia registrate su questo conto con data nel periodo, in ordine
    di data @if($parziale)— quelle che passano i filtri elencati sopra @endif. <b>Il saldo parte dal
    riporto</b>, cioè da quanto c'era sul conto il {{ $giornoPrima }}, e l'ultimo saldo progressivo
    del periodo è quello della situazione patrimoniale al {{ $al }}. Il saldo è nel verso naturale
    del conto, {{ $conto['natura_dare'] ? 'dare meno avere' : 'avere meno dare' }}:
    un importo negativo è contro natura{{ $conto['natura_dare'] ? ' (per una cassa, un conto sotto zero)' : ' (per un fornitore, un credito verso di lui)' }}.
    <b>Le operazioni stornate restano</b>, marcate come tali: una cancellazione che nasconde il
    cancellato sarebbe contraria all'art. 2219 c.c.
</div>
@endif

@if($apertura_non_registrata !== 0)
<div class="avviso">
    <b>Saldo di apertura non ancora registrato a giornale: {{ $euro($apertura_non_registrata) }}.</b>
    Non è una riga di questo mastrino e non è compreso nel saldo: la situazione patrimoniale lo
    conta a parte, e il controllo di quadratura lo segnala. Si registra dal Libro Giornale.
</div>
@endif

@if($altri_esercizi > 0)
<div class="avviso">
    {{ $altri_esercizi }} {{ $altri_esercizi === 1 ? 'riga appartiene' : 'righe appartengono' }} a un altro esercizio ma {{ $altri_esercizi === 1 ? 'porta' : 'portano' }} una data dentro questo periodo:
    {{ $altri_esercizi === 1 ? 'è marcata' : 'sono marcate' }} con il nome del suo esercizio. È la stessa anomalia che il controllo «liquidità e riepilogo» dello Stato patrimoniale segnala.
</div>
@endif

@if($riporto_proprie > 0)
<div class="avviso">
    {{ $riporto_proprie }} {{ $riporto_proprie === 1 ? 'riga di questo esercizio è datata' : 'righe di questo esercizio sono datate' }} prima del {{ $dal }} e {{ $riporto_proprie === 1 ? 'sta' : 'stanno' }} nel riporto, non fra le righe del periodo: il riporto non contiene solo gli esercizi precedenti.
</div>
@endif

@if($postdatate > 0)
<div class="avviso">
    {{ $postdatate }} {{ $postdatate === 1 ? 'movimento datato' : 'movimenti datati' }} dopo il {{ $al }} non {{ $postdatate === 1 ? 'compare' : 'compaiono' }} in questo mastrino, che si ferma a oggi.@if($futuro) Fra oggi e l'inizio dell'esercizio non {{ $postdatate === 1 ? 'è' : 'sono' }} né riporto né righe. @endif
</div>
@endif

<table class="dati">
    <tr>
        <td style="width: 16%;">
            <div class="et">Movimenti</div>
            <div class="vl">{{ number_format(count($righe), 0, ',', '.') }}</div>
        </td>
        <td style="width: 21%;">
            <div class="et">Riporto al {{ $giornoPrima }}</div>
            <div class="vl {{ $riporto < 0 ? 'neg' : '' }}">{{ $euro($riporto) }}</div>
        </td>
        <td style="width: 21%;">
            <div class="et">Totale dare{{ $parziale ? ' (parziale)' : '' }}</div>
            <div class="vl">{{ $euro($totale_dare) }}</div>
        </td>
        <td style="width: 21%;">
            <div class="et">Totale avere{{ $parziale ? ' (parziale)' : '' }}</div>
            <div class="vl">{{ $euro($totale_avere) }}</div>
        </td>
        <td style="width: 21%;">
            <div class="et">Saldo @if($data_riferimento)al {{ \Carbon\Carbon::parse($data_riferimento)->format('d/m/Y') }} @endif</div>
            <div class="vl {{ $saldo_alla_data < 0 ? 'neg' : '' }}">{{ $euro($saldo_alla_data) }}</div>
            {{-- Con un filtro attivo il saldo dichiarato è alla data dell'ultima riga mostrata; il
                 foglio porta anche quello del periodo intero, come la card a schermo. --}}
            @if($parziale)<div class="d">Senza filtri: {{ $euro($saldo_finale) }} al {{ $al }}</div>@endif
        </td>
    </tr>
</table>

@foreach(array_chunk($righe, 300) as $blocco)
@if(! $loop->first)<pagebreak />@endif
<table class="registro">
    <thead>
        <tr>
            <th class="n" style="width: 38px;">N.</th>
            <th style="width: 62px;">Data</th>
            <th style="width: 78px;">Protocollo</th>
            <th>Descrizione</th>
            <th style="width: 128px;">Controparte</th>
            <th class="n" style="width: 72px;">Dare</th>
            <th class="n" style="width: 72px;">Avere</th>
            <th class="n" style="width: 78px;">Saldo</th>
        </tr>
    </thead>
    <tbody>
        @if($loop->first)
        <tr class="riporto">
            <td class="num"></td>
            <td style="white-space: nowrap;">{{ $giornoPrima }}</td>
            <td></td>
            <td colspan="2">{{ $futuro ? "Riporto — saldo del conto a oggi: l'esercizio non è ancora cominciato" : "Riporto — saldo del conto all'inizio del periodo" }}</td>
            <td class="n"></td>
            <td class="n"></td>
            <td class="n {{ $riporto < 0 ? 'neg' : '' }}">{{ $euro($riporto) }}</td>
        </tr>
        @endif
        @foreach($blocco as $riga)
        <tr>
            <td class="num">{{ $riga['numero'] }}</td>
            <td style="white-space: nowrap;">{{ \Carbon\Carbon::parse($riga['data'])->format('d/m/Y') }}</td>
            <td class="d">{{ $riga['protocollo'] }}</td>
            <td>
                {{ $riga['descrizione'] }}
                @if($riga['stornata'])
                    <span class="badge">stornata</span>
                @endif
                @if($riga['altro_esercizio'])
                    <span class="badge badge-es">{{ $riga['altro_esercizio'] }}</span>
                @endif
            </td>
            <td>{{ $riga['controparte'] ?? '—' }}</td>
            <td class="n">{{ $riga['dare'] !== null ? $euro($riga['dare']) : '' }}</td>
            <td class="n">{{ $riga['avere'] !== null ? $euro($riga['avere']) : '' }}</td>
            <td class="n {{ $riga['saldo_progressivo'] < 0 ? 'neg' : '' }}">{{ $euro($riga['saldo_progressivo']) }}</td>
        </tr>
        @endforeach
    </tbody>
    @if($loop->last)
    <tfoot>
        <tr>
            <td colspan="5" style="text-align: right;">{{ $parziale ? 'Totale dei movimenti riportati' : 'Totale del periodo' }}</td>
            <td class="n">{{ $euro($totale_dare) }}</td>
            <td class="n">{{ $euro($totale_avere) }}</td>
            {{-- Sotto «Saldo» niente: un saldo progressivo non si somma. È dichiarato una volta,
                 con la sua data, nella striscia dei dati in testa. --}}
            <td class="n"></td>
        </tr>
    </tfoot>
    @endif
</table>
@endforeach

@if(count($righe) === 0)
<table class="registro">
    {{-- Due stati: con un filtro senza esito il saldo del conto NON è il riporto — è quello
         dichiarato nella striscia in testa, alla sua data — e non si ripete qui con un altro
         numero. Revisione della beta.26. --}}
    @if($totale_righe > 0)
    <tr><td class="vuoto">Nessuna riga corrisponde ai filtri: nel periodo questo conto ha {{ $totale_righe }} {{ $totale_righe === 1 ? 'movimento' : 'movimenti' }}. Il saldo dichiarato in testa resta quello vero alla sua data.</td></tr>
    @else
    <tr><td class="vuoto">Nessun movimento su questo conto nel periodo. Il saldo resta quello del riporto: {{ $euro($riporto) }}.</td></tr>
    @endif
</table>
@endif

