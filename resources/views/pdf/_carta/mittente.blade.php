{{--
  Lo studio in alto a sinistra nelle lettere, nelle ricevute e negli avvisi di compensazione: il logo accanto al nome, e sotto, su tutta la
  larghezza della colonna, i suoi dati. Sotto lo studio, dopo uno spazio di 6 px, la vista scrive il condominio. Sta nella cella di sinistra,
  larga 112 mm meno il margine sinistro, che va a capo 8 mm prima del destinatario, a destra. Le tabelle della
  vista e questa hanno `overflow: wrap`: una parola più larga della colonna (un'email, una PEC, un sito) va a capo dove la colonna finisce, senza
  allargarla, e il testo del PDF resta quello scritto (chi copia l'indirizzo lo copia com'è).
--}}
<table style="border-collapse: collapse; overflow: wrap;">
    <tr>
        @if($carta->logo())
            <td style="vertical-align: middle; padding: 0 3mm 0 0;"><img src="{{ $carta->logo() }}" style="max-height: 14mm; max-width: 40mm;"></td>
        @endif
        <td style="vertical-align: middle; padding: 0;">
            <div style="font-size: 12pt; font-weight: bold; color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#1e3a5f') }}; line-height: 1.25;">{{ $carta->nome() }}</div>
        </td>
    </tr>
</table>
@if($carta->righe() !== [])
    <div style="font-size: 8pt; color: #333; margin-top: 2px; line-height: 1.35;">{!! implode('<br>', array_map(fn (string $riga) => e($riga), $carta->righe())) !!}</div>
@endif
{{-- Lo spazio prima del condominio: in una cella mPDF non conta il `margin-top` di un blocco, né l'altezza di un blocco vuoto; conta quella di una cella. --}}
<table style="border-collapse: collapse;"><tr><td style="height: 6px; padding: 0; font-size: 1pt;"></td></tr></table>
