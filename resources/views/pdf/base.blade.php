{{-- La testata dello studio: con lo studio la prima pagina ha il blocco intero e le seguenti una riga (le regole `@page` qui sotto); senza, la
     testata del condominio di sempre, su ogni pagina. Le testate si danno con `@page`: con il margine che si allunga sulla testata (`PdfService`)
     mPDF misura così anche la prima pagina, mentre una testata accesa nel corpo (`sethtmlpageheader`) arriva quando la prima pagina è già
     cominciata, e il corpo le finirebbe sotto. `$intestazione_propria`: la vista scrive da sé chi emette il documento (la lettera, la ricevuta,
     l'avviso), e qui non si ripete. Con la carta intestata lo studio è stampato sulla carta: su ogni pagina una riga con il condominio, in cima alla
     parte libera. --}}
@php($testataDelloStudio = empty($intestazione_propria ?? null) && isset($carta) && $carta->haStudio())
@php($testataDellaCarta = empty($intestazione_propria ?? null) && isset($carta) && $carta->cartaIntestata() !== null)
@php($testataComune = empty($intestazione_propria ?? null) && ! $testataDelloStudio && ! $testataDellaCarta)
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Documento PDF')</title>
    <style>
        @include('pdf.styles')
    </style>
    @if($testataDelloStudio)
    <style>
        @include('pdf._carta.regole_pagina', ['prima' => 'KondoTestata', 'seguenti' => 'KondoTestatina', 'piede' => 'KondoFooter'])
    </style>
    @elseif($testataDellaCarta)
    <style>
        @page { header: html_KondoCarta; footer: html_KondoFooter; }
    </style>
    @elseif($testataComune)
    <style>
        @page { header: html_{{ \App\Services\PDF\PdfService::TESTATA_COMUNE }}; footer: html_KondoFooter; }
    </style>
    @endif
</head>
<body>
    @if($testataDelloStudio)
    <htmlpageheader name="KondoTestata">
        @include('pdf._carta.testata', ['titolo' => $titolo_stampa ?? null])
    </htmlpageheader>
    <htmlpageheader name="KondoTestatina">
        @include('pdf._carta.testatina', ['titolo' => $titolo_stampa ?? null])
    </htmlpageheader>
    @elseif($testataDellaCarta)
    <htmlpageheader name="KondoCarta">
        @include('pdf._carta.testata_carta', ['titolo' => $titolo_stampa ?? null])
    </htmlpageheader>
    @elseif($testataComune)
    <htmlpageheader name="{{ \App\Services\PDF\PdfService::TESTATA_COMUNE }}">
        <div class="header">
            @if(isset($condominio))
                <div class="header-title">{{ $condominio->nome }}</div>
                <div class="header-subtitle">
                    {{ $condominio->indirizzo }}<br>
                    C.F. {{ $condominio->codice_fiscale }}
                </div>
            @endif
            {{--
              ⚠️ **Facoltativo, e per un motivo preciso.** `Mpdf::SetHeader()` — l'API pensata per
              questo — chiamata dopo `WriteHTML()` sostituirebbe la testata dell'ultima pagina, che
              qui viene dalla regola `@page`: nessuna stampa la chiama.
              Qui, dove serve davvero (un registro di più pagine che altrimenti si identifica
              solo a pagina 1), il chiamante valorizza `$titolo_stampa`: chi non lo passa vede
              esattamente l'intestazione di sempre, byte per byte.
            --}}
            @if(! empty($titolo_stampa ?? null))
                <div style="font-size: 8pt; color: #667; margin-top: 2px;">{{ $titolo_stampa }}</div>
            @endif
        </div>
    </htmlpageheader>
    @endif

    {{-- Il giorno dell'emissione è quello dell'amministratore, non quello del server: fra le 00:00 e le 02:00 di Roma il server (UTC) è
         ancora a ieri. Una stampa che passa la sua data (`data_emissione_stampe`) tiene la sua. --}}
    @php($giornoDelPiede = ($data_emissione_stampe ?? \Illuminate\Support\Carbon::parse(\App\Helpers\DateHelper::oggiUtente()))->format('d/m/Y'))
    <htmlpagefooter name="KondoFooter">
        @if(!empty($piede_compatto ?? null))
            {{-- Beta.27: le stampe del riparto chiedono un piede che stia nel margine. mPDF ancora il
                 piede a `h − margin_footer` e lo fa crescere verso l'alto; quello standard misura
                 10,6 mm perché la tabella dentro il div esce a 9pt — mPDF non eredita il `font-size`
                 dal div, va messo sulla <table> — e con margin_bottom 12 invadeva il contenuto: il
                 filetto tagliava l'ultima riga e la firma. Questo sta in 6,5 mm. --}}
            <div style="border-top: 1px solid #c0ccd8; padding-top: 2px; color: #666; font-family: dejavusans, sans-serif;">
                <table style="width: 100%; font-size: 7pt; line-height: 1.15; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: left; width: 75%; padding: 0;">
                            @if(empty($piede_senza_data ?? null))
                                <span style="color: #555;">Documento emesso il {{ $giornoDelPiede }}</span>
                            @endif
                            @if(!empty($nota_legale_stampe))
                                @if(empty($piede_senza_data ?? null))<br>@endif{!! nl2br(str_replace('  ', '&nbsp;&nbsp;', e($nota_legale_stampe))) !!}
                            @endif
                        </td>
                        <td style="text-align: right; width: 25%; padding: 0;">
                            Pagina {PAGENO} di {nbpg}
                        </td>
                    </tr>
                </table>
            </div>
        @else
        <div style="border-top: 1px solid #c0ccd8; padding-top: 4px;
                    font-size: 7pt; color: #666; font-family: dejavusans, sans-serif;">
            <table style="width: 100%;">
                <tr>
                    <td style="text-align: left; width: 75%;">
                        @if(empty($piede_senza_data ?? null))
                            <span style="color: #555;">Documento emesso il {{ $giornoDelPiede }}</span>
                        @endif
                        @if(!empty($nota_legale_stampe))
                            @if(empty($piede_senza_data ?? null))<br>@endif{!! nl2br(str_replace('  ', '&nbsp;&nbsp;', e($nota_legale_stampe))) !!}
                        @endif
                    </td>
                    <td style="text-align: right; width: 25%;">
                        {{-- Nel fascicolo del rendiconto il nome della parte accanto al numero; chi non lo passa vede il piede di sempre. --}}
                        @if(! empty($parte_del_fascicolo ?? null)) {{ $parte_del_fascicolo }}, pagina {PAGENO} di {nbpg} @else Pagina {PAGENO} di {nbpg} @endif
                    </td>
                </tr>
            </table>
        </div>
        @endif
    </htmlpagefooter>

    <sethtmlpagefooter name="KondoFooter" page="ALL" value="on" />

    <div class="content">
        @yield('content')

        @if(!empty($firma_stampe_absolute_path))
            @if(!empty($firma_compatta ?? null))
                {{-- Beta.27: le stampe del riparto la chiedono compatta — etichetta e firma sulla stessa
                     riga, immagine più bassa, niente aria sopra. Con 48 righe su un A4 orizzontale il
                     blocco di 40 px + 80 px finiva da solo su una terza pagina bianca. Chi non passa
                     `firma_compatta` vede il blocco di sempre. --}}
                <table style="width: 100%; margin-top: 6px; page-break-inside: avoid; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: right; vertical-align: bottom; font-size: 8pt; color: #1e3a5f; padding: 0 8px 0 0; width: 80%;">
                            L'Amministratore
                        </td>
                        <td style="text-align: right; vertical-align: bottom; width: 20%; padding: 0;">
                            <img src="{{ $firma_stampe_absolute_path }}" style="max-width: 120px; max-height: 42px; object-fit: contain;">
                        </td>
                    </tr>
                </table>
            @else
            <div style="margin-top: 40px; text-align: right; page-break-inside: avoid;">
                <div style="font-size: 10pt; margin-bottom: 10px; color: #1e3a5f;">
                    L'Amministratore
                </div>
                <img src="{{ $firma_stampe_absolute_path }}" style="max-width: 180px; max-height: 80px; object-fit: contain;">
            </div>
            @endif
        @endif
    </div>

</body>
</html>
