<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Le righe che gli elenchi mostrano a chi non ha ancora scelto.
 *
 * ⚠️ **Rieseguibile.** `add()` lancia `SettingAlreadyExists` se la proprietà c'è già: senza la
 * guardia, un aggiornamento interrotto fra la scrittura dell'impostazione e la registrazione della
 * migrazione lascerebbe l'installazione in uno stato da cui non si esce più, perché ogni tentativo
 * successivo fallirebbe sullo stesso punto.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if ($this->migrator->exists('general.default_per_page')) {
            return;
        }

        $this->migrator->add('general.default_per_page', $this->valoreIniziale());
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.default_per_page');
    }

    /**
     * Il valore che l'installazione usa già, così chi aggiorna non si ritrova gli elenchi
     * ridimensionati senza aver toccato niente: l'impostazione nasce dichiarando ciò che era
     * implicito.
     *
     * ⚠️ **Il ripiego a 10 non è pignoleria.** `DEFAULT_PER_PAGE=` lasciato vuoto nel `.env` — che
     * è una riga che si scrive per sbaglio, non una configurazione — dà stringa vuota, quindi `0`
     * una volta castato, quindi `paginate(0)`, che è una divisione per zero dentro il paginatore:
     * ogni elenco del programma andrebbe in errore 500 subito dopo l'aggiornamento.
     */
    private function valoreIniziale(): int
    {
        $configurato = (int) config('pagination.default_per_page');

        return in_array($configurato, config('pagination.consentite'), true)
            ? $configurato
            : 10;
    }
};
