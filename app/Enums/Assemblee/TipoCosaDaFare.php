<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Enums\Assemblee;

/**
 * Che cosa resta da fare dopo una delibera (decisione 522 (1)): le cose proposte dal programma, ognuna fatta da sola quando la scheda nasce (o si approva) con
 * la delibera (il piano, la dispensa, la regola, la versione corretta, il piano rimesso come il preventivo approvato), e quelle scritte dall'amministratore
 * («fuori dal programma»), che si segnano eseguite.
 */
enum TipoCosaDaFare: string
{
    use ConLeParole;

    case CREA_PIANO_STRAORDINARIO = 'crea_piano_straordinario';
    case PREPARA_RIPIANAMENTO = 'prepara_ripianamento';
    case REGISTRA_DISPENSA = 'registra_dispensa';
    case SCRIVI_REGOLA = 'scrivi_regola';
    case RIFAI_VERSIONE = 'rifai_versione';
    case RIMETTI_PIANO = 'rimetti_piano';
    case AVVISA_CONDOMINI = 'avvisa_condomini';
    case FUORI_DAL_PROGRAMMA = 'fuori_dal_programma';
    case DOPO_IL_RIFIUTO = 'dopo_il_rifiuto';

    public function parola(): string
    {
        return match ($this) {
            self::CREA_PIANO_STRAORDINARIO => 'Crea il piano straordinario',
            self::PREPARA_RIPIANAMENTO => 'Prepara il piano di ripianamento',
            self::REGISTRA_DISPENSA => 'Registra la dispensa',
            self::SCRIVI_REGOLA => 'Scrivi la regola',
            self::RIFAI_VERSIONE => 'Correggi e ferma una versione nuova',
            self::RIMETTI_PIANO => 'Rimetti il piano come il preventivo approvato',
            self::AVVISA_CONDOMINI => 'Avvisa i condòmini',
            self::FUORI_DAL_PROGRAMMA => 'Fuori dal programma',
            self::DOPO_IL_RIFIUTO => 'Respinta: che cosa fai?',
        };
    }

    /**
     * Le schede che la eseguono (R5): tipo della scheda e ruolo del collegamento con la delibera. Vuoto: non la fa il programma. «Correggi e ferma una versione
     * nuova» ne ha due, la versione del rendiconto e quella del preventivo approvate dalla delibera.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function schedeCheLaEseguono(): array
    {
        return match ($this) {
            self::CREA_PIANO_STRAORDINARIO, self::PREPARA_RIPIANAMENTO, self::RIMETTI_PIANO => [['piano_rate', RuoloCollegamento::DELIBERA->value]],
            self::REGISTRA_DISPENSA => [['dispensa_sei_mesi', RuoloCollegamento::DISPENSA->value]],
            self::SCRIVI_REGOLA => [['regola_recupero', RuoloCollegamento::FONTE->value]],
            self::RIFAI_VERSIONE => [['rendiconto_versione', RuoloCollegamento::APPROVAZIONE->value], ['preventivo_versione', RuoloCollegamento::APPROVAZIONE->value]],
            default => [],
        };
    }

    /**
     * La parola nel punto «Stato di esecuzione delle delibere precedenti», che va nell'avviso e nel verbale: mai il testo scritto dall'amministratore, che può
     * portare un nome (Cass. 29323/2022).
     */
    public function parolaNelloStato(): string
    {
        return match ($this) {
            self::FUORI_DAL_PROGRAMMA => 'Un\'altra cosa da fare',
            self::DOPO_IL_RIFIUTO => 'La risposta alla delibera respinta',
            default => $this->parola(),
        };
    }
}
