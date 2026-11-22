<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use App\Settings\PecSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Il punto solo da cui il programma legge la casella PEC dello studio (decisione 564: le PEC delle convocazioni partono dal programma). La casella si imposta
 * nella pagina «Configurazione email» (decisione 572) e sta nel gruppo di impostazioni suo, {@see PecSettings}; qui diventa una {@see ConfigurazionePec}
 * con la password in chiaro, solo se c'è tutto: l'indirizzo, il server, la porta, l'utente e una password che si decifra. Altrimenti `null`, e il programma
 * lo dice («la casella PEC dello studio non è impostata»). Nei test si sostituisce con `app()->instance(CasellaPec::class, …)`.
 *
 * Nessun argomento al momento della creazione: i test la sostituiscono con una classe anonima che la estende.
 */
class CasellaPec
{
    public function configurazione(): ?ConfigurazionePec
    {
        $s = $this->impostazioni();
        if ($s === null || ! filled($s->indirizzo) || ! filled($s->server) || ! filled($s->utente) || ! filled($s->password) || $s->porta <= 0) {
            return null;
        }
        $password = self::decifra($s->password);
        if ($password === null) {
            return null;
        }

        return new ConfigurazionePec((string) $s->indirizzo, (string) $s->server, (int) $s->porta, (string) $s->utente, $password, $s->cifratura);
    }

    public function impostata(): bool
    {
        return $this->configurazione() !== null;
    }

    /**
     * L'ultima prova della casella salvata, se è stata fatta con questa casella (la stessa impronta): `null` se non c'è, o se dopo la prova sono cambiati
     * server, porta, cifratura, utente o password.
     *
     * @return array{il: string, andata: bool, motivo: ?string, testo: ?string, destinatario: ?string}|null
     */
    public function ultimaProva(): ?array
    {
        $configurazione = $this->configurazione();
        $prova = $this->impostazioni()?->ultima_prova;
        if ($configurazione === null || ! is_array($prova) || ($prova['impronta'] ?? null) !== self::impronta($configurazione)) {
            return null;
        }

        return [
            'il' => (string) ($prova['il'] ?? ''),
            'andata' => (bool) ($prova['andata'] ?? false),
            'motivo' => isset($prova['motivo']) ? (string) $prova['motivo'] : null,
            'testo' => isset($prova['testo']) ? (string) $prova['testo'] : null,
            'destinatario' => isset($prova['destinatario']) ? (string) $prova['destinatario'] : null,
        ];
    }

    public static function impronta(ConfigurazionePec $c): string
    {
        return ImprontaDellaCasella::di('pec', $c->server, $c->porta, $c->cifratura, $c->utente, $c->password());
    }

    /** La password salvata in chiaro; `null` se non c'è o non si decifra più (la chiave dell'applicazione è cambiata). */
    public static function decifra(?string $cifrata): ?string
    {
        if (! filled($cifrata)) {
            return null;
        }
        try {
            return Crypt::decryptString((string) $cifrata);
        } catch (DecryptException) {
            return null;
        }
    }

    protected function impostazioni(): ?PecSettings
    {
        try {
            return app(PecSettings::class);
        } catch (\Throwable) {
            // Prima della migrazione delle impostazioni la casella non c'è: nessuna PEC parte, niente si rompe.
            return null;
        }
    }
}
