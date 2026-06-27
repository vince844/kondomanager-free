@extends('pdf.base')

@section('title', 'Ripartizione delle Spese')

@section('content')

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 14px; border-bottom: 2px solid #1e3a5f; padding-bottom: 8px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: #1e3a5f; letter-spacing: 0.5px;">
        PROSPETTO DI RIPARTIZIONE SPESE PER UNITÀ IMMOBILIARE
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Piano dei conti: <strong>{{ $pianoConto->nome }}</strong> &nbsp;|&nbsp;
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        (dal {{ $esercizio->data_inizio->format('d/m/Y') }} al {{ $esercizio->data_fine->format('d/m/Y') }})
    </div>
</div>

@if(empty($matrice))
    <p style="color: #888; font-style: italic; text-align: center; margin-top: 40px;">
        Nessuna quota registrata per questo piano dei conti.<br>
        Verificare che il piano rate associato sia stato approvato e che le rate siano state generate.
    </p>
@else

{{-- TABELLA MATRICE CONDÒMINI × RATE --}}
<table style="width: 100%; border-collapse: collapse; font-size: 7.5pt;">
    <thead>
        <tr style="background-color: #dce6f1; color: #1e3a5f; text-align: center;">
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; width: 55px; text-align: center;">Codice</th>
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: left;">Condòmino / Intestatario</th>
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; width: 30px; text-align: center;">Int.</th>
            @foreach($colonneRate as $numero => $datiRata)
                <th style="padding: 5px 4px; border: 1px solid #b0c4de; text-align: center;">
                    {{ $datiRata['nome'] }}<br>
                    <span style="font-weight: normal; font-size: 6.5pt;">{{ $datiRata['scadenza'] }}</span>
                </th>
            @endforeach
            <th style="padding: 5px 4px; border: 1px solid #b0c4de; background-color: #c8d8ee; width: 80px; text-align: right;">TOTALE (€)</th>
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
                <td style="padding: 4px; text-align: center; border: 1px solid #dce3ea; font-size: 7pt; color: #555;">
                    {{ $row['cod'] }}
                </td>
                <td style="padding: 4px 6px; border: 1px solid #dce3ea; font-weight: 600; color: #1e1e1e;">
                    {{ $row['nome'] }}
                    @if($row['piano'])
                        <br><span style="font-weight: normal; font-size: 6.5pt; color: #777;">Piano {{ $row['piano'] }}</span>
                    @endif
                </td>
                <td style="padding: 4px; text-align: center; border: 1px solid #dce3ea; color: #555;">
                    {{ $row['interno'] }}
                </td>

                @foreach($colonneRate as $numero => $datiRata)
                    @php
                        $importo = $row['importi_per_rata'][$numero] ?? 0;
                        $totaliPerRata[$numero] += $importo;
                    @endphp
                    <td style="padding: 4px; text-align: right; border: 1px solid #dce3ea;">
                        @if($importo > 0)
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

    {{-- RIGA TOTALI --}}
    <tfoot>
        <tr style="background-color: #dce6f1; font-weight: bold; color: #1e3a5f;">
            <td colspan="3" style="padding: 5px 6px; border: 1px solid #b0c4de; text-align: right; font-size: 8pt;">
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

<div style="margin-top: 8px; font-size: 7pt; color: #666;">
    * Gli importi sono espressi in Euro (€). Il prospetto mostra la quota di ogni condòmino per ciascuna rata del piano di pagamento.
</div>

@endif

{{-- NOTE LEGALI --}}
<div style="margin-top: 18px; font-size: 7pt; color: #666; border-top: 1px solid #ccc; padding-top: 6px;">
    Documento redatto ai sensi dell'art. 1123 c.c. (ripartizione delle spese condominiali).<br>
    Le quote di ripartizione sono calcolate in base alle tabelle millesimali in uso.
</div>

@endsection
