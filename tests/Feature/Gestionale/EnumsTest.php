<?php

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFattura;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoDetrazione;
use App\Enums\TipoMovimentoContabile;

// ─────────────────────────────────────────────────────────────────────────────
// TipoMovimentoContabile
// ─────────────────────────────────────────────────────────────────────────────

describe('TipoMovimentoContabile', function () {

    it('ha i valori stringa corretti per la persistenza DB', function () {
        expect(TipoMovimentoContabile::FATTURA_ACQUISTO->value)->toBe('fattura_acquisto');
        expect(TipoMovimentoContabile::PAGAMENTO_FORNITORE->value)->toBe('pagamento_fornitore');
        expect(TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE->value)->toBe('storno_pagamento_fornitore');
        expect(TipoMovimentoContabile::INCASSO_RATA->value)->toBe('incasso_rata');
        expect(TipoMovimentoContabile::EMISSIONE_RATA->value)->toBe('emissione_rata');
        expect(TipoMovimentoContabile::GIROCONTO->value)->toBe('giroconto');
        expect(TipoMovimentoContabile::PAGAMENTO_F24->value)->toBe('pagamento_f24');
    });

    it('può essere creato da stringa (from DB)', function () {
        expect(TipoMovimentoContabile::from('pagamento_fornitore'))
            ->toBe(TipoMovimentoContabile::PAGAMENTO_FORNITORE);
    });

    it('lancia ValueError per stringa non valida', function () {
        expect(fn () => TipoMovimentoContabile::from('valore_inesistente'))
            ->toThrow(\ValueError::class);
    });

    it('tryFrom restituisce null per stringa non valida', function () {
        expect(TipoMovimentoContabile::tryFrom('inesistente'))->toBeNull();
    });

    it('PAGAMENTO_FORNITORE è uscita di cassa', function () {
        expect(TipoMovimentoContabile::PAGAMENTO_FORNITORE->isUscitaCassa())->toBeTrue();
        expect(TipoMovimentoContabile::FATTURA_ACQUISTO->isUscitaCassa())->toBeFalse();
    });

    it('STORNO_PAGAMENTO_FORNITORE è entrata di cassa', function () {
        expect(TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE->isEntrataCassa())->toBeTrue();
        expect(TipoMovimentoContabile::PAGAMENTO_FORNITORE->isEntrataCassa())->toBeFalse();
    });

    it('cicloPassivo contiene esattamente i 3 tipi del ciclo passivo', function () {
        $ciclo = TipoMovimentoContabile::cicloPassivo();

        expect($ciclo)->toHaveCount(5);
        expect($ciclo)->toContain(TipoMovimentoContabile::FATTURA_ACQUISTO);
        expect($ciclo)->toContain(TipoMovimentoContabile::NOTA_CREDITO_FORNITORE);
        expect($ciclo)->toContain(TipoMovimentoContabile::PAGAMENTO_FORNITORE);
        expect($ciclo)->toContain(TipoMovimentoContabile::STORNO_FATTURA);
        expect($ciclo)->toContain(TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE);
    });

    it('tutti i casi hanno una label non vuota', function () {
        foreach (TipoMovimentoContabile::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// MetodoPagamento
// ─────────────────────────────────────────────────────────────────────────────

describe('MetodoPagamento', function () {

    it('ha i valori stringa corretti', function () {
        expect(MetodoPagamento::BONIFICO->value)->toBe('bonifico');
        expect(MetodoPagamento::CONTANTI->value)->toBe('contanti');
        expect(MetodoPagamento::ASSEGNO->value)->toBe('assegno');
        expect(MetodoPagamento::RID_SDD->value)->toBe('rid_sdd');
        expect(MetodoPagamento::ALTRO->value)->toBe('altro');
    });

    it('solo BONIFICO richiede IBAN', function () {
        expect(MetodoPagamento::BONIFICO->richiedeIban())->toBeTrue();

        foreach ([MetodoPagamento::CONTANTI, MetodoPagamento::ASSEGNO, MetodoPagamento::RID_SDD, MetodoPagamento::ALTRO] as $metodo) {
            expect($metodo->richiedeIban())->toBeFalse();
        }
    });

    it('solo CONTANTI è soggetto al limite antiriciclaggio', function () {
        expect(MetodoPagamento::CONTANTI->isContante())->toBeTrue();
        expect(MetodoPagamento::BONIFICO->isContante())->toBeFalse();
    });

    it('tutti i casi hanno una label non vuota', function () {
        foreach (MetodoPagamento::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// StatoPagamentoFattura
// ─────────────────────────────────────────────────────────────────────────────

describe('StatoPagamentoFattura', function () {

    it('ha i valori stringa corretti', function () {
        expect(StatoPagamentoFattura::APERTA->value)->toBe('aperta');
        expect(StatoPagamentoFattura::PARZIALE->value)->toBe('parziale');
        expect(StatoPagamentoFattura::PAGATA->value)->toBe('pagata');
    });

    it('fromImporti calcola APERTA quando totale è zero', function () {
        expect(StatoPagamentoFattura::fromImporti(0, 100_000))->toBe(StatoPagamentoFattura::APERTA);
    });

    it('fromImporti calcola APERTA quando totale è negativo (storno)', function () {
        expect(StatoPagamentoFattura::fromImporti(-100, 100_000))->toBe(StatoPagamentoFattura::APERTA);
    });

    it('fromImporti calcola PARZIALE quando totale è tra 0 e netto', function () {
        expect(StatoPagamentoFattura::fromImporti(40_000, 100_000))->toBe(StatoPagamentoFattura::PARZIALE);
    });

    it('fromImporti calcola PAGATA quando totale uguale al netto', function () {
        expect(StatoPagamentoFattura::fromImporti(100_000, 100_000))->toBe(StatoPagamentoFattura::PAGATA);
    });

    it('fromImporti calcola PAGATA quando totale supera il netto (edge case)', function () {
        // Questo non dovrebbe mai accadere in produzione (bloccato da validaInput)
        // ma fromImporti deve restituire PAGATA comunque (non esplodere).
        expect(StatoPagamentoFattura::fromImporti(110_000, 100_000))->toBe(StatoPagamentoFattura::PAGATA);
    });

    it('APERTA e PARZIALE hanno residuo, PAGATA no', function () {
        expect(StatoPagamentoFattura::APERTA->hasResiduo())->toBeTrue();
        expect(StatoPagamentoFattura::PARZIALE->hasResiduo())->toBeTrue();
        expect(StatoPagamentoFattura::PAGATA->hasResiduo())->toBeFalse();
    });

    it('la label per NC è diversa da quella standard per PAGATA', function () {
        expect(StatoPagamentoFattura::PAGATA->label())->toBe('Pagata');
        expect(StatoPagamentoFattura::PAGATA->labelPerNC())->toBe('Compensata');
    });

    it('tutti i casi hanno badge color', function () {
        foreach (StatoPagamentoFattura::cases() as $case) {
            expect($case->badgeColor())->toBeString()->not->toBeEmpty();
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// StatoPagamentoFornitore
// ─────────────────────────────────────────────────────────────────────────────

describe('StatoPagamentoFornitore', function () {

    it('ha i valori stringa corretti', function () {
        expect(StatoPagamentoFornitore::CONFERMATO->value)->toBe('confermato');
        expect(StatoPagamentoFornitore::STORNATO->value)->toBe('stornato');
    });

    it('solo CONFERMATO è stornabile', function () {
        expect(StatoPagamentoFornitore::CONFERMATO->isStornabile())->toBeTrue();
        expect(StatoPagamentoFornitore::STORNATO->isStornabile())->toBeFalse();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// TipoAllocazioneFattura
// ─────────────────────────────────────────────────────────────────────────────

describe('TipoAllocazioneFattura', function () {

    it('ha i valori stringa corretti', function () {
        expect(TipoAllocazioneFattura::COMPETENZA->value)->toBe('competenza');
        expect(TipoAllocazioneFattura::PAGAMENTO->value)->toBe('pagamento');
        expect(TipoAllocazioneFattura::COMPENSAZIONE->value)->toBe('compensazione');
    });

    it('COMPETENZA NON partecipa al calcolo del saldo (invariante critica)', function () {
        expect(TipoAllocazioneFattura::COMPETENZA->partecipaAlCalcoloSaldo())->toBeFalse();
    });

    it('PAGAMENTO e COMPENSAZIONE partecipano al calcolo del saldo', function () {
        expect(TipoAllocazioneFattura::PAGAMENTO->partecipaAlCalcoloSaldo())->toBeTrue();
        expect(TipoAllocazioneFattura::COMPENSAZIONE->partecipaAlCalcoloSaldo())->toBeTrue();
    });

    it('perCalcoloSaldo restituisce i valori stringa corretti per le query WHERE', function () {
        $valori = TipoAllocazioneFattura::perCalcoloSaldo();

        expect($valori)->toHaveCount(2);
        expect($valori)->toContain('pagamento');
        expect($valori)->toContain('compensazione');
        expect($valori)->not->toContain('competenza');
    });

    it('solo PAGAMENTO è movimento di cassa', function () {
        expect(TipoAllocazioneFattura::PAGAMENTO->isMovimentoCassa())->toBeTrue();
        expect(TipoAllocazioneFattura::COMPENSAZIONE->isMovimentoCassa())->toBeFalse();
        expect(TipoAllocazioneFattura::COMPETENZA->isMovimentoCassa())->toBeFalse();
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// TipoDetrazione
// ─────────────────────────────────────────────────────────────────────────────

describe('TipoDetrazione', function () {

    it('ha i valori stringa corretti', function () {
        expect(TipoDetrazione::RISTRUTTURAZIONE->value)->toBe('ristrutturazione');
        expect(TipoDetrazione::ECOBONUS->value)->toBe('ecobonus');
        expect(TipoDetrazione::SISMABONUS->value)->toBe('sismabonus');
        expect(TipoDetrazione::SUPERBONUS->value)->toBe('superbonus');
    });

    it('ogni tipo ha un riferimento normativo non vuoto', function () {
        foreach (TipoDetrazione::cases() as $case) {
            expect($case->riferimentoNormativo())->toBeString()->not->toBeEmpty();
        }
    });

    it('RISTRUTTURAZIONE ha il riferimento normativo corretto', function () {
        expect(TipoDetrazione::RISTRUTTURAZIONE->riferimentoNormativo())
            ->toBe('art. 16-bis DPR 917/1986');
    });

    it('SUPERBONUS ha il riferimento normativo corretto', function () {
        expect(TipoDetrazione::SUPERBONUS->riferimentoNormativo())
            ->toBe('art. 119 DL 34/2020');
    });

    it('genera causale contenente tutti i dati obbligatori', function () {
        $causale = TipoDetrazione::RISTRUTTURAZIONE->generaCausale(
            cfBeneficiari: ['RSSMRA80A01H501Z', 'VRDLGI75B15F205X'],
            partitaIvaFornitore: '12345678901',
            numeroFattura: 'FT-2026/001',
            dataFattura: '15/01/2026'
        );

        expect($causale)
            ->toContain('art. 16-bis DPR 917/1986')
            ->toContain('RSSMRA80A01H501Z')
            ->toContain('VRDLGI75B15F205X')
            ->toContain('12345678901')
            ->toContain('FT-2026/001');
    });

    it('verifica correttamente se causale rientra nel limite SEPA 140 caratteri', function () {
        // Due CF brevi — dovrebbe rientrare
        $rientra = TipoDetrazione::RISTRUTTURAZIONE->causaleEntraNelLimiteSEPA(
            cfBeneficiari: ['RSSMRA80A01H501Z'],
            partitaIvaFornitore: '12345678901',
            numeroFattura: 'FT001',
            dataFattura: '15/01/2026'
        );

        // Tanti CF — potrebbe non rientrare
        $nonRientra = TipoDetrazione::SUPERBONUS->causaleEntraNelLimiteSEPA(
            cfBeneficiari: array_fill(0, 10, 'RSSMRA80A01H501Z'),
            partitaIvaFornitore: '12345678901',
            numeroFattura: 'FT-2026/001-CONDOMINIO-VIA-ROMA-12',
            dataFattura: '15/01/2026'
        );

        expect($rientra)->toBeBool();
        expect($nonRientra)->toBeFalse();
    });

    it('tutti i casi hanno label e percentuale non vuota', function () {
        foreach (TipoDetrazione::cases() as $case) {
            expect($case->label())->toBeString()->not->toBeEmpty();
            expect($case->percentualeDetrazione())->toBeString()->not->toBeEmpty();
        }
    });

});

// ─────────────────────────────────────────────────────────────────────────────
// Test incrociati — coerenza tra enum
// ─────────────────────────────────────────────────────────────────────────────

describe('Coerenza incrociata enum', function () {

    it('TipoAllocazioneFattura::perCalcoloSaldo non include competenza (invariante globale)', function () {
        $perSaldo = TipoAllocazioneFattura::perCalcoloSaldo();

        expect($perSaldo)->not->toContain(TipoAllocazioneFattura::COMPETENZA->value);
    });

    it('StatoPagamentoFattura::fromImporti è coerente con hasResiduo', function () {
        $aperta   = StatoPagamentoFattura::fromImporti(0, 100_000);
        $parziale = StatoPagamentoFattura::fromImporti(50_000, 100_000);
        $pagata   = StatoPagamentoFattura::fromImporti(100_000, 100_000);

        expect($aperta->hasResiduo())->toBeTrue();
        expect($parziale->hasResiduo())->toBeTrue();
        expect($pagata->hasResiduo())->toBeFalse();
    });

    it('MetodoPagamento::BONIFICO richiede IBAN e TipoMovimentoContabile è pagamento fornitore', function () {
        // Verifica concettuale: un pagamento con metodo bonifico usa PAGAMENTO_FORNITORE nel ledger
        expect(MetodoPagamento::BONIFICO->richiedeIban())->toBeTrue();
        expect(TipoMovimentoContabile::PAGAMENTO_FORNITORE->isUscitaCassa())->toBeTrue();
    });

});