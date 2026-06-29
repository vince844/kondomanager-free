@extends('pdf.base')

@section('title', 'Riparto Bilancio per Capitolo di Spesa')

@section('content')
@php
    use Illuminate\Support\Str;

    $capitoli      = $matrice['capitoli'];
    $righe        = $matrice['righe'];
    $granTotale   = $matrice['gran_totale'];
    $totPerCap    = $matrice['tot_per_capitolo'];
    $nCap         = count($capitoli);

    // ── Font adattivo ────────────────────────────────────────────────────────
    $fontBase  = $nCap > 8 ? '5.5pt' : ($nCap > 5 ? '6pt'   : '7pt');
    $fontSmall = $nCap > 8 ? '4.5pt' : ($nCap > 5 ? '5pt'   : '6pt');
    $fontTiny  = $nCap > 8 ? '4pt'   : ($nCap > 5 ? '4.5pt' : '5.5pt');

    // ── Larghezze colonne (%) ────────────────────────────────────────────────
    $wApp    = 5;
    $wNome   = $nCap > 5 ? 18 : 22;
    $wRuolo  = 5;
    $wTotSogg= $nCap > 5 ? 8 : 10;
    $wPct    = 5;
    $wTotApt = $nCap > 5 ? 8 : 10;
    $wFixed  = $wApp + $wNome + $wRuolo + $wTotSogg + $wPct + $wTotApt;
    $wTabCols = 100 - $wFixed;
    // Le larghezze interne verranno calcolate per ogni blocco (chunk)

    // ── Palette (allineata ai modelli) ───────────────────────────────────────
    $navy    = '#1e3a5f'; // brand color e testata testuale
    $navyMid = '#b0c4de'; // bordi header e tfoot
    $navyLt  = '#dce6f1'; // background header
    $iceBlue = '#edf2f8'; // colonna totale (tbody) e colonna app
    $rowAlt  = '#f7f9fb'; // righe alternate
    $totBg   = '#dce6f1'; // background riga totale finale
    $totEmph = '#c8d8ee'; // background cella gran totale (tfoot/thead)
    $sepLine = '#dce3ea'; // bordi interni tbody

    // ── Pre-calcola % per soggetto ───────────────────────────────────────────
    $pctSoggetti = [];
    foreach ($righe as $immobileId => $riga) {
        foreach ($riga['soggetti'] as $aid => $sogg) {
            $pctSoggetti[$aid . '|' . $immobileId] = $granTotale > 0
                ? round($sogg['totale'] / $granTotale * 100, 2)
                : 0;
        }
    }

    // ── Riepilogo per tipo soggetto (sommario) ───────────────────────────────
    $riepilogoRuoli = [];
    foreach ($righe as $riga) {
        foreach ($riga['soggetti'] as $sogg) {
            $ruoloRaw = $sogg['ruolo_raw'] ?? 'proprietario';
            $riepilogoRuoli[$ruoloRaw] = ($riepilogoRuoli[$ruoloRaw] ?? 0) + $sogg['totale'];
        }
    }

    $nUnita    = count($righe);
    $nSoggetti = array_sum(array_map(fn($r) => count($r['soggetti']), $righe));

    $dataDelibera = $pianoRate->data_delibera_assemblea?->format('d/m/Y') ?? null;
    $numVerbale   = $pianoRate->numero_verbale ?? null;

    $nomiRuoli = [
        'proprietario'      => 'Proprietari',
        'usufruttuario'     => 'Usufruttuari',
        'inquilino'         => 'Inquilini',
    ];
