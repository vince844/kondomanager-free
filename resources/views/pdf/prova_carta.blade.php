@extends('pdf.base')
{{--
  La pagina orizzontale della prova della carta: la testata a campi della carta (o quella comune, senza il nome dello studio), un titolo con il
  suo filetto e una tabella con cifre inventate, come le stampe orizzontali. Nessun dato di un condominio: li passa `ProvaDellaCarta`.
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $euro = fn (int $centesimi) => '€ '.number_format($centesimi / 100, 2, ',', '.');
    $righe = [['Int. 1', 'Maria Esempio', 'Proprietaria', 120.5, 36000], ['Int. 2', 'Paolo Esempio', 'Proprietario', 95.25, 28400], ['Int. 3', 'Anna Esempio', 'Inquilina', 84.25, 25100]];
@endphp

@section('title', 'Prova della carta')

@section('content')
<div style="margin-bottom: 8px; border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $coloreTitoli }}; letter-spacing: 0.5px;">PROVA DELLA CARTA — PAGINA ORIZZONTALE</h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">La «{{ $nome_della_carta }}», con dati d'esempio: il condominio, le persone e le cifre sono inventati.</div>
</div>

<table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
    <thead>
        <tr style="background: #eef2f7; color: #1e3a5f;">
            <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Unità</th>
            <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Condòmino</th>
            <th style="text-align: left; padding: 4px; border-bottom: 1px solid #c0ccd8;">Titolo</th>
            <th style="text-align: right; padding: 4px; border-bottom: 1px solid #c0ccd8;">Millesimi</th>
            <th style="text-align: right; padding: 4px; border-bottom: 1px solid #c0ccd8;">Quota</th>
        </tr>
    </thead>
    <tbody>
        @foreach($righe as [$unita, $nome, $titolo, $millesimi, $quota])
            <tr>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $unita }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $nome }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef;">{{ $titolo }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right;">{{ number_format($millesimi, 2, ',', '.') }}</td>
                <td style="padding: 3px 4px; border-bottom: 1px solid #e3e8ef; text-align: right; white-space: nowrap;">{{ $euro($quota) }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="4" style="padding: 4px; text-align: right; font-weight: bold; border-top: 1.5px solid #1e3a5f;">Totale</td>
            <td style="padding: 4px; text-align: right; font-weight: bold; white-space: nowrap; border-top: 1.5px solid #1e3a5f;">{{ $euro(array_sum(array_column($righe, 4))) }}</td>
        </tr>
    </tbody>
</table>
@endsection
