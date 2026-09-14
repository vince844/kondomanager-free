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

    // ── Blocchi e font ───────────────────────────────────────────────────────
    // ⚠️ Beta.27: foglio sempre A4 orizzontale, corpo mai sotto 6,5pt (fino a sei colonne 7pt).
    // I capitoli si dividono in blocchi **bilanciati**: sette colonne fanno 4 + 3 e non 6 + 1 —
    // la Coda 79 aveva trovato una pseudo-colonna («Già versato») stampata da sola nel secondo
    // blocco. Il corpo si decide sul blocco più largo, non sul totale delle colonne.
    $nBlocchi   = max(1, (int) ceil($nCap / 6));
    $perBlocco  = $nCap > 0 ? (int) ceil($nCap / $nBlocchi) : 6;
    $chunksCapitoli = $nCap > 0 ? array_chunk($capitoli, $perBlocco, true) : [];
    $fontPer = function (int $n): array {
        return $n > 6
            ? ['6.5pt', '5.8pt', '5.2pt']
            : ['7pt', '6.2pt', '5.5pt'];
    };
    [$fontBase, $fontSmall, $fontTiny] = $fontPer($perBlocco);

    // ── Larghezze colonne (%) ────────────────────────────────────────────────
    // Come la gemella per tabelle (beta.27): l'unità ha spazio per il suo nome su una riga, e il
    // totale unità (7%) tiene «€ 12.255,00» in grassetto a 7pt, che al 6,5% andava a capo.
    $wApp    = 9;
    $wNome   = $perBlocco > 5 ? 16 : 20;
    $wRuolo  = 3;
    $wTotSogg= 6.5;
    $wPct    = 3.5;
    $wTotApt = 7;
    // Le larghezze interne si calcolano per ogni blocco, perché i totali stanno solo nell'ultimo.

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
        'nuda_proprietario' => 'Nudi proprietari',
        'usufruttuario'     => 'Usufruttuari',
        'inquilino'         => 'Inquilini',
    ];
@endphp

{{-- INTESTAZIONE DOCUMENTO --}}
<div style="margin-bottom: 6px; border-bottom: 2px solid {{ $navy }}; padding-bottom: 4px;">
    <h2 style="margin: 0; padding: 0; font-size: 11pt; color: {{ $navy }}; letter-spacing: 0.5px;">
        RIPARTO BILANCIO PREVENTIVO PER CAPITOLO E SOGGETTO
    </h2>
    <div style="font-size: 7.5pt; color: #444; margin-top: 2px;">
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
<table style="width: 100%; border-collapse: separate; border-spacing: 3px 0; margin-bottom: 4px;">
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
                    border-radius: 4px; padding: 2px 6px; text-align: center; vertical-align: middle;
                    font-size: {{ $fontSmall }}; color: {{ $c['txt'] }}; white-space: nowrap;">
            <strong>{{ $nomiRuoli[$ruolo] ?? ucfirst($ruolo) }}</strong>
            &nbsp;€ {{ number_format($importoRuolo / 100, 2, ',', '.') }}
            <span style="color: {{ $c['bd'] }};">&nbsp;{{ number_format($pctRuolo, 1, ',', '.') }}%</span>
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

