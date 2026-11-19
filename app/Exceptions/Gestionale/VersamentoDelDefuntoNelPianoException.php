<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

/**
 * Decisione 529, dopo il piano: un versamento in denaro sulla rata del defunto quando il debito passato agli eredi è già nella loro rata nuova (un piano ha
 * assorbito le righe dell'arretrato). Il versamento non lo copre più: il programma dice dov'è il debito adesso e che si incassa da lì; se l'amministratore
 * lo registra comunque al defunto («Resta a …»), la frase dice le conseguenze. Senza la scelta niente è scritto.
 */
class VersamentoDelDefuntoNelPianoException extends IncassoNonRegistrabileException
{
    public function __construct(string $defunto, string $eredi, string $decesso, string $dove, bool $emessa)
    {
        $giorno = substr($decesso, 8, 2) . '/' . substr($decesso, 5, 2) . '/' . substr($decesso, 0, 4);
        parent::__construct(sprintf('Il debito di %s su questa unità è passato a %s con la successione del %s, ed è %s di %s: incassalo da lì%s. Se questo '
            . 'versamento resta comunque a %s, scegli «Resta a %s»: il versamento va sulla sua rata, gli eredi devono ancora la loro rata nuova, e a %s resterà '
            . 'un credito.', $defunto, $eredi, $giorno, $dove, $eredi, $emessa ? '' : ' quando è emessa', $defunto, $defunto, $defunto));
    }

    public function campo(): string
    {
        return 'agli_eredi';
    }
}
