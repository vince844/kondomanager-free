@extends('pdf.base')

@section('title', 'Riparto Bilancio per Tabella')

@section('content')
@php
    use Illuminate\Support\Str;

    $tabelle      = $matrice['tabelle'];
    $righe        = $matrice['righe'];
    $granTotale   = $matrice['gran_totale'];
    $totPerTab    = $matrice['tot_per_tabella'];
    $totQuotaTab  = $matrice['tot_quota_per_tabella'];
    $nTab         = count($tabelle);

    // ── Font adattivo ────────────────────────────────────────────────────────
    // Colonne di servizio ristrette (docs/stile_stampa_riparto_tabelle.md):
    // lo spazio recuperato consente un font più grande a parità di tabelle.
    //
    // ⚠️ Dalla 1.11.0-beta.27 il foglio è sempre A4 orizzontale e il corpo non scende mai sotto
    // 6,5pt: oltre otto tabelle si spezza in blocchi (`array_chunk` qui sotto), non si rimpicciolisce
    // il carattere. Fino a sei tabelle 7pt — è il corpo del programma con cui un amministratore ha
    // confrontato questa stampa, su carta — e il numero che decide è quello del blocco, non del
    // piano: prima si calcolava sul totale, e un piano da dieci tabelle spezzato in 8+2 stampava
    // anche il blocco da due al corpo più piccolo.
    $fontPer = function (int $n): array {
        return $n > 6
            ? ['6.5pt', '5.8pt', '5.2pt']
            : ['7pt', '6.2pt', '5.5pt'];
    };

    // ── Blocchi di pagina ────────────────────────────────────────────────────
    // Blocchi da otto al massimo, bilanciati (dieci = 5 + 5, non 8 + 2). Il blocco con i totali ha
    // tre colonne in più (fisse 45% contro 28%): con sette o otto tabelle «1.000,00» a 5,2pt vuole
    // 8,2 mm in una colonna da 7,8 (a otto 6,7) e «€ 1.021,25» a 6,5pt ne vuole 12,4 in una da 11,7
    // (10,0), e mPDF li spezza su due righe, `nowrap` o no. Si aggiunge un blocco finché l'ultimo —
    // il più corto — non scende a sei, la misura verificata sulla carta; gli altri restano a otto.
    $nBlocchi = max(1, (int) ceil($nTab / 8));
    while ($nBlocchi < $nTab && $nTab - ($nBlocchi - 1) * (int) ceil($nTab / $nBlocchi) > 6) {
        $nBlocchi++;
    }
    $perBlocco = $nTab > 0 ? (int) ceil($nTab / $nBlocchi) : 8;
    [$fontBase, $fontSmall, $fontTiny] = $fontPer($perBlocco);

    // ── Larghezze colonne (%) ────────────────────────────────────────────────
    // Le colonne di servizio contengono valori corti (sigla ruolo, percentuale,
    // importi): tenerle strette massimizza lo spazio per le tabelle millesimali.
    // ⚠️ Beta.27: la colonna dell'unità passa da 7 a 9 — a 7 «Appartamento 12» andava a capo e la
    // cella, con interno e piano sotto, decideva da sola l'altezza della riga; il nome del soggetto
    // cede due punti e il totale soggetto mezzo: «€ 12.255,00» in grassetto a 7pt misura 16,5 mm e
    // nel totale unità al 6,5% ne aveva 15,9, mentre il totale soggetto (corpo più piccolo) ha
    // margine. Fisse 45%, alle tabelle il 55%. La larghezza del nome dipende dal blocco, non dal piano.
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
    // Array [ "aid|iid" => percentuale_su_gran_totale ]
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

    // ── Conta unità e soggetti ────────────────────────────────────────────────
    $nUnita    = count($righe);
    $nSoggetti = array_sum(array_map(fn($r) => count($r['soggetti']), $righe));

    // ── Riferimento delibera (se presente) ───────────────────────────────────
    $dataDelibera = $pianoRate->data_delibera_assemblea?->format('d/m/Y') ?? null;
    $numVerbale   = $pianoRate->numero_verbale ?? null;

    // ── Nomi ruoli leggibili ──────────────────────────────────────────────────
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
        RIPARTO BILANCIO PREVENTIVO PER TABELLA E SOGGETTO
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

