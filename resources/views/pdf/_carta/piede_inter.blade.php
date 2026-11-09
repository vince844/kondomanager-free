{{--
  Il piede delle stampe col carattere Inter: «Documento emesso il …», la nota legale, a destra il numero di pagina (nel fascicolo con il nome della
  parte). `$giorno`: il giorno dell'emissione, già scritto; `$parte`: il nome della parte del fascicolo, o null.
--}}
<table style="width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5;">
    <tr>
        <td style="border: 0; padding: 5px 0 0 0; font-size: 7pt; color: #5A6B7B;">
            Documento emesso il {{ $giorno }}
            @if(!empty($nota_legale_stampe))
                <br>{!! nl2br(e($nota_legale_stampe)) !!}
            @endif
        </td>
        <td style="border: 0; padding: 5px 0 0 0; text-align: right; font-size: 7pt; color: #5A6B7B;">@if(! empty($parte)) {{ $parte }}, pagina {PAGENO} di {nbpg} @else Pagina {PAGENO} di {nbpg} @endif</td>
    </tr>
</table>
