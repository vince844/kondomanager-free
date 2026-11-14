<?php

namespace App\Services\PDF;

/**
 * Il gruppo di documenti di una stampa: ogni gruppo ha la sua carta dello studio. Ogni chiamata a `PdfService::generate()` lo dice
 * (`gruppo_carta`), e una stampa che non lo dice è un'eccezione.
 *
 * - Lettere ai condòmini: promemoria, sollecito, diffida, messa in mora.
 * - Ricevute e avvisi di compensazione.
 * - Rendiconto e assemblea: il fascicolo e le sue parti, i riparti, il preventivo, lo scadenziario del piano, il registro per l'assemblea.
 * - Estratti conto e prospetti per la persona.
 * - Registri ed elenchi dello studio: il libro giornale, la copia di controllo del registro, il mastrino, il libro mastro, le rate scadute, la
 *   distinta del pagamento, il rapporto dell'importazione.
 * - Nessuno: il modello F24, che è dell'Agenzia delle Entrate.
 */
enum GruppoCarta: string
{
    case LETTERE = 'lettere';
    case RICEVUTE_E_AVVISI = 'ricevute_e_avvisi';
    case RENDICONTO_E_ASSEMBLEA = 'rendiconto_e_assemblea';
    case PER_LA_PERSONA = 'per_la_persona';
    case REGISTRI_E_ELENCHI = 'registri_e_elenchi';
    case NESSUNO = 'nessuno';

    /** Il nome del gruppo per la pagina delle impostazioni. */
    public function nome(): string
    {
        return match ($this) {
            self::LETTERE => 'Lettere ai condòmini',
            self::RICEVUTE_E_AVVISI => 'Ricevute e avvisi di compensazione',
            self::RENDICONTO_E_ASSEMBLEA => 'Rendiconto e assemblea',
            self::PER_LA_PERSONA => 'Estratti conto e prospetti per la persona',
            self::REGISTRI_E_ELENCHI => 'Registri ed elenchi dello studio',
            self::NESSUNO => 'Nessuna carta (il modello F24)',
        };
    }

    /** Le stampe del gruppo, per la pagina delle impostazioni: quali documenti escono con la sua carta. */
    public function esempi(): string
    {
        return match ($this) {
            self::LETTERE => 'promemoria, sollecito, diffida, messa in mora',
            self::RICEVUTE_E_AVVISI => 'la ricevuta dell\'incasso, l\'avviso di compensazione',
            self::RENDICONTO_E_ASSEMBLEA => 'il fascicolo e le sue parti, i riparti, il preventivo, lo scadenziario del piano, il registro per l\'assemblea',
            self::PER_LA_PERSONA => 'l\'estratto conto della persona, il foglio del conguaglio, il prospetto degli oneri accessori',
            self::REGISTRI_E_ELENCHI => 'il libro giornale, la copia di controllo del registro, il mastrino, il libro mastro, le rate scadute, la distinta del pagamento, il rapporto dell\'importazione',
            self::NESSUNO => 'il modello F24, che è dell\'Agenzia delle Entrate',
        };
    }
}