@endphp

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 14px; border-bottom: 2px solid {{ $navy }}; padding-bottom: 8px;">
    <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $navy }}; letter-spacing: 0.5px;">
        RIPARTO BILANCIO PREVENTIVO PER CAPITOLO E SOGGETTO
    </h2>
    <div style="font-size: 8.5pt; color: #444; margin-top: 3px;">
        Piano rate: <strong>{{ $pianoRate->nome }}</strong> &nbsp;|&nbsp;
        Esercizio: <strong>{{ $esercizio->nome }}</strong>
        (dal {{ $esercizio->data_inizio?->format('d/m/Y') }} al {{ $esercizio->data_fine?->format('d/m/Y') }})
        @if($dataDelibera)
            &nbsp;|&nbsp; Delibera del: <strong>{{ $dataDelibera }}</strong>
        @endif
        @if($numVerbale)
            &nbsp;|&nbsp; Verbale n.: <strong>{{ $numVerbale }}</strong>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- RIEPILOGO PER TIPO SOGGETTO (barra sommario)                               --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@if(count($riepilogoRuoli) > 1)
<table style="width: 100%; border-collapse: separate; border-spacing: 3px 0; margin-bottom: 7px;">
    <tr>
        @foreach($riepilogoRuoli as $ruolo => $importoRuolo)
        @php
            $pctRuolo = $granTotale > 0 ? round($importoRuolo / $granTotale * 100, 1) : 0;
            $colori = [
                'proprietario'      => ['bg' => '#e8f0f8', 'bd' => '#4a7db5', 'txt' => '#1a3557'],
                'inquilino'         => ['bg' => '#eaf7ee', 'bd' => '#4aad6a', 'txt' => '#1a4a2a'],
                'usufruttuario'     => ['bg' => '#fef4e8', 'bd' => '#d4924a', 'txt' => '#5a3a10'],
            ];
            $c = $colori[$ruolo] ?? ['bg' => '#f0f0f0', 'bd' => '#999', 'txt' => '#333'];
        @endphp
        <td style="background-color: {{ $c['bg'] }}; border: 1px solid {{ $c['bd'] }};
                    border-radius: 4px; padding: 3px 6px; text-align: center; vertical-align: middle;">
            <div style="font-size: {{ $fontTiny }}; color: {{ $c['txt'] }}; font-weight: bold; white-space: nowrap;">
                {{ $nomiRuoli[$ruolo] ?? ucfirst($ruolo) }}
            </div>
            <div style="font-size: {{ $fontSmall }}; color: {{ $c['txt'] }}; white-space: nowrap;">
                € {{ number_format($importoRuolo / 100, 2, ',', '.') }}
            </div>
            <div style="font-size: {{ $fontTiny }}; color: {{ $c['bd'] }};">
                {{ $pctRuolo }}%
            </div>
        </td>
        @endforeach
    </tr>
</table>
@endif

@if (empty($capitoli) || empty($righe))
    <p style="color: #888; font-style: italic; text-align: center; margin-top: 40px;">
        Nessun dato disponibile.
    </p>
@else

@php
    // Dividiamo i capitoli in blocchi di max 6 colonne per pagina
    $chunksCapitoli = array_chunk($capitoli, 6, true);
@endphp

