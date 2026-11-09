{{--
  La testata della prima pagina con lo studio delle stampe col carattere Inter (il registro di contabilità, il mastrino, il libro mastro, la
  situazione patrimoniale, il riepilogo finanziario), al posto della loro tabella di testa: a sinistra il logo accanto allo studio con tutti i suoi
  dati, a destra il condominio con l'indirizzo e il C.F.; sotto, in una riga, il nome della stampa (`$att`) e quello che senza lo studio sta nella
  colonna di destra (`$slot`: l'esercizio, il periodo, «Stampato il»). Una stampa che comincia con la riga dello studio (le parti del fascicolo
  dopo il frontespizio) ha la sola riga sotto la testatina. Le due tabelle del blocco hanno `overflow: wrap`: una parola più larga della colonna
  dello studio va a capo dove la colonna finisce, e la colonna del condominio non si stringe. Con la carta intestata (le stampe verticali) lo studio è
  stampato sulla carta: al posto del blocco, il condominio in una riga.
--}}
@if($carta->primaPaginaIntera() && $carta->cartaIntestata() !== null)
{{-- Con la carta intestata lo studio è stampato sulla carta: il condominio in una riga, in cima alla parte libera. --}}
<table style="width: 100%; border-collapse: collapse; overflow: wrap;">
    <tr>
        <td style="vertical-align: bottom; padding: 0 0 6px 0; border: 0; font-size: 7.5pt; color: #5A6B7B;">
            <span style="font-family: fraunces; font-size: 12pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#0E2238') }};">{{ $condominio->nome }}</span>@if(filled($condominio->indirizzo)) · {{ $condominio->indirizzo }}@endif @if(filled($condominio->codice_fiscale)) · C.F. {{ $condominio->codice_fiscale }}@endif
        </td>
    </tr>
</table>
@elseif($carta->primaPaginaIntera())
<table style="width: 100%; border-collapse: collapse; overflow: wrap;">
    <tr>
        <td style="width: 58%; vertical-align: top; padding: 0 4mm 8px 0; border: 0;">
            <table style="border-collapse: collapse; overflow: wrap;">
                <tr>
                    @if($carta->logo())
                        <td style="vertical-align: top; padding: 0 3mm 0 0; border: 0;"><img src="{{ $carta->logo() }}" style="max-height: 14mm; max-width: 40mm;"></td>
                    @endif
                    <td style="vertical-align: top; padding: 0; border: 0;">
                        <div style="font-family: fraunces; font-size: 11pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#0E2238') }};">{{ $carta->nome() }}</div>
                        @foreach($carta->righe() as $riga)
                            <div style="font-size: 7pt; color: #5A6B7B;">{{ $riga }}</div>
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 42%; vertical-align: top; text-align: right; padding: 0 0 8px 0; border: 0;">
            <div style="font-family: fraunces; font-size: 12pt; color: {{ \App\Services\PDF\Carta::coloreDi($carta, '#0E2238') }};">{{ $condominio->nome }}</div>
            @if(filled($condominio->indirizzo))<div style="font-size: 7.5pt; color: #5A6B7B;">{{ $condominio->indirizzo }}</div>@endif
            @if(filled($condominio->codice_fiscale))<div style="font-size: 7.5pt; color: #5A6B7B;">C.F. {{ $condominio->codice_fiscale }}</div>@endif
        </td>
    </tr>
</table>
@endif
{{-- Il filetto sta sopra la riga (sotto lo studio e il condominio) o, nelle parti del fascicolo, sotto: sulla tabella del blocco mPDF non lo
     disegna, perché dentro c'è un'altra tabella. --}}
<table style="width: 100%; border-collapse: collapse; @if($carta->primaPaginaIntera())border-top: 2px solid {{ \App\Services\PDF\Carta::coloreDi($carta, '#0E2238') }};@else border-bottom: 2px solid {{ \App\Services\PDF\Carta::coloreDi($carta, '#0E2238') }};@endif">
    <tr>
        <td class="doc" style="text-align: left; padding: 5px 0 {{ $carta->primaPaginaIntera() ? '0' : '6px' }} 0; border: 0;">
            <span style="font-size: 7pt; letter-spacing: 1.1pt; text-transform: uppercase;">{{ $att }}</span> · {{ $slot }}
        </td>
    </tr>
</table>
