<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Documento PDF')</title>
    <style>
        @include('pdf.styles')
    </style>
</head>
<body>
    <htmlpageheader name="KondoHeader">
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
              questo — non produce nulla: viene chiamata dopo `WriteHTML()`, e questo blocco HTML
              (`sethtmlpageheader`) ha comunque la precedenza. È inerte allo stesso modo in altri
              sei controller di stampa del progetto, tutti fuori da questo diff — non li tocco.
              Qui, dove serve davvero (un registro di più pagine che altrimenti si identifica
              solo a pagina 1), il chiamante valorizza `$titolo_stampa`: chi non lo passa vede
              esattamente l'intestazione di sempre, byte per byte.
            --}}
            @if(! empty($titolo_stampa ?? null))
                <div style="font-size: 8pt; color: #667; margin-top: 2px;">{{ $titolo_stampa }}</div>
            @endif
        </div>
    </htmlpageheader>

    <sethtmlpageheader name="KondoHeader" page="O" value="on" show-this-page="1" />

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
                            <span style="color: #555;">Documento emesso il {{ ($data_emissione_stampe ?? now())->format('d/m/Y') }}</span>
                            @if(!empty($nota_legale_stampe))
                                <br>{!! nl2br(str_replace('  ', '&nbsp;&nbsp;', e($nota_legale_stampe))) !!}
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
                        <span style="color: #555;">Documento emesso il {{ ($data_emissione_stampe ?? now())->format('d/m/Y') }}</span>
                        @if(!empty($nota_legale_stampe))
                            <br>{!! nl2br(str_replace('  ', '&nbsp;&nbsp;', e($nota_legale_stampe))) !!}
                        @endif
                    </td>
                    <td style="text-align: right; width: 25%;">
                        Pagina {PAGENO} di {nbpg}
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