@foreach($chunksCapitoli as $chunkIndex => $capitoliChunk)
    @php
        $nCapChunk = count($capitoliChunk);
        // ⚠️ Coda 79 (beta.27): le tre colonne dei totali — totale soggetto, % e totale unità — e la
        // riga «Totali generali» valgono il documento intero, non il blocco. Stampate su ogni
        // blocco, la seconda pagina affermava un totale che le sue colonne contraddicevano. Ora
        // stanno **solo nell'ultimo blocco**, dove chi legge ha visto tutte le colonne; i blocchi
        // precedenti lo dicono in calce e danno lo spazio ai capitoli.
        $ultimoBlocco = $loop->last;
        $wFisseBlocco = $wApp + $wNome + $wRuolo + ($ultimoBlocco ? $wTotSogg + $wPct + $wTotApt : 0);
        $wPerTab  = $nCapChunk > 0 ? max(5, (100 - $wFisseBlocco) / $nCapChunk) : 50;
        $wQuota   = $wPerTab * 0.40;
        $wImporto = $wPerTab - $wQuota;
    @endphp

    @if(!$loop->first)
        <pagebreak />
        <div style="margin-bottom: 6px; border-bottom: 2px solid {{ $navy }}; padding-bottom: 4px;">
            <h2 style="margin: 0; padding: 0; font-size: 11pt; color: {{ $navy }}; letter-spacing: 0.5px;">
                RIPARTO BILANCIO PREVENTIVO PER CAPITOLO E SOGGETTO (Blocco {{ $chunkIndex + 1 }} di {{ count($chunksCapitoli) }})
            </h2>
        </div>
    @endif

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- TABELLA PRINCIPALE (BLOCCO {{ $chunkIndex + 1 }})                           --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<table style="width: 100%; border-collapse: collapse; font-size: {{ $fontBase }}; line-height: 1.15;">

    {{-- ── THEAD ─────────────────────────────────────────────────────────── --}}
    <thead>
        {{-- Riga 1: nomi capitoli --}}
        <tr style="background-color: {{ $navyLt }}; color: {{ $navy }};">
            <th rowspan="2" style="padding: 2px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wApp }}%;
                                    text-align: center; vertical-align: middle;">
                Unità
            </th>
            <th rowspan="2" style="padding: 2px 5px; border: 1px solid {{ $navyMid }}; width: {{ $wNome }}%;
                                    text-align: left; vertical-align: middle;">
                Condòmino / Soggetto
            </th>
            <th rowspan="2" style="padding: 2px 2px; border: 1px solid {{ $navyMid }}; width: {{ $wRuolo }}%;
                                    text-align: center; vertical-align: middle; font-size: {{ $fontTiny }};">
                Ruolo
            </th>

            @foreach($capitoliChunk as $capId => $capInfo)
                <th colspan="2" style="padding: 2px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wPerTab }}%;
                                        text-align: center; font-size: {{ $fontSmall }}; white-space: nowrap; overflow: hidden;">
                    {{ Str::limit($capInfo['nome'], $nCapChunk > 6 ? 12 : 22) }}
                </th>
            @endforeach

            @if($ultimoBlocco)
            <th rowspan="2" style="padding: 2px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wTotSogg }}%;
                                    text-align: right; vertical-align: middle; background-color: #f0f5fa;
                                    font-size: {{ $fontSmall }};">
                TOT. SOGG.
            </th>
            <th rowspan="2" style="padding: 2px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wPct }}%;
                                    text-align: center; vertical-align: middle; background-color: {{ $totEmph }};
                                    font-size: {{ $fontTiny }};">
                % TOT.
            </th>
            <th rowspan="2" style="padding: 2px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wTotApt }}%;
                                    text-align: right; vertical-align: middle; background-color: {{ $totEmph }};
                                    font-size: {{ $fontSmall }};">
                TOT. IMMOB.
            </th>
            @endif
        </tr>

        {{-- Riga 2: mill. / importo sub-header --}}
        {{-- mPDF non applica lo style del <tr> alle celle: corpo e peso vanno sui <th>, altrimenti
             la riga esce a 7pt in grassetto e l'etichetta della quota si spezza su due righe. --}}
        <tr style="background-color: {{ $navyLt }}; color: {{ $navy }};">
            @foreach($capitoliChunk as $capId => $capInfo)
                <th style="font-size: {{ $fontTiny }}; font-weight: normal; padding: 2px 2px; border: 1px solid {{ $navyMid }}; text-align: right; width: {{ $wQuota }}%; opacity: 0.9;">
                    {{ $capInfo['quota_label'] }}
                </th>
                <th style="font-size: {{ $fontTiny }}; font-weight: normal; padding: 2px 2px; border: 1px solid {{ $navyMid }}; text-align: right; width: {{ $wImporto }}%; opacity: 0.9;">
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
                        @php
                            // Come la gemella per tabelle (beta.27): il nome dell'unità in testa, e una
                            // sola riga piccola con interno e piano, solo se ci sono. `?:` e non `??`:
                            // un interno assente arriva come stringa vuota. Il piano è testo libero
                            // («1», «T», «Primo piano»): l'etichetta si premette solo se manca.
                            $piano = trim((string) ($rigaImmobile['piano'] ?? ''));
                            $etichettaPiano = $piano === '' ? null
                                : (str_contains(mb_strtolower($piano), 'piano') ? $piano : 'piano '.$piano);
                            $dettagliUnita = implode(' · ', array_filter([
                                $rigaImmobile['interno'] ? 'int. '.$rigaImmobile['interno'] : null,
                                $etichettaPiano !== null ? Str::limit($etichettaPiano, 18) : null,
                            ]));
                        @endphp
                        <td rowspan="{{ $nSoggettiImmobile }}"
                            style="padding: 1.5px 2px; border: 1px solid {{ $sepLine }};
                                   text-align: center; color: {{ $navy }};
                                   background-color: {{ $iceBlue }}; vertical-align: middle;
                                   border-left: 3px solid {{ $navy }};">
                            @if(!empty($rigaImmobile['nome_immobile']))
                                <span style="font-weight: bold; font-size: {{ $fontSmall }};">{{ Str::limit($rigaImmobile['nome_immobile'], 24) }}</span>
                                @if($dettagliUnita !== '')
                                    <br><span style="font-weight: normal; font-size: {{ $fontTiny }}; color: #666;">{{ $dettagliUnita }}</span>
                                @endif
                            @else
                                <span style="font-weight: bold; font-size: {{ $fontBase }};">{{ $rigaImmobile['interno'] ?: '—' }}</span>
                                @if($etichettaPiano !== null)
                                    <br><span style="font-weight: normal; font-size: {{ $fontTiny }}; color: #666;">{{ Str::limit($etichettaPiano, 18) }}</span>
                                @endif
                            @endif
                        </td>
                    @endif

                    {{-- ── Nome soggetto ────────────────────────────── --}}
                    <td style="padding: 1.5px 4px; border: 1px solid {{ $sepLine }};
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
                    <td style="padding: 1.5px 2px; border: 1px solid {{ $sepLine }};
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
                        @endphp

                        @if($isFirst)
                            @php
                                /*
                                 * Un millesimo scritto — anche se è zero — si legge in nero.
                                 * Lo sbiadito resta a chi nella tabella non c'è (`null`).
                                 *
                                 * Fino alla beta.63 la condizione chiedeva anche un importo
                                 * addebitato: uno zero documentato usciva quindi grigio
                                 * chiarissimo su fondo chiaro, cioè illeggibile proprio nel
                                 * documento che va in assemblea. Gemella della stessa riga in
                                 * `riparto_tabelle.blade.php`.
                                 *
                                 * ⚠️ I commenti qui dentro si scrivono in PHP e non in Blade:
                                 * il compilatore estrae il blocco `@php` prima di compilare i
                                 * commenti, e un `{{-- --}}` finirebbe verbatim nel PHP generato
                                 * facendo rispondere 500 all'intera stampa.
                                 */
                                $hasQuota = !is_null($quota);
                            @endphp
                            <td rowspan="{{ $nSoggettiImmobile }}" style="padding: 1.5px 2px; border: 1px solid {{ $sepLine }};
                                       text-align: right; font-size: {{ $fontSmall }};
                                       color: {{ $hasQuota ? '#555' : '#ddd' }};
                                       background-color: {{ $hasQuota ? 'transparent' : '#fafafa' }}; vertical-align: middle;">
                                @if(!is_null($quota))
                                    {{ number_format((float)$quota, (int) ($capInfo['decimali'] ?? 2), ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                        @endif

                        <td style="padding: 1.5px 3px; border: 1px solid {{ $sepLine }};
                                   text-align: right; background-color: {{ $bgRow }};
                                   color: {{ $navy }}; font-size: {{ $fontBase }}; white-space: nowrap;">
                            {{-- ⚠️ Non «> 0»: un importo negativo è un credito, cioè un valore
                                 reale che il totale di riga somma comunque. Nasconderlo nella
                                 cella e contarlo nel totale produce una riga che non torna in
                                 orizzontale, su un foglio che va in assemblea. Lo zero invece
                                 resta un trattino: vuol dire «non partecipa». --}}
                            @if($importo != 0)
                                € {{ number_format($importo / 100, 2, ',', '.') }}
                            @else
                                —
                            @endif
                        </td>
                    @endforeach

                    @if($ultimoBlocco)
                    <td style="padding: 1.5px 3px; border: 1px solid {{ $sepLine }};
                               text-align: right; font-weight: bold; font-size: {{ $fontSmall }};
                               color: {{ $navy }}; background-color: #fafafa; white-space: nowrap;">
                        € {{ number_format($soggetto['totale'] / 100, 2, ',', '.') }}
                    </td>

                    <td style="padding: 1.5px 3px; border: 1px solid {{ $sepLine }};
                               text-align: right; font-size: {{ $fontTiny }};
                               color: #666; background-color: #f0f5fa;">
                        {{ number_format($pct, 2, ',', '.') }}%
                    </td>

                    @if($isFirst)
                        <td rowspan="{{ $nSoggettiImmobile }}"
                            style="padding: 1.5px 4px; border: 1px solid {{ $sepLine }};
                                   text-align: right; font-weight: bold;
                                   background-color: {{ $iceBlue }}; color: {{ $navy }};
                                   vertical-align: middle; font-size: {{ $fontBase }}; white-space: nowrap;">
                            € {{ number_format($rigaImmobile['totale_immobile'] / 100, 2, ',', '.') }}
                        </td>
                    @endif
                    @endif
                </tr>
            @endforeach

            @if(!$loop->last)
                <tr><td colspan="{{ ($ultimoBlocco ? 6 : 3) + ($nCapChunk * 2) }}"
                        style="padding: 0; height: 1px; background-color: {{ $sepLine }}; border: none;"></td></tr>
            @endif
        @endforeach

        <tr style="font-weight: bold; color: #1a1a1a; font-size: {{ $fontBase }};">
            <td colspan="3" align="left" style="padding: 3px 6px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }};
                                    text-align: left; font-size: {{ $fontSmall }}; letter-spacing: 0.5px; background-color: #f7f9fb;">
                {{ $ultimoBlocco ? 'Totali generali' : 'Totali delle colonne di questo blocco' }}
            </td>

            @foreach($capitoliChunk as $capId => $capInfo)
                @php
                    $totI = $totPerCap[$capId] ?? 0;
                @endphp
                <td align="right" style="padding: 3px 2px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                            font-size: {{ $fontSmall }}; background-color: #f7f9fb;">
                    
                </td>
                <td align="right" style="padding: 3px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                            font-size: {{ $fontSmall }}; background-color: #f7f9fb; white-space: nowrap;">
                    € {{ number_format($totI / 100, 2, ',', '.') }}
                </td>
            @endforeach

            @if($ultimoBlocco)
            <td align="right" style="padding: 3px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                        font-size: {{ $fontSmall }}; background-color: #f7f9fb;">
                € {{ number_format($granTotale / 100, 2, ',', '.') }}
            </td>

            <td align="right" style="padding: 3px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                        font-size: {{ $fontTiny }}; background-color: #f7f9fb;">
                100%
            </td>

            <td align="right" style="padding: 3px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }};
                        text-align: right; font-size: {{ $fontBase }}; background-color: #f7f9fb;">
                € {{ number_format($granTotale / 100, 2, ',', '.') }}
            </td>
            @endif
        </tr>
    </tbody>
</table>

    @if(! $ultimoBlocco)
        <div style="margin-top: 3px; font-size: {{ $fontTiny }}; color: #666;">
            Continua nel blocco successivo: i totali per soggetto e per unità, che valgono tutte le colonne del documento, stanno nell'ultimo blocco.
        </div>
    @endif

@endforeach

{{-- ── LEGENDA + NOTA ─────────────────────────────────────────────────────── --}}
{{-- Beta.27: compatta, senza «Stampato il … alle …» che ripeteva il piè di pagina. --}}
<div style="margin-top: 4px; font-size: {{ $fontTiny }}; color: #666;">
    <strong>Legenda ruoli:</strong>
    <span style="color: #2a5080; font-weight: bold;">P</span> Proprietario &nbsp;
    <span style="color: #7a56a8; font-weight: bold;">NP</span> Nudo proprietario &nbsp;
    <span style="color: #c07a30; font-weight: bold;">U</span> Usufruttuario &nbsp;
    <span style="color: #2a8050; font-weight: bold;">I</span> Inquilino &nbsp;·&nbsp;
    Importi in euro; le quote sono quelle delle tabelle di ripartizione (millesimi, quote, persone, kW o metri cubi secondo la colonna; «—» quando il capitolo raggruppa voci su tabelle diverse); <em>% TOT.</em> è la parte di ogni soggetto sul totale del piano rate.
    @if($pianoRate->stato)
        &nbsp;·&nbsp; Stato del piano: <strong>{{ ucfirst($pianoRate->stato->value ?? $pianoRate->stato) }}</strong>
    @endif
    @if(isset($capitoli[\App\Services\RipartoCapitoliService::COLONNA_GIA_VERSATO]) || isset($capitoli[\App\Services\RipartoCapitoliService::COLONNA_PREGRESSO]))
        <br>
        @if(isset($capitoli[\App\Services\RipartoCapitoliService::COLONNA_GIA_VERSATO]))
            <strong>Già versato:</strong> quanto l'unità aveva già corrisposto verso queste voci, che viene scomputato dal dovuto. Segue l'unità e non la persona (art. 63 disp. att. c.c.). Le colonne dei capitoli restano al deliberato.&nbsp;
        @endif
        @if(isset($capitoli[\App\Services\RipartoCapitoliService::COLONNA_PREGRESSO]))
            <strong>Saldi precedenti:</strong> i saldi delle gestioni chiuse, che non appartengono a nessuna voce del preventivo corrente.
        @endif
    @endif
</div>

@endif

{{-- ── NOTE LEGALI ────────────────────────────────────────────────────────── --}}
<div style="margin-top: 4px; font-size: {{ $fontTiny }}; color: #888;
             border-top: 1px solid #d0dce8; padding-top: 3px;">
    Documento redatto ai sensi dell'art. 1123 c.c. — La ripartizione è calcolata in base
    alle tabelle millesimali approvate in uso per l'esercizio indicato. In caso di discordanza
    fa fede il verbale assembleare di approvazione del bilancio preventivo.
</div>

@endsection
