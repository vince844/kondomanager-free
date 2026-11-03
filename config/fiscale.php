<?php

/**
 * Dati fiscali che DESCRIVONO, non GUIDANO comportamento: codici tributo,
 * aliquote storicizzate, festività, tassi legali. Vivono in config (versionati
 * in git) e non in tabelle — sono dati globali, non per-condominio, e la loro
 * storia sta già nel version control. Se manca una riga di aliquota valida
 * alla data richiesta, il calcolo deve fermarsi (mai un default silenzioso).
 *
 * Design: docs/design/f24_ritenute_design.md §2.3.
 */

return [

    'codici_tributo' => [
        '1001' => ['descrizione' => 'Ritenute su retribuzioni lavoro dipendente', 'sezione' => 'erario'],
        '1019' => ['descrizione' => 'Ritenuta su appalti (persone fisiche/società di persone IRPEF)', 'sezione' => 'erario'],
        '1020' => ['descrizione' => 'Ritenuta su appalti (soggetti IRES)', 'sezione' => 'erario'],
        '1038' => ['descrizione' => 'Ritenuta lavoro autonomo (soppresso)', 'sezione' => 'erario', 'soppresso_dal' => '2017-01-01'],
        '1040' => ['descrizione' => 'Ritenuta su redditi di lavoro autonomo (art. 25 DPR 600/1973; dal 1° gennaio 2027 art. 38 D.Lgs. 33/2025) e su provvigioni (art. 25-bis DPR 600/1973; dal 1° gennaio 2027 art. 39 D.Lgs. 33/2025)', 'sezione' => 'erario'],
        '8947' => ['descrizione' => 'Sanzione ravvedimento — interessi', 'sezione' => 'erario', 'sanzione' => true, 'auto_precompila' => false],
        '8948' => ['descrizione' => 'Sanzione ravvedimento — imposte sostitutive', 'sezione' => 'erario', 'sanzione' => true, 'auto_precompila' => false],
        '8949' => ['descrizione' => 'Sanzione ravvedimento — altre imposte dirette', 'sezione' => 'erario', 'sanzione' => true, 'auto_precompila' => false],
    ],

    // Storicizzate: [regime][] => ['dal', 'al'?, 'aliquota', 'base', 'titolo', 'norma'].
    // TipoRitenuta::aliquota() cerca la prima riga con dal <= data <= (al ?? +inf).
    'aliquote' => [
        'appalto_4' => [
            ['dal' => '2007-01-01', 'aliquota' => 4.0, 'base' => 100, 'titolo' => 'acconto'],
        ],
        'lavoro_autonomo_20' => [
            ['dal' => '1973-09-29', 'aliquota' => 20.0, 'base' => 100, 'titolo' => 'acconto'],
        ],
        'provvigioni_base_50' => [
            ['dal' => '2007-01-01', 'aliquota' => 23.0, 'base' => 50, 'titolo' => 'acconto'],
        ],
        'provvigioni_base_20' => [
            ['dal' => '2007-01-01', 'aliquota' => 23.0, 'base' => 20, 'titolo' => 'acconto'],
        ],
        'non_residente_30' => [
            ['dal' => '1973-09-29', 'aliquota' => 30.0, 'base' => 100, 'titolo' => 'imposta'],
        ],
        'lavoro_dipendente' => [
            ['dal' => '1973-09-29', 'aliquota' => 0.0, 'base' => 100, 'titolo' => 'acconto'],
        ],
    ],

    // Plafond in centesimi, coerente col resto del progetto. null = mensile sempre.
    'plafond' => [
        'appalto_4' => 50000,
        'lavoro_autonomo_20' => 10000,
        'provvigioni_base_50' => 10000,
        'provvigioni_base_20' => 10000,
        'non_residente_30' => 10000,
        'lavoro_dipendente' => null,
    ],

    // Dal 1° gennaio 2027 si applica il testo unico dei versamenti e della riscossione, D.Lgs. 24 marzo 2025, n. 33
    // (art. 243 c. 1, «1° gennaio 2026» sostituito con «1° gennaio 2027» dall'art. 4 c. 4 del D.L. 31 dicembre 2025, n. 200,
    // convertito dalla legge 27 febbraio 2026, n. 26), che da quella data abroga gli artt. 23–30 del DPR 600/1973 (art. 241
    // c. 1 lett. b). Ogni rubrica del testo unico cita l'articolo del DPR che sostituisce: art. 33 (art. 23), art. 38 (art.
    // 25: c. 1 lavoro autonomo, c. 2 non residenti), art. 39 (art. 25-bis), art. 40 (art. 25-ter). Se la data cambiasse
    // ancora, si cambiano qui le righe `fino_al` e `dal`, tutte insieme.
    'riferimenti_normativi' => [
        'appalto_4' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 25-ter DPR 600/1973'],
            ['dal' => '2027-01-01', 'testo' => 'art. 40 D.Lgs. 33/2025'],
        ],
        'lavoro_autonomo_20' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 25 DPR 600/1973'],
            ['dal' => '2027-01-01', 'testo' => 'art. 38, comma 1, D.Lgs. 33/2025'],
        ],
        'provvigioni_base_50' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 25-bis DPR 600/1973'],
            ['dal' => '2027-01-01', 'testo' => 'art. 39 D.Lgs. 33/2025'],
        ],
        'provvigioni_base_20' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 25-bis DPR 600/1973 (dichiarazione percipiente)'],
            ['dal' => '2027-01-01', 'testo' => 'art. 39 D.Lgs. 33/2025 (dichiarazione percipiente)'],
        ],
        'non_residente_30' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 25 DPR 600/1973 (non residenti)'],
            ['dal' => '2027-01-01', 'testo' => 'art. 38, comma 2, D.Lgs. 33/2025 (non residenti)'],
        ],
        'lavoro_dipendente' => [
            ['fino_al' => '2026-12-31', 'testo' => 'art. 23 DPR 600/1973'],
            ['dal' => '2027-01-01', 'testo' => 'art. 33 D.Lgs. 33/2025'],
        ],
    ],

    'festivita_nazionali' => [
        '01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26',
    ],

    'interessi_legali' => [
        ['dal' => '2024-01-01', 'tasso' => 2.5],
        ['dal' => '2025-01-01', 'tasso' => 2.0],
        ['dal' => '2026-01-01', 'tasso' => 1.6],
    ],

    // Come si aggregano più ritenute dello stesso mese in un'unica riga F24
    // quando il periodo di riferimento non coincide (Fase 3 — non ancora consumato in Fase 1).
    'mese_riferimento_cumulo' => 'righe_distinte',

];
