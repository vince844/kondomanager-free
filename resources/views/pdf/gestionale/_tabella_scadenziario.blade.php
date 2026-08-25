{{--
    Partial condiviso: tabella scadenziario rate.

    Variabili:
    - $matrice:          array di righe [etichetta, sub_etichetta?, importi_per_rata[], totale]
    - $colonneRate:      array[numero_rata => [nome, scadenza]]
    - $labelColonna:     stringa intestazione prima colonna
    - $hasSottoEtichetta: bool (mostra riga secondaria per immobile)
--}}

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
