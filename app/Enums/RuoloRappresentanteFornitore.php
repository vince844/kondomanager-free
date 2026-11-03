<?php

namespace App\Enums;

/**
 * Il ruolo di un rappresentante di un fornitore, nella pivot `anagrafica_fornitore.ruolo`.
 *
 * ## Perché nasce
 *
 * L'elenco viveva scritto a mano in **due** posti — `CreateFornitoreAnagraficaRequest` e
 * `resources/js/pages/fornitori/anagrafiche/AnagraficheNew.vue` — e la beta.7 stava per aggiungerne
 * un terzo, portando la tendina del ruolo anche sulla creazione del fornitore. Due copie che
 * divergono le nota qualcuno; tre no.
 *
 * ## La relazione, in una riga
 *
 * Un fornitore ha **N rappresentanti**, ognuno con **uno** di questi ruoli. Non esiste un
 * «referente principale»: `referente_principale` è una colonna della pivot che nessuna parte del
 * progetto scrive, e «referente» è semplicemente uno dei sei ruoli.
 */
enum RuoloRappresentanteFornitore: string
{
    case TITOLARE = 'titolare';
    case AMMINISTRATIVO = 'amministrativo';
    case COMMERCIALE = 'commerciale';
    case TECNICO = 'tecnico';
    case REFERENTE = 'referente';
    case ALTRO = 'altro';

    public function label(): string
    {
        return match ($this) {
            self::TITOLARE       => 'Titolare',
            self::AMMINISTRATIVO => 'Amministrativo',
            self::COMMERCIALE    => 'Commerciale',
            self::TECNICO        => 'Tecnico',
            self::REFERENTE      => 'Referente',
            self::ALTRO          => 'Altro',
        };
    }

    /** I valori accettati dalla validazione. */
    public static function valori(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Le opzioni per una tendina: `{ id, label }`, la forma che usa `vue-select` in queste pagine. */
    public static function opzioni(): array
    {
        return array_map(
            fn (self $r) => ['id' => $r->value, 'label' => $r->label()],
            self::cases(),
        );
    }
}
