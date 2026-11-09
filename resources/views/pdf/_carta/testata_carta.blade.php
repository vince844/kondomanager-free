{{--
  La testata delle stampe con la carta intestata (le stampe verticali di `pdf.base`), su ogni pagina: lo studio è stampato sulla carta, e il programma
  scrive il condominio in una riga in cima alla parte libera (dal margine alto della carta), con l'indirizzo e il C.F.; a destra il titolo della
  stampa, se c'è. Il margine alto si allunga sulla riga: il corpo comincia sotto.
--}}
<table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #c0ccd8; overflow: wrap;">
    <tr>
        <td style="font-size: 8.5pt; color: #555; padding: 0 0 1mm 0; vertical-align: bottom;">
            @isset($condominio)<b style="color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#2c3e50') }};">{{ $condominio->nome }}</b>@if(filled($condominio->indirizzo)) · {{ $condominio->indirizzo }}@endif @if(filled($condominio->codice_fiscale)) · C.F. {{ $condominio->codice_fiscale }}@endif @endisset
        </td>
        @if(filled($titolo ?? null))
            <td style="font-size: 7.5pt; color: #667; padding: 0 0 1mm 0; text-align: right; vertical-align: bottom;">{{ $titolo }}</td>
        @endif
    </tr>
</table>
<div style="height: 2mm; font-size: 1pt;"></div>
