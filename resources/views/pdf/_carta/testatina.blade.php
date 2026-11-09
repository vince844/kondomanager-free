{{--
  La testata delle pagine dopo la prima con lo studio (le stampe di `pdf.base`): una riga con lo studio, il condominio e il suo C.F., e a destra
  il titolo della stampa, se c'è. Sta nella fascia di oggi: il corpo comincia al margine della stampa.
--}}
<table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #c0ccd8;">
    <tr>
        <td style="font-size: 7.5pt; color: #555; padding: 0 0 1mm 0; vertical-align: bottom;">
            <b style="color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#2c3e50') }};">{{ $carta->nome() }}</b>@isset($condominio) · {{ $condominio->nome }}@if(filled($condominio->codice_fiscale)) · C.F. {{ $condominio->codice_fiscale }}@endif @endisset
        </td>
        @if(filled($titolo ?? null))
            <td style="font-size: 7.5pt; color: #667; padding: 0 0 1mm 0; text-align: right; vertical-align: bottom;">{{ $titolo }}</td>
        @endif
    </tr>
</table>