@foreach($chunksCapitoli as $chunkIndex => $capitoliChunk)
    @php
        $nCapChunk = count($capitoliChunk);
        $wPerTab  = $nCapChunk > 0 ? max(5, $wTabCols / $nCapChunk) : 50;
        $wQuota   = $wPerTab * 0.44;
        $wImporto = $wPerTab - $wQuota;
    @endphp

    @if(!$loop->first)
        <pagebreak />
        <div style="margin-bottom: 14px; border-bottom: 2px solid {{ $navy }}; padding-bottom: 8px;">
            <h2 style="margin: 0; padding: 0; font-size: 13pt; color: {{ $navy }}; letter-spacing: 0.5px;">
                RIPARTO BILANCIO PREVENTIVO PER CAPITOLO E SOGGETTO (Pagina {{ $chunkIndex + 1 }} di {{ count($chunksCapitoli) }})
            </h2>
        </div>
    @endif

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- TABELLA PRINCIPALE (BLOCCO {{ $chunkIndex + 1 }})                           --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<table style="width: 100%; border-collapse: collapse; font-size: {{ $fontBase }};">

    {{-- ── THEAD ─────────────────────────────────────────────────────────── --}}
    <thead>
        {{-- Riga 1: nomi capitoli --}}
        <tr style="background-color: {{ $navyLt }}; color: {{ $navy }};">
            <th rowspan="2" style="padding: 4px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wApp }}%;
                                    text-align: center; vertical-align: middle;">
                App.
            </th>
            <th rowspan="2" style="padding: 4px 5px; border: 1px solid {{ $navyMid }}; width: {{ $wNome }}%;
                                    text-align: left; vertical-align: middle;">
                Condòmino / Soggetto
            </th>
            <th rowspan="2" style="padding: 4px 2px; border: 1px solid {{ $navyMid }}; width: {{ $wRuolo }}%;
                                    text-align: center; vertical-align: middle; font-size: {{ $fontTiny }};">
                Ruolo
            </th>

            @foreach($capitoliChunk as $capId => $capInfo)
                <th colspan="2" style="padding: 4px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wPerTab }}%;
                                        text-align: center; font-size: {{ $fontSmall }}; white-space: nowrap; overflow: hidden;">
                    {{ Str::limit($capInfo['nome'], $nCap > 6 ? 12 : 22) }}
                </th>
            @endforeach

            <th rowspan="2" style="padding: 4px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wTotSogg }}%;
                                    text-align: right; vertical-align: middle; background-color: #f0f5fa;
                                    font-size: {{ $fontSmall }};">
                TOTALE SOGG.
            </th>
            <th rowspan="2" style="padding: 4px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wPct }}%;
                                    text-align: center; vertical-align: middle; background-color: {{ $totEmph }};
                                    font-size: {{ $fontTiny }};">
                % TOT.
            </th>
            <th rowspan="2" style="padding: 4px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wTotApt }}%;
                                    text-align: right; vertical-align: middle; background-color: {{ $totEmph }};
                                    font-size: {{ $fontSmall }};">
                TOT. IMMOB.
            </th>
        </tr>

        {{-- Riga 2: mill. / importo sub-header --}}
        <tr style="background-color: {{ $navyLt }}; color: {{ $navy }}; font-weight: normal; font-size: {{ $fontTiny }};">
            @foreach($capitoliChunk as $capId => $capInfo)
                <th style="padding: 2px 2px; border: 1px solid {{ $navyMid }}; text-align: right; width: {{ $wQuota }}%; opacity: 0.9;">
                    {{ $capInfo['quota_label'] }}
                </th>
                <th style="padding: 2px 2px; border: 1px solid {{ $navyMid }}; text-align: right; width: {{ $wImporto }}%; opacity: 0.9;">
                    importo €
                </th>
            @endforeach
        </tr>
    </thead>

    {{-- ── TBODY ───────────────────────────────────────────────────────────── --}}
    <tbody>
        @php
            $altColor     = false;
            $nSoggettiTot = 0;
        @endphp

        @foreach($righe as $immobileId => $rigaImmobile)
            @php
                $nSoggettiImmobile = count($rigaImmobile['soggetti']);
                $nSoggettiTot += $nSoggettiImmobile;
            @endphp

            @foreach($rigaImmobile['soggetti'] as $anagraficaId => $soggetto)
                @php
                    $altColor = !$altColor;
                    $bgRow    = $altColor ? $rowAlt : '#ffffff';
                    $isFirst  = $loop->first;
                    $isLast   = $loop->last;

                    $accentColori = [
                        'P'  => '#2a5080',
                        'NP' => '#7a56a8',
                        'U'  => '#c07a30',
                        'I'  => '#2a8050',
                        'C'  => '#a09030',
                    ];
                    $accent = $accentColori[$soggetto['ruolo']] ?? '#888';
                    $isBold = in_array($soggetto['ruolo'], ['P', 'NP']);

                    $pctKey = $anagraficaId . '|' . $immobileId;
                    $pct    = $pctSoggetti[$pctKey] ?? 0;
                @endphp

                <tr style="background-color: {{ $bgRow }};">

                    {{-- ── Colonna App. ─────────────────────── --}}
                    @if($isFirst)
                        <td rowspan="{{ $nSoggettiImmobile }}"
                            style="padding: 3px 2px; border: 1px solid {{ $sepLine }};
                                   text-align: center; font-weight: bold; color: {{ $navy }};
                                   background-color: {{ $iceBlue }}; vertical-align: middle;
                                   font-size: {{ $fontBase }};
                                   border-left: 3px solid {{ $navy }};">
                            {{ $rigaImmobile['interno'] ?? '—' }}
                            @if($rigaImmobile['piano'])
                                <br><span style="font-weight: normal; font-size: {{ $fontTiny }}; color: #888;">
                                    Piano {{ $rigaImmobile['piano'] }}
                                </span>
                            @endif
                        </td>
                    @endif

                    {{-- ── Nome soggetto ────────────────────────────── --}}
                    <td style="padding: 3px 5px; border: 1px solid {{ $sepLine }};
                               border-left: 2px solid {{ $accent }};
                               font-weight: {{ $isBold ? 'bold' : 'normal' }};
                               color: #1a1a1a; font-size: {{ $fontBase }};">
                        {{ $soggetto['nome'] }}
                        @if (isset($soggetto['quota_sogg']))
                            <span style="font-size: {{ $fontTiny }}; color: #888; font-weight: normal;">
                                ({{ floatval($soggetto['quota_sogg']) }}%)
                            </span>
                        @endif
                    </td>

                    {{-- ── Sigla ruolo ──────────────────────────────── --}}
                    <td style="padding: 3px 2px; border: 1px solid {{ $sepLine }};
                               text-align: center; color: {{ $accent }};
                               font-size: {{ $fontTiny }}; font-weight: bold;">
                        {{ $soggetto['ruolo'] }}
                    </td>

                    {{-- ── Dati per capitolo ────────────────────────── --}}
                    @foreach($capitoliChunk as $capId => $capInfo)
                        @php
                            $datiTab  = $soggetto['per_capitolo'][$capId] ?? null;
                            $quota    = $datiTab ? $datiTab['quota'] : null;
                            $importo  = $datiTab ? $datiTab['importo'] : 0;
                            $hasData  = !is_null($quota) && $importo > 0;
                        @endphp

                        @if($isFirst)
                            @php
                                $totImmTab = 0;
                                foreach($rigaImmobile['soggetti'] as $sogg) {
                                    $totImmTab += ($sogg['per_capitolo'][$capId]['importo'] ?? 0);
                                }
                                $hasQuota = !is_null($quota) && $totImmTab > 0;
                            @endphp
                            <td rowspan="{{ $nSoggettiImmobile }}" style="padding: 2px 2px; border: 1px solid {{ $sepLine }};
                                       text-align: right; font-size: {{ $fontSmall }};
                                       color: {{ $hasQuota ? '#555' : '#ddd' }};
                                       background-color: {{ $hasQuota ? 'transparent' : '#fafafa' }}; vertical-align: middle;">
                                @if(!is_null($quota))
                                    {{ number_format((float)$quota, 3, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                        @endif

                        <td style="padding: 2px 3px; border: 1px solid {{ $sepLine }};
                                   text-align: right; background-color: {{ $bgRow }};
                                   color: {{ $navy }}; font-size: {{ $fontBase }};">
                            @if($importo > 0)
                                € {{ number_format($importo / 100, 2, ',', '.') }}
                            @else
                                —
                            @endif
                        </td>
                    @endforeach

                    <td style="padding: 2px 3px; border: 1px solid {{ $sepLine }};
                               text-align: right; font-weight: bold; font-size: {{ $fontSmall }};
                               color: {{ $navy }}; background-color: #fafafa;">
                        € {{ number_format($soggetto['totale'] / 100, 2, ',', '.') }}
                    </td>

                    <td style="padding: 2px 3px; border: 1px solid {{ $sepLine }};
                               text-align: right; font-size: {{ $fontTiny }};
                               color: #666; background-color: #f0f5fa;">
                        {{ number_format($pct, 2, ',', '.') }}%
                    </td>

                    @if($isFirst)
                        <td rowspan="{{ $nSoggettiImmobile }}"
                            style="padding: 3px 4px; border: 1px solid {{ $sepLine }};
                                   text-align: right; font-weight: bold;
                                   background-color: {{ $iceBlue }}; color: {{ $navy }};
                                   vertical-align: middle; font-size: {{ $fontBase }};">
                            € {{ number_format($rigaImmobile['totale_immobile'] / 100, 2, ',', '.') }}
                        </td>
                    @endif
                </tr>
            @endforeach

            @if(!$loop->last)
                <tr><td colspan="{{ 6 + ($nCapChunk * 2) }}"
                        style="padding: 0; height: 3px; background-color: {{ $sepLine }}; border: none;"></td></tr>
            @endif
        @endforeach

        <tr style="font-weight: bold; color: #1a1a1a; font-size: {{ $fontBase }};">
            <td colspan="3" align="left" style="padding: 5px 6px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }};
                                    text-align: left; font-size: {{ $fontSmall }}; letter-spacing: 0.5px; background-color: #f7f9fb;">
                Totali generali
            </td>

            @foreach($capitoliChunk as $capId => $capInfo)
                @php
                    $totI = $totPerCap[$capId] ?? 0;
                @endphp
                <td align="right" style="padding: 5px 2px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                            font-size: {{ $fontSmall }}; background-color: #f7f9fb;">
                    
                </td>
                <td align="right" style="padding: 5px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                            font-size: {{ $fontSmall }}; background-color: #f7f9fb;">
                    € {{ number_format($totI / 100, 2, ',', '.') }}
                </td>
            @endforeach

            <td align="right" style="padding: 5px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                        font-size: {{ $fontSmall }}; background-color: #f7f9fb;">
                € {{ number_format($granTotale / 100, 2, ',', '.') }}
            </td>

            <td align="right" style="padding: 5px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                        font-size: {{ $fontTiny }}; background-color: #f7f9fb;">
                100%
            </td>

            <td align="right" style="padding: 5px 5px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }};
                        text-align: right; font-size: {{ $fontBase }}; background-color: #f7f9fb;">
                € {{ number_format($granTotale / 100, 2, ',', '.') }}
            </td>
        </tr>
    </tbody>
</table>

@endforeach

{{-- ── LEGENDA + NOTA ─────────────────────────────────────────────────────── --}}
<table style="width: 100%; border-collapse: collapse; margin-top: 7px;">
    <tr>
        <td style="width: 75%; vertical-align: top; font-size: {{ $fontTiny }}; color: #666; padding-right: 8px;">
            <strong>Legenda ruoli:</strong>
            <span style="color: #2a5080; font-weight: bold;">P</span> Proprietario &nbsp;
            <span style="color: #c07a30; font-weight: bold;">U</span> Usufruttuario &nbsp;
            <span style="color: #2a8050; font-weight: bold;">I</span> Inquilino
            <br>
            Gli importi sono in Euro (€). Le quote ‰ sono quelle registrate nelle tabelle di ripartizione. Le colonne contrassegnate con 'q.' indicano quote proporzionali, non millesimi. La colonna <em>% TOT.</em> indica la percentuale di ogni soggetto sul totale del piano rate.
        </td>
        <td style="width: 25%; vertical-align: bottom; font-size: {{ $fontTiny }}; color: #666; text-align: right;">
            Stampato il {{ now()->format('d/m/Y') }} alle {{ now()->format('H:i') }}
            @if($pianoRate->stato)
                <br>Stato: <strong>{{ ucfirst($pianoRate->stato->value ?? $pianoRate->stato) }}</strong>
            @endif
        </td>
    </tr>
</table>

@endif

{{-- ── NOTE LEGALI ────────────────────────────────────────────────────────── --}}
<div style="margin-top: 8px; font-size: {{ $fontTiny }}; color: #888;
             border-top: 1px solid #d0dce8; padding-top: 4px;">
    Documento redatto ai sensi dell'art. 1123 c.c. — La ripartizione è calcolata in base
    alle tabelle millesimali approvate in uso per l'esercizio indicato. In caso di discordanza
    fa fede il verbale assembleare di approvazione del bilancio preventivo.
</div>

@endsection
