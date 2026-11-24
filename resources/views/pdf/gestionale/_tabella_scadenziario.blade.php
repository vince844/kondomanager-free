{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
    Partial condiviso: tabella scadenziario rate.

    Variabili:
    - $matrice:          array di righe [etichetta, sub_etichetta?, importi_per_rata[], totale]
    - $colonneRate:      array[numero_rata => [nome, scadenza]]
    - $labelColonna:     stringa intestazione prima colonna
    - $hasSottoEtichetta: bool (mostra riga secondaria per immobile)
    - $perSoggetto:      bool, la forma delle stampe per unità (Coda 756): `$matrice` è l'elenco delle unità, ognuna con i suoi
                         `soggetti` (nome, ruolo, importi_per_rata, totale) e il suo `totale`; `$colonneRate` ha la chiave
                         «<piano>:<numero>» e, per ogni colonna, il nome del piano (`piano`); `$conPiani` mette il nome del piano sopra le rate.
--}}

@if(!empty($perSoggetto))
@php
    $conPiani = !empty($conPiani);
    $gruppiPiano = [];
    if ($conPiani) {
        foreach ($colonneRate as $datiRata) {
            $gruppiPiano[$datiRata['piano']] = ($gruppiPiano[$datiRata['piano']] ?? 0) + 1;
        }
    }
    $righeTesta = $conPiani ? 2 : 1;
    $totaliPerRata = array_fill_keys(array_keys($colonneRate), 0);
    $granTotale    = 0;
    $rigaAlterna   = false;
@endphp
<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background-color: #dce6f1; color: #1e3a5f; text-align: center;">
            <th rowspan="{{ $righeTesta }}" style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: left; min-width: 110px;">{{ $labelColonna }}</th>
            <th rowspan="{{ $righeTesta }}" style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: left;">Soggetto</th>
            <th rowspan="{{ $righeTesta }}" style="padding: 5px 4px; border: 1px solid #b0c4de; width: 28px; text-align: center;">Ruolo</th>
            @if($conPiani)
                @foreach($gruppiPiano as $nomePiano => $nColonne)
                    <th colspan="{{ $nColonne }}" style="padding: 4px; border: 1px solid #b0c4de; text-align: center;">{{ $nomePiano }}</th>
                @endforeach
            @else
                @foreach($colonneRate as $datiRata)
                    <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: center;">
                        {{ $datiRata['nome'] }}<br>
                        <span style="font-weight: normal; font-size: 6.5pt;">{{ $datiRata['scadenza'] }}</span>
                    </th>
                @endforeach
            @endif
            <th rowspan="{{ $righeTesta }}" style="padding: 5px 4px; border: 1px solid #b0c4de; background-color: #c8d8ee; width: 70px; text-align: right;">Totale del soggetto</th>
            <th rowspan="{{ $righeTesta }}" style="padding: 5px 4px; border: 1px solid #b0c4de; background-color: #c8d8ee; width: 70px; text-align: right;">Totale dell'unità</th>
        </tr>
        @if($conPiani)
            <tr style="background-color: #dce6f1; color: #1e3a5f; text-align: center;">
                @foreach($colonneRate as $datiRata)
                    <th style="padding: 4px; border: 1px solid #b0c4de; text-align: center;">
                        {{ $datiRata['nome'] }}<br>
                        <span style="font-weight: normal; font-size: 6.5pt;">{{ $datiRata['scadenza'] }}</span>
                    </th>
                @endforeach
            </tr>
        @endif
    </thead>
    <tbody>
        @foreach($matrice as $unita)
            @php
                $rigaAlterna = !$rigaAlterna;
                $nSoggetti = max(1, count($unita['soggetti']));
            @endphp
            @foreach($unita['soggetti'] as $iSoggetto => $soggetto)
                <tr style="background-color: {{ $rigaAlterna ? '#f7f9fb' : '#ffffff' }};">
                    @if($iSoggetto === 0)
                        <td rowspan="{{ $nSoggetti }}" style="padding: 4px 6px; border: 1px solid #dce3ea; vertical-align: middle; font-weight: 600; color: #1e1e1e;">
                            {{ $unita['etichetta'] }}
                            @if(!empty($unita['dettaglio']))
                                <br><span style="font-weight: normal; font-size: 6.5pt; color: #666;">{{ $unita['dettaglio'] }}</span>
                            @endif
                        </td>
                    @endif
                    <td style="padding: 4px 6px; border: 1px solid #dce3ea; color: #1e1e1e;">{{ $soggetto['nome'] }}</td>
                    <td style="padding: 4px; text-align: center; border: 1px solid #dce3ea; color: #555;">{{ $soggetto['ruolo'] }}</td>
                    @foreach($colonneRate as $chiave => $datiRata)
                        @php
                            $importo = $soggetto['importi_per_rata'][$chiave] ?? 0;
                            $totaliPerRata[$chiave] += $importo;
                        @endphp
                        <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea;">
                            {{-- Come per condòmino: un credito (importo negativo) è un valore vero e il totale di colonna lo somma. --}}
                            @if($importo != 0)
                                € {{ number_format($importo / 100, 2, ',', '.') }}
                            @else
                                <span style="color: #bbb;">—</span>
                            @endif
                        </td>
                    @endforeach
                    <td style="padding: 4px; text-align: right; border: 1px solid #b0c4de; font-weight: bold; background-color: #edf2f8; color: #1e3a5f;">
                        € {{ number_format($soggetto['totale'] / 100, 2, ',', '.') }}
                    </td>
                    @if($iSoggetto === 0)
                        <td rowspan="{{ $nSoggetti }}" style="padding: 4px; text-align: right; vertical-align: middle; border: 1px solid #b0c4de; font-weight: bold; background-color: #e3ebf5; color: #1e3a5f;">
                            € {{ number_format($unita['totale'] / 100, 2, ',', '.') }}
                        </td>
                    @endif
                </tr>
            @endforeach
            @php $granTotale += $unita['totale']; @endphp
        @endforeach
    </tbody>
    <tfoot>
        <tr style="background-color: #dce6f1; font-weight: bold; color: #1e3a5f;">
            <td colspan="3" style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: right; font-size: 8pt;">TOTALI</td>
            @foreach($colonneRate as $chiave => $datiRata)
                <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de;">€ {{ number_format($totaliPerRata[$chiave] / 100, 2, ',', '.') }}</td>
            @endforeach
            <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de; background-color: #c8d8ee;">€ {{ number_format($granTotale / 100, 2, ',', '.') }}</td>
            <td style="padding: 5px 4px; text-align: right; border: 2px solid #1e3a5f; background-color: #c8d8ee; color: #1e3a5f;">€ {{ number_format($granTotale / 100, 2, ',', '.') }}</td>
        </tr>
    </tfoot>
