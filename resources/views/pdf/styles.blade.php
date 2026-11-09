{{-- I titoli, la testata comune e i filetti dei titoli hanno il colore della carta dello studio; senza un colore, quello di sempre. --}}
@php
    $coloreDelleStampe = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#2c3e50');
@endphp
body {
    font-family: dejavusans, sans-serif;
    font-size: 9pt;
    color: #333;
    line-height: 1.4;
}

h1, h2, h3, h4 {
    margin-top: 0;
    color: {{ $coloreDelleStampe }};
}

h1 { font-size: 14pt; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
h2 { font-size: 12pt; }
h3 { font-size: 11pt; }

{{-- La testata comune non ha margine sotto: il margine alto delle stampe si allunga sulla testata, e un margine sotto lo conterebbe, spostando il
     corpo anche con un nome corto. --}}
.header {
    text-align: center;
    border-bottom: 2px solid {{ $coloreDelleStampe }};
    padding-bottom: 10px;
}

.header-title {
    font-size: 16pt;
    font-weight: bold;
    color: {{ $coloreDelleStampe }};
}

.header-subtitle {
    font-size: 10pt;
    color: #555;
}

.section {
    margin-bottom: 15px;
}

.section-title {
    font-size: 11pt;
    font-weight: bold;
    background-color: #f0f0f0;
    padding: 4px 8px;
    border-left: 3px solid {{ $coloreDelleStampe }};
    margin-bottom: 8px;
}

.grid {
    width: 100%;
}

.grid td {
    vertical-align: top;
    padding: 2px 0;
}

.label {
    font-weight: bold;
    color: #555;
    width: 35%;
}

.value {
    width: 65%;
}

.table-report {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 15px;
}

.table-report th {
    background-color: #e2e8f0;
    color: #334155;
    font-weight: bold;
    text-align: left;
    padding: 6px;
    border: 1px solid #cbd5e1;
}

.table-report td {
    padding: 6px;
    border: 1px solid #cbd5e1;
}

.table-report tr:nth-child(even) {
    background-color: #f8fafc;
}

.text-right { text-align: right !important; }
.text-center { text-align: center !important; }
.text-bold { font-weight: bold !important; }
.text-sm { font-size: 8pt; }
.text-muted { color: #64748b; }

.summary-box {
    border: 1px solid #cbd5e1;
    background-color: #f1f5f9;
    padding: 10px;
}
