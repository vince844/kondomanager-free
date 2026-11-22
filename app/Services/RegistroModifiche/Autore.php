<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Enums\Role;
use App\Models\User;

/**
 * Chi scrive una riga del registro: una persona (l'utente in sessione, con il ruolo di serie che ha) o «Il programma», con l'origine e il suo dettaglio.
 *
 * Si legge dal contesto, mai da `created_by` (decisione 518; P15 per il ripristino, che copia nome e ruolo di chi lo avvia). I nomi si copiano nella riga:
 * eliminato l'utente, la riga dice ancora chi era.
 */
final class Autore
{
    public const PERSONA = 'persona';

    public const PROGRAMMA = 'programma';

    /** Il nome che la riga scrive quando non c'è una persona. */
    public const NOME_DEL_PROGRAMMA = 'Il programma';

    public function __construct(
        public readonly string $tipo,
        public readonly ?int $id,
        public readonly string $nome,
        public readonly ?string $ruolo,
        public readonly string $origine,
        public readonly ?string $dettaglio,
    ) {}

    public static function persona(User $utente, string $origine = 'pagina', ?string $dettaglio = null): self
    {
        return new self(self::PERSONA, (int) $utente->getKey(), (string) $utente->name, self::ruoloDi($utente), $origine, $dettaglio);
    }

    /** Una persona che non è più un utente in sessione (il ripristino la copia nello stato quando parte). */
    public static function personaCopiata(?int $id, string $nome, ?string $ruolo, string $origine, ?string $dettaglio = null): self
    {
        return new self(self::PERSONA, $id, $nome, $ruolo, $origine, $dettaglio);
    }

    public static function programma(string $origine, ?string $dettaglio = null): self
    {
        return new self(self::PROGRAMMA, null, self::NOME_DEL_PROGRAMMA, null, $origine, $dettaglio);
    }

    /** La stessa persona o lo stesso programma con un'altra origine: «Importazione da Danea avviata da …». */
    public function conOrigine(string $origine, ?string $dettaglio): self
    {
        return new self($this->tipo, $this->id, $this->nome, $this->ruolo, $origine, $dettaglio);
    }

    /**
     * Il ruolo di serie dell'utente, nell'ordine dei poteri: chi è amministratore e collaboratore è «amministratore». Un ruolo su misura si scrive col suo
     * nome; nessun ruolo, `null`.
     */
    public static function ruoloDi(User $utente): ?string
    {
        $nomi = $utente->relationLoaded('roles') ? $utente->roles->pluck('name') : $utente->getRoleNames();
        foreach ([Role::AMMINISTRATORE, Role::COLLABORATORE, Role::FORNITORE, Role::UTENTE] as $ruolo) {
            if ($nomi->contains($ruolo->value)) {
                return $ruolo->value;
            }
        }

        $primo = $nomi->first();

        return $primo === null ? null : (string) $primo;
    }

    public function ePersona(): bool
    {
        return $this->tipo === self::PERSONA;
    }
}
