{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

{{--
  L'indice del fascicolo per il legale (`00 Indice.pdf`): in testa la persona, il giorno e chi l'ha preparato (il condominio è nella testata della carta);
  le cifre con le etichette che le dicono; l'elenco dei file dell'archivio con il numero, il nome, che cosa è, la data, e per i documenti conservati
  l'impronta (le prime 12 cifre) con la sua frase; i fatti; «Che cosa manca»; in fondo, sempre, la frase che dice che cosa l'indice è e quella sui dati
  personali. Dice solo ciò che è dentro il fascicolo, e mai che basta per agire. Tutto arriva già scritto dal server (`FascicoloLegale::indice`).
--}}
@php
    $coloreTitoli = \App\Services\PDF\Carta::coloreDi($carta ?? null, '#1e3a5f');
    $cella = 'padding: 3px 4px; border-bottom: 1px solid #e3e8ef; vertical-align: top;';
    $testa = 'padding: 4px; border-bottom: 1px solid #c0ccd8; text-align: left;';
@endphp

@section('title', 'Fascicolo per il legale — ' . $persona['nome'])

@section('content')
    <div style="border-bottom: 2px solid {{ $coloreTitoli }}; padding-bottom: 6px; margin-bottom: 10px;">
        <h2 style="margin: 0; font-size: 13pt; color: {{ $coloreTitoli }}; letter-spacing: 0.5px;">Fascicolo per il legale</h2>
        <div style="font-size: 10pt; color: #222; margin-top: 4px;">
            <strong>{{ $persona['nome'] }}</strong>@if($unita !== []) — {{ implode(', ', $unita) }}@endif
            @if(filled($persona['codice_fiscale'] ?? null))<span style="color: #555;"> — C.F. {{ $persona['codice_fiscale'] }}</span>@endif
        </div>
        <div style="font-size: 9pt; color: #444; margin-top: 2px;">{{ \App\Services\Gestionale\Lettere\TestiLettere::condominioConNome((string) $condominio->nome) }} · {{ $preparato }}</div>
    </div>

    <div style="font-size: 10pt; margin-bottom: 2px;"><strong>{{ $cifra }}</strong></div>
    @foreach($di_cui as $riga)
        <div style="font-size: 9pt; color: #333; margin-left: 4mm;">{{ $riga }}</div>
    @endforeach

    <h3 style="font-size: 10.5pt; color: {{ $coloreTitoli }}; margin: 12px 0 4px;">Che cosa c'è</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 8pt;">
        <thead>
            <tr style="background: #eef2f7; color: #1e3a5f;">
                <th style="{{ $testa }} width: 5%;">N.</th>
                <th style="{{ $testa }} width: 29%;">Nome nell'archivio</th>
                <th style="{{ $testa }} width: 40%;">Che cosa è</th>
                <th style="{{ $testa }} width: 12%;">Data</th>
                <th style="{{ $testa }} width: 14%;">Impronta</th>
            </tr>
        </thead>
        <tbody>
            @foreach($file as $f)
                <tr>
                    <td style="{{ $cella }}">{{ $f['numero'] ?? '—' }}</td>
                    <td style="{{ $cella }}">{{ $f['nome'] ?? 'non nell\'archivio' }}</td>
                    <td style="{{ $cella }}">
                        {{ $f['cosa'] }}
                        @if($f['frase'])<div style="font-size: 7.5pt; color: {{ in_array($f['stato'], ['diversa', 'sparito'], true) ? '#8a4b00' : '#444' }}; margin-top: 1px;">{{ $f['frase'] }}</div>@endif
                    </td>
                    <td style="{{ $cella }} white-space: nowrap;">{{ $f['data'] ?? '' }}</td>
                    <td style="{{ $cella }} white-space: nowrap;">{{ $f['cifre'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($fatti !== [])
        <h3 style="font-size: 10.5pt; color: {{ $coloreTitoli }}; margin: 12px 0 4px;">Da sapere</h3>
        @foreach($fatti as $frase)
            <div style="font-size: 9pt; margin: 0 0 3px 4mm;">• {{ $frase }}</div>
        @endforeach
    @endif

    <h3 style="font-size: 10.5pt; color: #8a4b00; margin: 12px 0 4px;">Che cosa manca</h3>
    @forelse($mancanze as $frase)
        <div style="font-size: 9pt; margin: 0 0 3px 4mm;">• {{ $frase }}</div>
    @empty
        <div style="font-size: 9pt; margin: 0 0 3px 4mm; color: #444;">{{ $frase_nessuna_mancanza }}</div>
    @endforelse

    {{-- Le due frasi in fondo restano insieme: una tabella, perché mPDF tiene intera una tabella e non un blocco. --}}
    <table style="width: 100%; border-collapse: collapse; margin-top: 14px; page-break-inside: avoid;">
        <tr><td style="padding: 6px 0 0; border-top: 1px solid #c0ccd8; font-size: 9pt; color: #333;">{{ $frase_in_fondo }}</td></tr>
        <tr><td style="padding: 3px 0 0; font-size: 9pt; color: #333;">{{ $frase_dati_personali }}</td></tr>
    </table>
@endsection
