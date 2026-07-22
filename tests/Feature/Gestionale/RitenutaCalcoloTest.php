<?php

use App\DataTransferObjects\RitenutaCalcolo;
use App\Enums\Fiscale\MotivoEsclusioneRitenuta;
use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\Fiscale\NaturaRigaRitenuta;
use App\Enums\Fiscale\TipoRitenuta;
use App\Models\Fornitore;
use App\Services\Gestionale\RitenutaService;
use Carbon\Carbon;

/**
 * Fase 1 del redesign ritenute (docs/design/f24_ritenute_design.md).
 *
 * Test puri: nessuna scrittura contabile, nessun setup di piano dei conti —
 * solo il calcolo (TipoRitenuta + RitenutaService). Il Fornitore è
 * istanziato in memoria (mai persistito): non serve alcuna tabella.
 */

// ─────────────────────────────────────────────────────────────────────────────
// TipoRitenuta — aliquote e codice tributo
// ─────────────────────────────────────────────────────────────────────────────

describe('TipoRitenuta', function () {

    it('appalto 4%: mai confuso con lavoro autonomo 20%', function () {
        expect(TipoRitenuta::APPALTO_4->aliquota(Carbon::parse('2026-01-01')))->toBe(4.0);
        expect(TipoRitenuta::LAVORO_AUTONOMO_20->aliquota(Carbon::parse('2026-01-01')))->toBe(20.0);
    });

    it('codice tributo appalto dipende da IRPEF vs IRES, non dal tipo di spesa', function () {
        expect(TipoRitenuta::APPALTO_4->codiceTributo(NaturaPercipiente::PERSONA_FISICA_IRPEF))->toBe('1019');
        expect(TipoRitenuta::APPALTO_4->codiceTributo(NaturaPercipiente::SOCIETA_PERSONE_IRPEF))->toBe('1019');
        expect(TipoRitenuta::APPALTO_4->codiceTributo(NaturaPercipiente::SOGGETTO_IRES))->toBe('1020');
    });

    it('lavoro autonomo è sempre 1040, mai influenzato dalla natura del percipiente', function () {
        expect(TipoRitenuta::LAVORO_AUTONOMO_20->codiceTributo(NaturaPercipiente::PERSONA_FISICA_IRPEF))->toBe('1040');
        expect(TipoRitenuta::LAVORO_AUTONOMO_20->codiceTributo(NaturaPercipiente::SOGGETTO_IRES))->toBe('1040');
    });

    it('non residente 30% è a titolo di IMPOSTA, tutti gli altri sono ACCONTO', function () {
        expect(TipoRitenuta::NON_RESIDENTE_30->titolo())->toBe(App\Enums\Fiscale\TitoloRitenuta::IMPOSTA);
        expect(TipoRitenuta::APPALTO_4->titolo())->toBe(App\Enums\Fiscale\TitoloRitenuta::ACCONTO);
        expect(TipoRitenuta::LAVORO_AUTONOMO_20->titolo())->toBe(App\Enums\Fiscale\TitoloRitenuta::ACCONTO);
    });

    it('provvigioni: 23% su base 50% con riduzione, su base 20% senza', function () {
        expect(TipoRitenuta::PROVVIGIONI_BASE_50->percentualeBase())->toBe(50.0);
        expect(TipoRitenuta::PROVVIGIONI_BASE_20->percentualeBase())->toBe(20.0);
        expect(TipoRitenuta::PROVVIGIONI_BASE_50->aliquota(Carbon::parse('2026-01-01')))->toBe(23.0);
    });

    it('nessuna aliquota configurata alla data → eccezione, mai un default silenzioso', function () {
        // APPALTO_4 esiste solo dal 2007-01-01 in config/fiscale.php: una data
        // precedente non deve MAI produrre un'aliquota di fallback.
        expect(fn () => TipoRitenuta::APPALTO_4->aliquota(Carbon::parse('2000-01-01')))
            ->toThrow(\DomainException::class);
    });

    it('riferimento normativo appalti cambia dal 2027 (D.Lgs. 33/2025)', function () {
        expect(TipoRitenuta::APPALTO_4->riferimentoNormativo(Carbon::parse('2026-12-31')))
            ->toContain('25-ter');
        expect(TipoRitenuta::APPALTO_4->riferimentoNormativo(Carbon::parse('2027-01-01')))
            ->toContain('33/2025');
    });

    it('tutti i casi hanno una label non vuota e un plafond', function () {
        foreach (TipoRitenuta::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->plafond())->toBeInstanceOf(App\Enums\Fiscale\PlafondRitenuta::class);
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// NaturaRigaRitenuta / MotivoEsclusioneRitenuta — enum di supporto
// ─────────────────────────────────────────────────────────────────────────────

describe('Enum di supporto Fase 1', function () {

    it('cassa professionale e rimborsi NON concorrono di default, imponibile e rivalsa INPS sì', function () {
        expect(NaturaRigaRitenuta::IMPONIBILE->concorreDiDefault())->toBeTrue();
        expect(NaturaRigaRitenuta::RIVALSA_INPS_GS->concorreDiDefault())->toBeTrue();
        expect(NaturaRigaRitenuta::CASSA_PROFESSIONALE->concorreDiDefault())->toBeFalse();
        expect(NaturaRigaRitenuta::RIMBORSO_ART15->concorreDiDefault())->toBeFalse();
        expect(NaturaRigaRitenuta::RIMBORSO_PIE_LISTA->concorreDiDefault())->toBeFalse();
        expect(NaturaRigaRitenuta::BOLLO_RIADDEBITATO->concorreDiDefault())->toBeFalse();
        expect(NaturaRigaRitenuta::FORNITURA_CON_POSA->concorreDiDefault())->toBeFalse();
    });

    it('tutti i motivi di esclusione hanno una label non vuota', function () {
        foreach (MotivoEsclusioneRitenuta::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// RitenutaService — calcolo puro
// ─────────────────────────────────────────────────────────────────────────────

describe('RitenutaService::calcola', function () {

    it('regime nuovo: appalto 4% su 5000€ di base → 200€ di ritenuta', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta'  => true,
            'tipo_ritenuta'      => TipoRitenuta::APPALTO_4,
            'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore,
            [['importo_imponibile' => 500000, 'concorre_base_ritenuta' => true]],
            500000,
            true,
            Carbon::parse('2026-01-01'),
        );

        expect($risultato->applicata)->toBeTrue()
            ->and($risultato->importo)->toBe(20000)
            ->and($risultato->aliquota)->toBe(4.0)
            ->and($risultato->codiceTributo)->toBe('1019');
    });

    it('50€ di base con appalto 4% → 2€ di ritenuta (anti-regressione soglia)', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta'  => true,
            'tipo_ritenuta'      => TipoRitenuta::APPALTO_4,
            'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 5000, 'concorre_base_ritenuta' => true]], 5000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->importo)->toBe(200); // 50,00€ * 4% = 2,00€ = 200 centesimi
    });

    it('regime legacy (senza tipo_ritenuta): stesso risultato di prima — zero regressioni', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta'        => true,
            'perc_ritenuta'            => 4,
            'perc_imponibile_ritenuta' => 100,
            'codice_tributo'           => '1040',
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000, 'concorre_base_ritenuta' => true]], 100000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->importo)->toBe(4000)
            ->and($risultato->codiceTributo)->toBe('1040')
            ->and($risultato->tipoRitenuta)->toBeNull();
    });

    it('forfetario: zero ritenuta SEMPRE, anche se qualcuno ha chiesto applica_ritenuta=true', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta'  => true,
            'regime_forfetario'  => true,
            'tipo_ritenuta'      => TipoRitenuta::LAVORO_AUTONOMO_20,
            'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000, 'concorre_base_ritenuta' => true]], 100000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->applicata)->toBeFalse()
            ->and($risultato->importo)->toBe(0)
            ->and($risultato->motivoEsclusione)->toBe(MotivoEsclusioneRitenuta::FORFETARIO);
    });

    it('esclusione richiesta dal chiamante (bonifico parlante / fuori campo / posa accessoria) → zero', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100,
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000, 'concorre_base_ritenuta' => true]], 100000, false, Carbon::parse('2026-01-01'),
        );

        expect($risultato->applicata)->toBeFalse()->and($risultato->importo)->toBe(0);
    });

    it('fornitore non soggetto a ritenuta → zero anche se richiestaApplicazione=true', function () {
        $fornitore = new Fornitore(['soggetto_ritenuta' => false]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000]], 100000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->applicata)->toBeFalse();
    });

    it('riga con concorre_base_ritenuta=false è esclusa dalla base (cassa professionale)', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta' => true, 'perc_ritenuta' => 20, 'perc_imponibile_ritenuta' => 100,
        ]);

        // 800€ imponibile prestazione + 100€ cassa professionale (esclusa) + 32€ rivalsa INPS GS (inclusa)
        $righe = [
            ['importo_imponibile' => 80000, 'concorre_base_ritenuta' => true],  // prestazione
            ['importo_imponibile' => 10000, 'concorre_base_ritenuta' => false], // cassa professionale — ESCLUSA
            ['importo_imponibile' => 3200,  'concorre_base_ritenuta' => true],  // rivalsa INPS GS — INCLUSA
        ];

        $risultato = (new RitenutaService())->calcola($fornitore, $righe, 93200, true, Carbon::parse('2026-01-01'));

        // Base = 800 + 32 = 832€ (la cassa professionale NON entra) → ritenuta 20% = 166,40€
        expect($risultato->baseImponibile)->toBe(83200)
            ->and($risultato->importo)->toBe(16640);
    });

    it('IVA non entra mai in base: il chiamante passa solo importo_imponibile, mai il lordo', function () {
        // Se per errore l'IVA finisse nella base, 1000€ + 220€ IVA darebbe una base
        // di 1220€ invece di 1000€. Verifichiamo che il servizio usi esattamente
        // quello che riceve — la responsabilità di non passare il lordo è del
        // chiamante (FatturaPassivaService), collaudato qui con dati puliti.
        $fornitore = new Fornitore([
            'soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100,
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000, 'concorre_base_ritenuta' => true]], 100000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->baseImponibile)->toBe(100000); // non 122000
    });

    it('fattura pregressa senza righe di dettaglio: usa il fallback intero come base', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100,
        ]);

        $risultato = (new RitenutaService())->calcola($fornitore, [], 100000, true, Carbon::parse('2026-01-01'));

        expect($risultato->baseImponibile)->toBe(100000)->and($risultato->importo)->toBe(4000);
    });

    it('la nota della scrittura riporta l\'aliquota reale, mai un 4% fisso', function () {
        $fornitore = new Fornitore([
            'soggetto_ritenuta'  => true,
            'tipo_ritenuta'      => TipoRitenuta::LAVORO_AUTONOMO_20,
            'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
        ]);

        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000, 'concorre_base_ritenuta' => true]], 100000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->nota)->toContain('20%')->not->toContain('4%');
    });

    it('provvigioni: arrotondamento a due passaggi (base poi imposta) è una scelta esplicita e testata', function () {
        // Revisione avversariale: RitenutaService arrotonda la base ritenuta
        // (baseCalcolo) PRIMA di applicare l'aliquota, invece di calcolare in
        // un solo passaggio. Su ~6% degli importi possibili per i regimi
        // provvigioni questo diverge di 1 centesimo da un calcolo diretto.
        // Scelta deliberata: baseCalcolo è di per sé una cifra rilevante (punto
        // 8 CU in Fase 2), quindi va arrotondata come cifra a sé, non nascosta
        // dentro un'unica formula. Questo test blocca la scelta: se qualcuno
        // la cambia per errore, il test fallisce invece di lasciarla silenziosa.
        $fornitore = new Fornitore([
            'soggetto_ritenuta'  => true,
            'tipo_ritenuta'      => TipoRitenuta::PROVVIGIONI_BASE_20,
            'natura_percipiente' => NaturaPercipiente::PERSONA_FISICA_IRPEF,
        ]);

        // 1,63€ di provvigione: base ritenuta = round(1,63 * 20%) = round(0,326) = 0,33€,
        // ritenuta = round(0,33 * 23%) = round(0,0759) = 0,08€ — NON 0,07€ (che darebbe
        // un calcolo diretto in un solo passaggio: round(1,63 * 4,6%) = round(0,07498)).
        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 163, 'concorre_base_ritenuta' => true]], 163, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->baseImponibile)->toBe(33)->and($risultato->importo)->toBe(8);
    });

});

describe('RitenutaCalcolo DTO', function () {

    it('toLegacyDatiExtra è null quando non applicata', function () {
        expect(RitenutaCalcolo::nonApplicata(1000)->toLegacyDatiExtra())->toBeNull();
    });

    it('toLegacyDatiExtra mantiene la forma storica letta da FatturaShow.vue', function () {
        $fornitore = new Fornitore(['soggetto_ritenuta' => true, 'perc_ritenuta' => 4, 'perc_imponibile_ritenuta' => 100, 'codice_tributo' => '1040']);
        $risultato = (new RitenutaService())->calcola(
            $fornitore, [['importo_imponibile' => 100000, 'concorre_base_ritenuta' => true]], 100000, true, Carbon::parse('2026-01-01'),
        );

        expect($risultato->toLegacyDatiExtra())->toBe([
            'imponibile_calcolo' => 100000,
            'aliquota' => 4.0,
            'codice_tributo' => '1040',
        ]);
    });

});
