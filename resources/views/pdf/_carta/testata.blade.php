{{--
  La testata della prima pagina con lo studio (le stampe di `pdf.base`): a sinistra il logo accanto allo studio con tutti i suoi dati, a destra il
  condominio con l'indirizzo e il C.F.; sotto le due colonne il titolo della stampa, se c'è. Il logo è alto al massimo 14 mm e largo al massimo
  40 mm: accanto al nome, non sopra, la testata resta bassa. L'altezza la misura mPDF (il margine alto si allunga sulla testata): il filetto è
  l'ultima riga, e sotto c'è solo lo spazio prima del corpo. Le due tabelle hanno `overflow: wrap`: una parola più larga della colonna dello studio
  (una PEC, un sito) va a capo dove la colonna finisce, e la colonna del condominio non si stringe.
--}}
<table style="width: 100%; border-collapse: collapse; overflow: wrap;">
    <tr>
        <td style="width: 58%; vertical-align: top; padding: 0 4mm 0 0;">
            <table style="border-collapse: collapse; overflow: wrap;">
                <tr>
                    @if($carta->logo())
                        <td style="vertical-align: top; padding: 0 3mm 0 0;"><img src="{{ $carta->logo() }}" style="max-height: 14mm; max-width: 40mm;"></td>
                    @endif
                    <td style="vertical-align: top; padding: 0;">
                        <div style="font-size: 11pt; font-weight: bold; color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#2c3e50') }}; line-height: 1.2;">{{ $carta->nome() }}</div>
                        @foreach($carta->righe() as $riga)
                            <div style="font-size: 7.5pt; color: #555; line-height: 1.3;">{{ $riga }}</div>
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 42%; vertical-align: top; text-align: right; padding: 0;">
            @isset($condominio)
                <div style="font-size: 12pt; font-weight: bold; color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#2c3e50') }}; line-height: 1.2;">{{ $condominio->nome }}</div>
                @if(filled($condominio->indirizzo))<div style="font-size: 8.5pt; color: #555; line-height: 1.3;">{{ $condominio->indirizzo }}</div>@endif
                @if(filled($condominio->codice_fiscale))<div style="font-size: 8.5pt; color: #555; line-height: 1.3;">C.F. {{ $condominio->codice_fiscale }}</div>@endif
            @endisset
        </td>
    </tr>
</table>
@if(filled($titolo ?? null))
    <div style="font-size: 8pt; color: #667; margin-top: 1.5mm;">{{ $titolo }}</div>
@endif
<div style="border-bottom: 2px solid {{ \App\Services\PDF\Carta::coloreDi($carta, '#2c3e50') }}; height: 1.5mm; font-size: 1pt;"></div>
<div style="height: 1.5mm; font-size: 1pt;"></div>
