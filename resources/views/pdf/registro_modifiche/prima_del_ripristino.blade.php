{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
@extends('pdf.base')

@section('title', 'Registro delle modifiche prima del ripristino')

@section('content')
    {{--
        Il registro delle modifiche com'era prima del ripristino di una copia che non aveva righe in comune con lui (decisione 546 (2), §12 del progetto,
        passo 3), oppure, con `senza_firma`, le sole righe del file che non avevano la firma del programma e che il ripristino non ha rimesso: ogni riga con la sua sequenza, l'ora, chi, su che cosa, che cosa è cambiato e la sua impronta. I dati già oscurati restano oscurati (il file
        ha le righe com'erano, con l'oscuramento). I valori si scrivono sempre con `{{ }}` (P18), mai come HTML.
    --}}
    @php
        $testoDelCampo = function (array $c): string {
            $prima = $c['prima'] ?? null;
            $dopo = $c['dopo'] ?? null;

            return ($c['etichetta'] ?? $c['chiave'] ?? '') . ': ' . ($prima === null || $prima === '' ? 'vuoto' : $prima) . ' → ' . ($dopo === null || $dopo === '' ? 'vuoto' : $dopo);
        };
        $ultima = end($righe) ?: null;
        $senzaFirma = (bool) ($senza_firma ?? false);
        $introduzione = 'Le righe che c\'erano nel registro delle modifiche prima del ripristino'
            . ($copia_del ? ' della copia del ' . $copia_del : '') . ', fatto il ' . $ripristinata_il . ($chi ? ' da ' . $chi : '') . '.'
            . ($senzaFirma
                ? ' Queste righe non avevano la firma del programma (erano state aggiunte o cambiate fuori dal programma, per esempio direttamente nel database):'
                    . ' il ripristino non le ha rimesse nel registro. Sono qui, ognuna con la sua impronta. '
                : ' La copia non aveva righe in comune con questo registro: le righe sono qui, ognuna con la sua impronta, che la lega a quella prima. ')
            . (count($righe) === 1 ? '1 riga' : count($righe) . ' righe') . ($oltre > 0 ? ' (e altre ' . $oltre . ' rimaste nel file della cartella delle copie)' : '') . '.';
    @endphp

    <h1 style="font-size: 14pt; margin: 0 0 2mm;">{{ $senzaFirma ? 'Righe senza la firma del programma, prima del ripristino' : 'Registro delle modifiche prima del ripristino' }}</h1>
    <p style="font-size: 9pt; color: #555; margin: 0 0 5mm;">{{ $introduzione }}</p>

    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
        <thead>
            <tr style="background: #f1f5f9;">
                <th style="text-align: right; padding: 1.2mm; width: 8%;">N.</th>
                <th style="text-align: left; padding: 1.2mm; width: 11%;">Quando</th>
                <th style="text-align: left; padding: 1.2mm; width: 13%;">Chi</th>
                <th style="text-align: left; padding: 1.2mm; width: 20%;">Su che cosa</th>
                <th style="text-align: left; padding: 1.2mm; width: 32%;">Che cosa è cambiato</th>
                <th style="text-align: left; padding: 1.2mm; width: 16%;">Impronta</th>
            </tr>
        </thead>
        <tbody>
            @foreach($righe as $r)
                @php
                    $campi = is_string($r['campi'] ?? null) ? (json_decode($r['campi'], true) ?: []) : ($r['campi'] ?? []);
                    $quando = \Illuminate\Support\Carbon::parse((string) $r['avvenuta_il'], 'UTC')->setTimezone($fuso)->format('d/m/Y H:i');
                @endphp
                <tr style="border-bottom: 0.2mm solid #e2e8f0;">
                    <td style="text-align: right; padding: 1.2mm; vertical-align: top;">{{ $r['sequenza'] ?? '—' }}</td>
                    <td style="padding: 1.2mm; vertical-align: top;">{{ $quando }}</td>
                    <td style="padding: 1.2mm; vertical-align: top;">{{ $r['autore_nome'] . (empty($r['autore_ruolo']) ? '' : ' (' . $r['autore_ruolo'] . ')') }}</td>
                    <td style="padding: 1.2mm; vertical-align: top;">{{ $r['oggetto_nome'] }}</td>
                    <td style="padding: 1.2mm; vertical-align: top;">
                        <strong>{{ $r['azione'] . (empty($r['oscurata_il']) ? '' : ' · oscurata') }}</strong>
                        @if (! empty($r['riepilogo']))
                            <br>{{ $r['riepilogo'] }}
                        @endif
                        @foreach ($campi as $c)
                            <br>{{ $testoDelCampo($c) }}
                        @endforeach
                        @if (! empty($r['perche']))
                            <br>Perché: {{ $r['perche'] }}
                        @endif
                    </td>
                    <td style="padding: 1.2mm; vertical-align: top; font-size: 6.5pt; word-break: break-all;">{{ $r['impronta'] ?? 'non sigillata' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($ultima)
        <p style="font-size: 8pt; color: #555; margin-top: 4mm;">
            Impronta dell'ultima riga: {{ $ultima['impronta'] ?? 'non sigillata' }}.
        </p>
    @endif
@endsection
