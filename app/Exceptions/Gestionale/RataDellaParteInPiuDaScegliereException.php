<?php

namespace App\Exceptions\Gestionale;

use App\Helpers\MoneyHelper;

/**
 * Chi ha versato per un altro ha pagato più del debito, ha rate emesse nel condominio, ma nessuna nella gestione di
 * questo incasso (Coda 167, decisione 30.8 del 30/09/2026).
 *
 * La parte in più non si sposta da sola in un'altra gestione — una straordinaria, l'esercizio precedente: è un
 * trasferimento fra gestioni, e come per la compensazione fra gestioni lo decide l'amministratore. Il modulo gli mostra
 * le rate di chi ha versato e non ne sceglie nessuna; se la scelta non arriva, l'incasso si ferma qui, prima che resti
 * scritto qualcosa.
 */
class RataDellaParteInPiuDaScegliereException extends IncassoNonRegistrabileException
{
    /**
     * @param  bool  $sceltaNonPiuValida  la rata scelta dall'amministratore non è più di chi ha versato, emessa e di questo
     *                                    condominio (è cambiata fra la validazione e la registrazione): si chiede di
     *                                    sceglierla di nuovo, invece di ripiegare in silenzio sulla proposta (S9).
     */
    public function __construct(
        protected string $chiHaVersato,
        protected int $parteInPiuCents,
        protected bool $sceltaNonPiuValida = false,
    ) {
        parent::__construct($this->sceltaNonPiuValida
            ? sprintf(
                'La rata scelta per la parte in più (%s) non è più disponibile fra quelle di %s: sceglila di nuovo.',
                MoneyHelper::format($this->parteInPiuCents),
                $this->chiHaVersato,
            )
            : sprintf(
                '%s non ha rate nella gestione di questo incasso: scegli tu su quale delle sue rate va la parte in più (%s).',
                $this->chiHaVersato,
                MoneyHelper::format($this->parteInPiuCents),
            ));
    }

    public function campo(): string
    {
        return 'quota_parte_in_piu_id';
    }
}