</table>
<div style="margin-top: 6px; font-size: 7pt; color: #666;">
    Ruoli: <strong>P</strong> proprietario · <strong>NP</strong> nudo proprietario · <strong>U</strong> usufruttuario · <strong>I</strong> inquilino.
    Ogni soggetto paga la riga con il suo nome; il totale dell'unità è la somma delle righe dell'unità.
</div>
@else

<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background-color: #dce6f1; color: #1e3a5f; text-align: center;">
            <th style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: left; min-width: 130px;">
                {{ $labelColonna }}
            </th>
            @foreach($colonneRate as $numero => $datiRata)
                <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: center;">
                    {{ $datiRata['nome'] }}<br>
                    <span style="font-weight: normal; font-size: 6.5pt;">{{ $datiRata['scadenza'] }}</span>
                </th>
            @endforeach
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; background-color: #c8d8ee; width: 75px; text-align: right;">
                TOTALE (€)
            </th>
        </tr>
    </thead>
    <tbody>
        @php
            $totaliPerRata = array_fill_keys(array_keys($colonneRate), 0);
            $granTotale    = 0;
            $rigaAlterna   = false;
        @endphp

        @foreach($matrice as $row)
            @php $rigaAlterna = !$rigaAlterna; @endphp
            <tr style="background-color: {{ $rigaAlterna ? '#f7f9fb' : '#ffffff' }};">
                <td style="padding: 4px 6px; border: 1px solid #dce3ea; font-weight: 600; color: #1e1e1e;">
                    {{ $row['etichetta'] }}
                    @if(!empty($hasSottoEtichetta) && !empty($row['sub_etichetta']))
                        <br><span style="font-weight: normal; font-size: 6.5pt; color: #666;">{{ $row['sub_etichetta'] }}</span>
                    @endif
                    @if(!empty($hasSottoEtichetta) && !empty($row['intestatario']))
                        <br><span style="font-weight: normal; font-size: 6.5pt; color: #888;">Intestatario: {{ $row['intestatario'] }}</span>
                    @endif
                </td>

                @foreach($colonneRate as $numero => $datiRata)
                    @php
                        $importo = $row['importi_per_rata'][$numero] ?? 0;
                        $totaliPerRata[$numero] += $importo;
                    @endphp
                    <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea;">
                        {{-- ⚠️ Non "> 0": un credito su questa rata (RataQuote::importo negativo,
                             es. saldo iniziale) è un valore reale che il totale di colonna sotto
                             include comunque. Nasconderlo qui e sommarlo lì fa apparire un totale
                             che non coincide con la somma delle celle visibili — la colonna
                             TOTALE (€) qui a destra già mostra i negativi senza questo filtro. --}}
                        @if($importo != 0)
                            € {{ number_format($importo / 100, 2, ',', '.') }}
                        @else
                            <span style="color: #bbb;">—</span>
                        @endif
                    </td>
                @endforeach

                <td style="padding: 4px; text-align: right; border: 1px solid #b0c4de; font-weight: bold; background-color: #edf2f8; color: #1e3a5f;">
                    € {{ number_format($row['totale'] / 100, 2, ',', '.') }}
                </td>
            </tr>
            @php $granTotale += $row['totale']; @endphp
        @endforeach
    </tbody>

    <tfoot>
        <tr style="background-color: #dce6f1; font-weight: bold; color: #1e3a5f;">
            <td style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: right; font-size: 8pt;">
                TOTALI
            </td>
            @foreach($colonneRate as $numero => $datiRata)
                <td style="padding: 5px 4px; text-align: right; border: 1px solid #b0c4de;">
                    € {{ number_format($totaliPerRata[$numero] / 100, 2, ',', '.') }}
                </td>
            @endforeach
            <td style="padding: 5px 4px; text-align: right; border: 2px solid #1e3a5f; background-color: #c8d8ee; color: #1e3a5f;">
                € {{ number_format($granTotale / 100, 2, ',', '.') }}
            </td>
        </tr>
    </tfoot>
</table>
@endif
