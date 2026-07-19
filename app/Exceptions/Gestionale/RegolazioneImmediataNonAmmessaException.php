<?php

namespace App\Exceptions\Gestionale;

/**
 * La registrazione a regolazione immediata è stata richiesta per un fatto
 * amministrativo che invece richiede la struttura del debito verso fornitore.
 *
 * Casi vietati per costruzione (spec §6):
 *  1. Ritenuta d'acconto — il condominio è sostituto d'imposta: il corrispettivo
 *     va spezzato in netto-al-fornitore e ritenuta-all'Erario, il che richiede
 *     una partita fornitore e un debito verso Erario da versare con F24.
 *  2. Split payment PA — richiede partita.
 *  3. Scadenziario / esposizione fornitori — se il debito va tracciato nel tempo,
 *     non è un fatto che nasce e si estingue nello stesso momento.
 *  4. F24 che estingue un debito già rilevato (es. ritenute operate) — il debito
 *     esiste già sul mastro Erario: va chiuso contro quel debito, non imputato a costo.
 *
 * La regolazione immediata resta il percorso corretto per bolli, commissioni
 * bancarie, addebiti automatici e piccole spese: costo → banca in scrittura unica.
 *
 * HTTP: 422 Unprocessable Entity (violazione di regola di dominio, non di autorizzazione)
 */
class RegolazioneImmediataNonAmmessaException extends \DomainException {}
