<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

/**
 * La casella PEC dello studio da cui partono le convocazioni (decisione 564): l'indirizzo, il server, la porta, l'utente e la password, in chiaro solo qui
 * dentro, e la cifratura (`ssl` o `tls`: la PEC non parte in chiaro; senza, quella della porta, SSL con la 465 e TLS con le altre). La password non esce:
 * non c'è un `toArray()`, e `var_dump` e i log la nascondono.
 */
final class ConfigurazionePec
{
    public readonly string $cifratura;

    public function __construct(
        public readonly string $indirizzo,
        public readonly string $server,
        public readonly int $porta,
        public readonly string $utente,
        #[\SensitiveParameter] private readonly string $password,
        ?string $cifratura = null,
    ) {
        $this->cifratura = in_array($cifratura, ['ssl', 'tls'], true) ? $cifratura : ($porta === 465 ? 'ssl' : 'tls');
    }

    public function password(): string
    {
        return $this->password;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['indirizzo' => $this->indirizzo, 'server' => $this->server, 'porta' => $this->porta, 'cifratura' => $this->cifratura, 'utente' => $this->utente,
            'password' => '••••'];
    }
}
