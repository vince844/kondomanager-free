{{--
  Il piede delle stampe col carattere Inter: «Documento emesso il …», la nota legale, a destra il numero di pagina (nel fascicolo con il nome della
  parte). `$giorno`: il giorno dell'emissione, già scritto; `$parte`: il nome della parte del fascicolo, o null.
  Nel fascicolo la colonna destra sta in basso e sotto il nome della parte lascia una riga libera, l'ultima del piede: lì il fascicolo scrive il suo
  numero di pagina quando unisce le parti (`FascicoloDelRendiconto::scriviIlNumero`). Il fascicolo non sa dove finisce la colonna, e scrive il numero
  dal margine destro: la colonna è larga 45 mm (il numero più lungo, «Pagina 999 di 999 del fascicolo», a 7 punti ne misura 37) e non va a capo, così
  la nota legale, anche se lunga, non finisce sotto il numero e il nome della parte sta su una riga. Fuori dal fascicolo il piede è quello di sempre.
--}}
<table style="width: 100%; border-collapse: collapse; border-top: 1px solid #D9DEE5;">
    <tr>
        <td style="border: 0; padding: 5px 0 0 0; font-size: 7pt; color: #5A6B7B;">
            Documento emesso il {{ $giorno }}
            @if(!empty($nota_legale_stampe))
                <br>{!! nl2br(e($nota_legale_stampe)) !!}
            @endif
        </td>
        @if(! empty($parte))
        <td style="border: 0; padding: 5px 0 0 0; width: 45mm; white-space: nowrap; text-align: right; vertical-align: bottom; font-size: 7pt; color: #5A6B7B;">{{ $parte }}, pagina {PAGENO} di {nbpg}<br>&nbsp;</td>
        @else
        <td style="border: 0; padding: 5px 0 0 0; text-align: right; font-size: 7pt; color: #5A6B7B;">Pagina {PAGENO} di {nbpg}</td>
        @endif
    </tr>
</table>