@if (empty($tabelle) || empty($righe))
    <p style="color: #888; font-style: italic; text-align: center; margin-top: 40px;">
        Nessun dato disponibile. Verificare che il piano rate abbia rate emesse e che il piano
        dei conti abbia tabelle millesimali configurate.
    </p>
@else

@php
    // Le tabelle si dividono in blocchi di pagina **bilanciati** (beta.27): dieci tabelle fanno
    // 5 + 5 e non 8 + 2 — una tabella sola nel secondo blocco è la stessa anomalia che la Coda 79
    // aveva trovato nella gemella. Fino alla beta.26 otto tabelle stavano in un blocco A3; oggi il
    // foglio è A4 e i blocchi sono la sola strada. Quanti e da quante tabelle è calcolato in testa
    // al template (`$nBlocchi`, `$perBlocco`), perché anche il corpo del carattere dipende da lì.
    $chunksTabelle = $nTab > 0 ? array_chunk($tabelle, $perBlocco, true) : [];
@endphp

@foreach($chunksTabelle as $chunkIndex => $tabelleChunk)
    @php
        $nTabChunk = count($tabelleChunk);
        // ⚠️ Coda 79 (beta.27): totale soggetto, % e totale unità, e la riga «Totali generali»,
        // valgono il documento intero. Stanno solo nell'ultimo blocco; i precedenti danno lo spazio
        // alle tabelle e lo dicono in calce.
        $ultimoBlocco = $loop->last;
        $wFisseBlocco = $wApp + $wNome + $wRuolo + ($ultimoBlocco ? $wTotSogg + $wPct + $wTotApt : 0);
        $wPerTab  = $nTabChunk > 0 ? max(5, (100 - $wFisseBlocco) / $nTabChunk) : 50;
        // 40/60 e non 44/56: «€ 1.021,25» a 7pt vuole 13 mm, una quota «159,57» ne vuole 8.
        $wQuota   = $wPerTab * 0.40;
        $wImporto = $wPerTab - $wQuota;
    @endphp

    @if(!$loop->first)
        <pagebreak />
        <div style="margin-bottom: 6px; border-bottom: 2px solid {{ $navy }}; padding-bottom: 4px;">
            <h2 style="margin: 0; padding: 0; font-size: 11pt; color: {{ $navy }}; letter-spacing: 0.5px;">
                RIPARTO BILANCIO PREVENTIVO PER TABELLA E SOGGETTO (Blocco {{ $chunkIndex + 1 }} di {{ count($chunksTabelle) }})
            </h2>
        </div>
    @endif

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- TABELLA PRINCIPALE (BLOCCO {{ $chunkIndex + 1 }})                           --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<table style="width: 100%; border-collapse: collapse; font-size: {{ $fontBase }}; line-height: 1.15;">

    {{-- ── THEAD ─────────────────────────────────────────────────────────── --}}
    <thead>
        {{-- Riga 1: nomi tabelle --}}
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

            @foreach($tabelleChunk as $tabId => $tabInfo)
                <th colspan="2" style="padding: 2px 3px; border: 1px solid {{ $navyMid }}; width: {{ $wPerTab }}%;
                                        text-align: center; font-size: {{ $fontSmall }}; white-space: nowrap; overflow: hidden;">
                    {{ Str::limit($tabInfo['nome'], $nTabChunk > 6 ? 12 : 22) }}
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
             la riga esce a 7pt in grassetto e «mill. ‰» si spezza su due righe. --}}
        <tr style="background-color: {{ $navyLt }}; color: {{ $navy }};">
            @foreach($tabelleChunk as $tabId => $tabInfo)
                @php
                    $etichetteUnita = [
                        'millesimi' => 'mill. ‰',
                        'quote'     => 'quote',
                        'persone'   => 'pers.',
                        'kwatt'     => 'kW',
                        'mtcubi'    => 'mc.',
                    ];
                    // Una colonna senza dimensione di riparto (l'addebito diretto) non ha
                    // quote da intitolare: meglio vuoto che un'etichetta che promette un numero.
                    $labelUnita = ($tabInfo['senza_quote'] ?? false)
                        ? ''
                        : ($etichetteUnita[$tabInfo['quota_tipo']] ?? 'quote');
                @endphp
                <th style="font-size: {{ $fontTiny }}; font-weight: normal; padding: 2px 2px; border: 1px solid {{ $navyMid }}; text-align: right; width: {{ $wQuota }}%; opacity: 0.9;">
                    {{ $labelUnita }}
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

                    // Ruolo → colore accent sinistra
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

                    {{-- ── Colonna App. (rowspan) ─────────────────────── --}}
                    @if($isFirst)
                        <td rowspan="{{ $nSoggettiImmobile }}"
                            style="padding: 1.5px 2px; border: 1px solid {{ $sepLine }};
                                   text-align: center; color: {{ $navy }};
                                   background-color: {{ $iceBlue }}; vertical-align: middle;
                                   border-left: 3px solid {{ $navy }};">
                            @php
                                // Una riga per il nome e UNA sola, piccola, per interno e piano — e solo se
                                // ci sono: fino alla beta.26 la cella era alta tre righe anche per un box
                                // senza interno («Int. —» stampato a vuoto), e su 48 righe erano le celle
                                // delle unità a decidere l'altezza della pagina. `$dettagliUnita` unisce
                                // ciò che esiste con un punto mediano.
                                $piano = trim((string) ($rigaImmobile['piano'] ?? ''));
                                // Il campo è testo libero: «1», «T» dal form e dall'importatore, «Primo piano»
                                // dai seeder. L'etichetta si premette solo se il valore non la porta già,
                                // così non esce né «int. 3 · 1» né «Piano Primo piano».
                                $etichettaPiano = $piano === '' ? null
                                    : (str_contains(mb_strtolower($piano), 'piano') ? $piano : 'piano '.$piano);
                                $dettagliUnita = implode(' · ', array_filter([
                                    $rigaImmobile['interno'] ? 'int. '.$rigaImmobile['interno'] : null,
                                    $etichettaPiano !== null ? Str::limit($etichettaPiano, 18) : null,
                                ]));
                            @endphp
                            @if(!empty($rigaImmobile['nome_immobile']))
                                {{-- Identità primaria allineata alla vista a schermo: nome unità in testa --}}
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

                    {{-- ── Dati per tabella ────────────────────────── --}}
                    @foreach($tabelleChunk as $tabId => $tabInfo)
                        @php
                            $datiTab  = $soggetto['per_tabella'][$tabId] ?? null;
                            $quota    = $datiTab ? $datiTab['quota'] : null;
                            $importo  = $datiTab ? $datiTab['importo'] : 0;
                            $decimali = $tabInfo['decimali'] ?? 2;
                        @endphp

                        {{-- quota ‰ (Stampata solo sulla prima riga dell'immobile, unificata) --}}
                        @if($isFirst)
                            @php
                                /*
                                 * ⚠️ **La leggibilità guarda la PRESENZA in tabella, non l'importo.**
                                 * Fino alla beta.63 era `!is_null($quota) && $totImmTab > 0`: una
                                 * quota a zero — legittima, e dalla beta.61 il modo dichiarato per
                                 * documentare che un'unità è stata considerata e non partecipa —
                                 * usciva nel grigio del «non c'è niente», illeggibile su fondo
                                 * chiaro. Si registrava lo zero sulla carta e poi lo si nascondeva.
                                 *
                                 * ⛔ **E il commento qui è PHP, non Blade.** La prima stesura usava
                                 * `{{-- … --}}` dentro questo `@php`: `storeUncompiledBlocks()` gira
                                 * **prima** di `compileComments()`, quindi un commento Blade dentro
                                 * un blocco `@php` finisce verbatim nel PHP compilato e il template
                                 * non si apre più — 500 su **ogni** stampa di riparto, di ogni
                                 * piano. L'ha preso la revisione avversariale della .63.
                                 */
                                $hasQuota = !is_null($quota);
                            @endphp
                            <td rowspan="{{ $nSoggettiImmobile }}" style="padding: 1.5px 2px; border: 1px solid {{ $sepLine }};
                                       text-align: right; font-size: {{ $fontSmall }};
                                       color: {{ $hasQuota ? '#555' : '#ddd' }};
                                       background-color: {{ $hasQuota ? 'transparent' : '#fafafa' }}; vertical-align: middle;">
                                @if(!is_null($quota))
                                    {{ number_format((float)$quota, $decimali, ',', '.') }}
                                @else
                                    —
                                @endif
                            </td>
                        @endif

                        {{-- importo --}}
                        <td style="padding: 1.5px 3px; border: 1px solid {{ $sepLine }};
                                   text-align: right; background-color: {{ $bgRow }};
                                   color: {{ $navy }}; font-size: {{ $fontBase }};">
                            {{-- ⚠️ Non «> 0»: un importo negativo è un credito, cioè un valore
                                 reale, e il totale di riga qui a destra lo somma comunque.
                                 Nasconderlo nella cella e contarlo nel totale produce una riga
                                 che non torna in orizzontale — su un foglio che va in assemblea
                                 significa un documento che non si può ricontrollare a mano.
                                 Lo zero invece resta un trattino: vuol dire «non partecipa». --}}
                            @if($importo != 0)
                                € {{ number_format($importo / 100, 2, ',', '.') }}
                            @else
                                —
                            @endif
                        </td>
                    @endforeach

                    @if($ultimoBlocco)
                    {{-- ── Totale Soggetto ─────────────────────────────── --}}
                    <td style="padding: 1.5px 3px; border: 1px solid {{ $sepLine }};
                               text-align: right; font-weight: bold; font-size: {{ $fontSmall }};
                               color: {{ $navy }}; background-color: #fafafa; white-space: nowrap;">
                        € {{ number_format($soggetto['totale'] / 100, 2, ',', '.') }}
                    </td>

                    {{-- ── % sul totale ─────────────────────────────── --}}
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

            {{-- Separatore visivo tra appartamenti --}}
            @if(!$loop->last)
                <tr><td colspan="{{ ($ultimoBlocco ? 6 : 3) + ($nTabChunk * 2) }}"
                        style="padding: 0; height: 1px; background-color: {{ $sepLine }}; border: none;"></td></tr>
            @endif
        @endforeach

        {{-- ── RIGA TOTALI (inserita nel tbody per compatibilità mPDF) ───────── --}}
        <tr style="font-weight: bold; color: #1a1a1a; font-size: {{ $fontBase }};">
            <td colspan="3" align="left" style="padding: 3px 6px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }};
                                    text-align: left; font-size: {{ $fontSmall }}; letter-spacing: 0.5px; background-color: #f7f9fb;">
                {{ $ultimoBlocco ? 'Totali generali' : 'Totali delle colonne di questo blocco' }}
            </td>

            @foreach($tabelleChunk as $tabId => $tabInfo)
                @php
                    $totQ = $totQuotaTab[$tabId] ?? 0;
                    $totI = $totPerTab[$tabId] ?? 0;
                    $decimali = $tabInfo['decimali'] ?? 2;
                @endphp
                {{-- Corpo piccolo e mai a capo: «1.342,00» nella colonna dei metri cubi andava su due righe. --}}
                <td align="right" style="padding: 3px 2px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                            font-size: {{ $fontTiny }}; background-color: #f7f9fb; white-space: nowrap;">
                    {{ ($tabInfo['senza_quote'] ?? false) ? '—' : number_format((float)$totQ, $decimali, ',', '.') }}
                </td>
                <td align="right" style="padding: 3px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                            font-size: {{ $fontSmall }}; background-color: #f7f9fb; white-space: nowrap;">
                    € {{ number_format($totI / 100, 2, ',', '.') }}
                </td>
            @endforeach

            @if($ultimoBlocco)
            {{-- Totale soggetto (Gran Totale) --}}
            <td align="right" style="padding: 3px 3px; border: 1px solid {{ $navyMid }}; border-top: 2px solid {{ $navy }}; text-align: right;
                        font-size: {{ $fontSmall }}; background-color: #f7f9fb;">
                € {{ number_format($granTotale / 100, 2, ',', '.') }}
            </td>

            {{-- % totale (sempre 100%) --}}
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
            Continua nel blocco successivo: i totali per soggetto e per unità, che valgono tutte le tabelle del documento, stanno nell'ultimo blocco.
        </div>
    @endif

@endforeach

{{-- ── LEGENDA + NOTA ─────────────────────────────────────────────────────── --}}
{{-- Beta.27: una riga sola. «Stampato il … alle …» ripeteva il piè di pagina («Documento emesso il»);
     lo stato del piano resta, perché su un documento d'assemblea conta. --}}
<div style="margin-top: 4px; font-size: {{ $fontTiny }}; color: #666;">
    <strong>Legenda ruoli:</strong>
    <span style="color: #2a5080; font-weight: bold;">P</span> Proprietario &nbsp;
    <span style="color: #7a56a8; font-weight: bold;">NP</span> Nudo proprietario &nbsp;
    <span style="color: #c07a30; font-weight: bold;">U</span> Usufruttuario &nbsp;
    <span style="color: #2a8050; font-weight: bold;">I</span> Inquilino &nbsp;·&nbsp;
    Importi in euro; le quote sono quelle delle tabelle di ripartizione (mill. ‰, quote, pers., kW o mc. secondo la tabella); <em>% TOT.</em> è la parte di ogni soggetto sul totale del piano rate.
    @if($pianoRate->stato)
        &nbsp;·&nbsp; Stato del piano: <strong>{{ ucfirst($pianoRate->stato->value ?? $pianoRate->stato) }}</strong>
    @endif
    @php
        // La fonte del documento (beta.29): registrato alla generazione, ricostruito dai dati di
        // oggi, o anteprima di un piano mai generato. Lo dice la matrice, non il template.
        $fonte = $matrice['fonte'] ?? ['tipo' => 'ricostruito', 'generato_il' => null];
        $generatoIl = $fonte['generato_il'] ?? null;
        $generatoIl = $generatoIl ? \Illuminate\Support\Carbon::parse($generatoIl)->format('d/m/Y') : null;
    @endphp
    &nbsp;·&nbsp;
    @if(($fonte['tipo'] ?? null) === 'registrato')
        Riparto <strong>registrato</strong> alla generazione del {{ $generatoIl }}
    @elseif(($fonte['tipo'] ?? null) === 'anteprima')
        <strong>Anteprima</strong> — piano non ancora generato
    @else
        Riparto <strong>ricostruito</strong> dai dati attuali, non registrato
    @endif
    @php $pseudo = [\App\Services\RipartoTabelleService::COLONNA_GIA_VERSATO, \App\Services\RipartoTabelleService::COLONNA_PREGRESSO, \App\Services\RipartoTabelleService::COLONNA_DIRETTO, \App\Services\RipartoTabelleService::COLONNA_FUORI_RIPARTO]; @endphp
    @if(array_intersect_key($tabelle, array_flip($pseudo)))
        <br>
        @if(isset($tabelle[\App\Services\RipartoTabelleService::COLONNA_GIA_VERSATO]))
            <strong>Già versato:</strong> quanto l'unità aveva già corrisposto verso queste voci, che viene scomputato dal dovuto. Segue l'unità e non la persona (art. 63 disp. att. c.c.). Le colonne delle tabelle restano al deliberato.&nbsp;
        @endif
        @if(isset($tabelle[\App\Services\RipartoTabelleService::COLONNA_PREGRESSO]))
            <strong>Saldi precedenti:</strong> i saldi delle gestioni chiuse, che non appartengono a nessuna tabella del preventivo corrente.&nbsp;
        @endif
        @if(isset($tabelle[\App\Services\RipartoTabelleService::COLONNA_DIRETTO]))
            <strong>Addebito diretto:</strong> spese di una sola unità, con la riga di fattura, che nessuna tabella ripartisce.&nbsp;
        @endif
        @if(isset($tabelle[\App\Services\RipartoTabelleService::COLONNA_FUORI_RIPARTO]))
            <strong>Fuori riparto:</strong> importo addebitato che il riparto ricostruito non spiega (dati cambiati dopo la generazione).
        @endif
    @endif
</div>

@endif

{{-- ── NOTE LEGALI ────────────────────────────────────────────────────────── --}}
<div style="margin-top: 4px; font-size: {{ $fontTiny }}; color: #888;
             border-top: 1px solid #d0dce8; padding-top: 3px;">
    Documento redatto ai sensi dell'art. 1123 c.c. — La ripartizione è calcolata in base
    @if(($fonte['tipo'] ?? null) === 'registrato')
    alle tabelle millesimali in uso al momento della generazione ({{ $generatoIl }}).
    @else
    alle tabelle millesimali approvate in uso per l'esercizio indicato.
    @endif
    In caso di discordanza fa fede il verbale assembleare di approvazione del bilancio preventivo.
</div>

@endsection
