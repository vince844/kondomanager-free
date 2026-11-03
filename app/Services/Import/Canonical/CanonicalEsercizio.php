<?php

namespace App\Services\Import\Canonical;

use Carbon\CarbonImmutable;

/**
 * Un esercizio di gestione, con le sue date reali.
 *
 * ## La regola che questa classe esiste per far rispettare
 *
 * **Non si assume mai l'anno solare.** Gli esercizi sfalsati esistono e sono comuni — il
 * riparto reale che abbiamo in mano va dal 1° novembre 2024 al 31 ottobre 2025 — e un
 * importatore che deducesse le date dall'etichetta `"2024/2025"` produrrebbe un esercizio
 * sbagliato di due mesi, con tutti i riparti che ne discendono.
 *
 * Le date arrivano dalla riga `Periodo:` della testata, che le dichiara entrambe. Se quella riga
 * manca, l'esercizio **non si importa**: è meglio chiederle che indovinarle.
 */
final readonly class CanonicalEsercizio
{
    public function __construct(
        public string $etichetta,          // «2024/2025», «2026»
        public CarbonImmutable $dataInizio,
        public CarbonImmutable $dataFine,
        public ?string $tipo = null,       // «ordinario», «straordinario»
    ) {}

    /** Vero se l'esercizio coincide con l'anno solare. Informativo: non cambia nulla. */
    /**
     * L'esercizio dichiarato dalla testata è **straordinario**?
     *
     * Verificato sullo schema Firebird di Domustudio (backup del 19/02/2019): in Danea l'esercizio
     * non è una tabella a sé — è una riga di `TCONDOMINI`, con `DATAAPERTURA`/`DATACHIUSURA`
     * proprie, un `CONDGENID` che punta all'identità del condominio in `TCONDOMINI_GENERALE`, e
     * una colonna booleana **`STRAORDINARIO`** (`DEFAULT 0`). Un esercizio straordinario è quindi
     * una riga come le altre, con un periodo suo e lo stesso condominio dell'ordinario, esportata
     * con le stesse stampe: nel file l'unica differenza è la parola nella testata.
     *
     * Il confronto è sul prefisso perché la testata declina al maschile («straordinario») ma
     * altre stampe potrebbero declinare diversamente, e la parola è comunque scritta da loro.
     */
    public function eStraordinario(): bool
    {
        return $this->tipo !== null && str_starts_with(mb_strtolower($this->tipo), 'straordinar');
    }

    public function isSolare(): bool
    {
        return $this->dataInizio->month === 1
            && $this->dataInizio->day === 1
            && $this->dataFine->month === 12
            && $this->dataFine->day === 31
            && $this->dataInizio->year === $this->dataFine->year;
    }

    public function giorni(): int
    {
        return (int) $this->dataInizio->diffInDays($this->dataFine) + 1;
    }

    public function toArray(): array
    {
        return [
            'etichetta' => $this->etichetta,
            'tipo' => $this->tipo,
            'data_inizio' => $this->dataInizio->toDateString(),
            'data_fine' => $this->dataFine->toDateString(),
            'solare' => $this->isSolare(),
        ];
    }
}
