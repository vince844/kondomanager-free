<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Gestionale;

/**
 * Decisione 529: un versamento sulla rata del defunto dopo la successione, con l'arretrato passato agli eredi e non ancora in un piano. Il debito non è più
 * suo: il programma avvisa e chiede all'amministratore se il versamento copre il debito degli eredi (di chi ha versato, o di tutti per quota se arriva dal conto
 * del defunto) o resta al defunto, con le conseguenze di ognuna. Decide l'amministratore; senza la sua scelta l'incasso non parte (la stessa regola della
 * decisione 30.7), e niente è scritto.
 */
class VersamentoDelDefuntoDaScegliereException extends IncassoNonRegistrabileException
{
    public function __construct(string $defunto, string $eredi, string $decesso, ?string $versatoDa = null)
    {
        $giorno = substr($decesso, 8, 2) . '/' . substr($decesso, 5, 2) . '/' . substr($decesso, 0, 4);
        $agliEredi = $versatoDa !== null
            ? "«Per il debito degli eredi»: il versamento copre la parte di {$versatoDa}, e il prossimo piano non gliela chiede di nuovo"
            : "«Per il debito degli eredi»: il versamento copre il debito di {$eredi}, per quota, e il prossimo piano non lo chiede di nuovo";
        parent::__construct(sprintf('Il debito di %s su questa unità è passato a %s con la successione del %s. Scegli che cosa copre il versamento: %s; '
            . '«Resta a %s»: il prossimo piano chiederà agli eredi l\'arretrato intero, e a %s resterà un credito.', $defunto, $eredi, $giorno, $agliEredi, $defunto, $defunto));
    }

    public function campo(): string
    {
        return 'agli_eredi';
    }
}
