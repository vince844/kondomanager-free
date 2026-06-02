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

    <div class="content">
        @yield('content')
    </div>

</body>
</html>
