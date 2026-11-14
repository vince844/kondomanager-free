{{--
  La testa comune delle stampe del rendiconto sulla carta del riepilogo e della situazione patrimoniale (le stesse di `_rendiconto_stile`): la
  testatina dalla seconda pagina (con lo studio, nelle parti del fascicolo, dalla prima), il piede con il nome della parte nel fascicolo e sotto la
  riga dove il fascicolo scrive il suo numero, e in testa alla prima pagina la testata dello studio, della carta intestata o del condominio.

  `$titoloStampa`: il nome della stampa, nella testata e nella testatina; `$alla`: il giorno della lettura (Y-m-d), nella testatina; `$chi`
  (facoltativo): al posto del nome della stampa nella testatina, per il foglio di una persona. Come le altre stampe: `$carta`, `$condominio`,
  `$esercizio`, `$parte_del_fascicolo`, `$data_emissione_stampe`.

  ⚠️ Blade: una direttiva attaccata a un carattere di parola NON viene compilata. Ogni `@if`/`@endif` qui ha uno spazio o un a-capo prima.
--}}
@php
    // Lo studio: il blocco in testa alla prima pagina e il suo nome nella testatina; senza, la testata del condominio. Con la carta intestata lo studio
    // è stampato sulla carta: la testata ha il condominio e la riga della stampa, la testatina il condominio.
    $conStudioInTesta = isset($carta) && $carta->haStudio();
    $conCartaInTesta = isset($carta) && $carta->cartaIntestata() !== null;
    $coloreInTesta = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#0E2238');
@endphp
<htmlpageheader name="rcTestatina">
    <table style="width: 100%; border-collapse: collapse; border-bottom: 1px solid #D9DEE5;">
        <tr>
            <td style="border: 0; padding: 0 0 5px 0; font-size: 7.5pt; color: #5A6B7B;">@if($conStudioInTesta){{ $carta->nome() }} · @endif<b style="color: {{ $coloreInTesta }};">{{ $condominio->nome }}</b> · {{ $chi ?? $titoloStampa }}</td>
            <td style="border: 0; padding: 0 0 5px 0; text-align: right; font-size: 7.5pt; color: #5A6B7B;">{{ $esercizio->nome }} · al {{ \Carbon\Carbon::parse($alla)->format('d/m/Y') }}</td>
        </tr>
    </table>
</htmlpageheader>
@if($conCartaInTesta && ! $carta->primaPaginaIntera())
{{-- La testatina e il piede li dà la regola `@page` dello stile. --}}
@elseif($conStudioInTesta && ! $carta->primaPaginaIntera())
{{-- Con lo studio, una stampa che comincia con la testatina (le parti del fascicolo) la ha già dalla prima pagina. --}}
<sethtmlpageheader name="rcTestatina" page="ALL" value="on" show-this-page="1" />
@else
<sethtmlpageheader name="rcTestatina" page="ALL" value="on" />
@endif

<htmlpagefooter name="rcPiede">
    @include('pdf._carta.piede_inter', ['giorno' => ($data_emissione_stampe ?? \App\Helpers\DateHelper::adessoUtente())->format('d/m/Y'), 'parte' => $parte_del_fascicolo ?? null])
</htmlpagefooter>
<sethtmlpagefooter name="rcPiede" page="ALL" value="on" />

@if($conStudioInTesta || $conCartaInTesta)
@component('pdf._carta.testata_inter', ['carta' => $carta, 'condominio' => $condominio, 'att' => $titoloStampa])
<b class="forte">{{ $esercizio->nome }}</b> · dal <b class="forte">{{ $esercizio->data_inizio?->format('d/m/Y') }}</b> al <b class="forte">{{ $esercizio->data_fine?->format('d/m/Y') }}</b> · Stampato il <b class="forte">{{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</b>
@endcomponent
@else
<table class="testata">
    <tr>
        <td>
            <div class="ente">{{ $condominio->nome }}</div>
            <div class="att">{{ $titoloStampa }}</div>
            <div class="rif">
                @if($condominio->indirizzo) {{ $condominio->indirizzo }}<br> @endif
                @if($condominio->codice_fiscale) C.F. {{ $condominio->codice_fiscale }} @endif
            </div>
        </td>
        <td class="doc">
            <b class="forte">{{ $esercizio->nome }}</b><br>
            dal <b class="forte">{{ $esercizio->data_inizio?->format('d/m/Y') }}</b> al <b class="forte">{{ $esercizio->data_fine?->format('d/m/Y') }}</b><br>
            Stampato il <b class="forte">{{ \App\Helpers\DateHelper::adessoUtente()->format('d/m/Y') }}</b>
        </td>
    </tr>
</table>
@endif
