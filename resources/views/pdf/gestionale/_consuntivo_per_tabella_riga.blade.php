{{-- Una riga del consuntivo per tabella, un'unità e un soggetto: la usano i blocchi di righe e la tabella in fondo. --}}
<tr>
    <td>{{ $r['unita'] }}</td>
    {{-- I giorni di chi ha avuto l'unità per una parte dell'anno; con più ruoli, quelli di ogni ruolo con il ruolo. --}}
    <td>{{ $r['nome'] }}@if(($giorniDellaRiga = \App\Services\Gestionale\ParoleDelConguaglio::giorni($r['giorni'], $r['giorni_per_ruolo'] ?? [])) !== null) <span class="d">({{ $giorniDellaRiga }})</span>@endif</td>
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
