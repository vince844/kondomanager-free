{{-- Una riga del consuntivo per tabella, un'unità e un soggetto: la usano i blocchi di righe e la tabella in fondo. --}}
<tr>
    <td>{{ $r['unita'] }}</td>
    <td>{{ $r['nome'] }}@if($r['giorni'] !== null) <span class="d">({{ $r['giorni'] }} giorni)</span>@endif</td>
    <td style="text-align: center;">{{ $r['ruolo'] }}</td>
    @foreach($tabelleBlocco as $tid => $t)
        <td class="n ml mill">{{ $mill($r['tabelle'][$tid]['quota'] ?? null) }}</td>
        <td class="n">{{ $cella($r['tabelle'][$tid]['importo'] ?? 0) }}</td>
    @endforeach
    @if($ultimoBlocco)
        @if($conVersato)<td class="n ml">{{ $cella($r['gia_versato']) }}</td>@endif
        <td class="n ml"><b>{{ $euro($r['totale']) }}</b></td>
    @endif
</tr>
