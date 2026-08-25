<?php

namespace App\Services\Import\Controlli;

use App\Models\ImportBatch;

/**
 * Sa rispondere da solo alla domanda «questa cosa è stata sistemata?».
 *
 * ## La regola che non si può violare
 *
 * Ogni verificatore parte **dagli id annotati in `import_batch_items`**, mai dall'insieme del
 * condominio. È la differenza fra «le tabelle che ho importato io» e «tutte le tabelle», e
 * sbagliarla produce il difetto peggiore che questa lista possa avere: una voce che si chiude
 * da sola mentre il problema è ancora lì. Un falso verde non toglie il problema — toglie il
 * problema *dalla vista*, che è peggio che non aver mai scritto la voce.
 *
 * ## Perché non si persiste mai «risolta»
 *
 * Perché sarebbero due verità — l'archivio e la riga — e vincerebbe sempre quella vecchia. Lo
 * stato si calcola a ogni lettura: una tabella scollegata da un capitolo riapre la sua voce nello
 * stesso istante, senza cron e senza riconciliazione. L'unica cosa che finisce su disco è la
 * spunta manuale, che è l'unica informazione che nessuna query può produrre.
 */
interface VerificatoreControllo
{
    /**
     * @param  array<string, list<int>>  $idPerTipo  gli id creati da questo lotto, per classe
     */
    public function esegui(ImportBatch $batch, array $idPerTipo): EsitoControllo;
}
