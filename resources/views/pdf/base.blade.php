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
        </div>
    </htmlpageheader>

    <sethtmlpageheader name="KondoHeader" page="O" value="on" show-this-page="1" />

    <htmlpagefooter name="KondoFooter">
        <div style="border-top: 1px solid #c0ccd8; padding-top: 4px;
                    font-size: 7pt; color: #666; font-family: dejavusans, sans-serif;">
            <table style="width: 100%;">
                <tr>
                    <td style="text-align: left; width: 75%;">
                        @if(!empty($nota_legale_stampe))
                            {{ $nota_legale_stampe }}
                        @endif
                    </td>
                    <td style="text-align: right; width: 25%;">
                        Pagina {PAGENO} di {nbpg}
                    </td>
                </tr>
            </table>
        </div>
    </htmlpagefooter>

    <sethtmlpagefooter name="KondoFooter" page="ALL" value="on" />

    <div class="content">
        @yield('content')

        @if(!empty($firma_stampe_absolute_path))
            <div style="margin-top: 40px; text-align: right; page-break-inside: avoid;">
                <div style="font-size: 10pt; margin-bottom: 10px; color: #1e3a5f;">
                    L'Amministratore
                </div>
                <img src="{{ $firma_stampe_absolute_path }}" style="max-width: 180px; max-height: 80px; object-fit: contain;">
            </div>
        @endif
    </div>

</body>
</html>
