{{-- L'intestazione del consuntivo per tabella, su due righe: le tabelle del blocco con i loro nomi, e sotto i millesimi (o la percentuale) e gli euro.
     La usano i blocchi di righe e la tabella in fondo, quando è la sola. --}}
<tr>
    <th class="s" rowspan="2" style="width: {{ $wUnita }}%;">Unità</th>
    <th class="s" rowspan="2" style="width: {{ $wNome }}%;">Soggetto</th>
    <th class="centro" rowspan="2" style="width: {{ $wRuolo }}%;">Ruolo</th>
    @foreach($tabelleBlocco as $t)
        <th class="tab" colspan="2" style="width: {{ $wTab }}%;">{{ \Illuminate\Support\Str::limit($t['nome'], $nTabB > 6 ? 12 : 22) }}</th>
    @endforeach
    @if($ultimoBlocco)
        @if($conVersato)<th class="ml" rowspan="2" style="width: 7%;">Già versato</th>@endif
        <th class="ml" rowspan="2" style="width: 8%;">Totale</th>
    @endif
</tr>
<tr>
    @foreach($tabelleBlocco as $t)
        <th class="ml" style="width: {{ $wTab * 0.4 }}%;">{{ $t['quota'] === 'percentuale' ? '%' : $etichettaQuota }}</th>
        <th style="width: {{ $wTab * 0.6 }}%;">Euro</th>
    @endforeach
</tr>
