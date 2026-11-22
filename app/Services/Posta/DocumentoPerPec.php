<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Posta;

use Illuminate\Database\Eloquent\Model;

/**
 * Un documento da mandare per PEC a una persona (decisioni 564 e 569): la convocazione di un'assemblea, e dopo le lettere ai condòmini in ritardo (sollecito,
 * diffida, messa in mora). Che cosa è (`tipo`, lo stesso di `registro_invii.tipo`), di che cosa è il documento (`documentabile`: l'assemblea, la persona per
 * una lettera), a chi va (la persona e il suo indirizzo PEC, copiato), il PDF già composto con il suo nome, l'oggetto e il testo del messaggio, e i dati della
 * riga (`dati` del registro degli invii), e gli altri PDF che partono con lui.
 */
final class DocumentoPerPec
{
    /**
     * @param array<string, mixed> $dati
     * @param list<array{file: string, contenuto: string, mime?: string}> $allegati i file che partono con il documento (per la convocazione i PDF dei
     *     documenti dei punti; per il verbale la copia sottoscritta com'è, con il suo tipo)
     */
    public function __construct(
        public readonly int $condominioId,
        public readonly ?int $anagraficaId,
        public readonly string $indirizzo,
        public readonly string $tipo,
        public readonly Model $documentabile,
        public readonly string $pdf,
        public readonly string $nomeDelFile,
        public readonly string $oggetto,
        public readonly string $testo,
        public readonly array $dati = [],
        public readonly array $allegati = [],
    ) {}
}
