<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\RegistroModifica;
use Illuminate\Support\Carbon;

/**
 * L'esito di «Verifica l'integrità» (§3, scena 5 della bozza): integro o no, quante righe dal giorno della prima, le righe che non tornano con il motivo,
 * l'ora della verifica. Le due frasi della bozza le scrive {@see frase()}.
 */
final class EsitoDellaVerifica
{
    /**
     * @param list<array{riga_id: int, sequenza: ?int, motivo: string}> $nonTornano
     */
    public function __construct(
        public readonly bool $integro,
        public readonly int $righe,
        public readonly ?Carbon $dal,
        public readonly array $nonTornano,
        public readonly Carbon $verificataIl,
        public readonly int $daSigillare = 0,
    ) {}

    /** La prima riga che non torna, se c'è. */
    public function primaRigaCheNonTorna(): ?RegistroModifica
    {
        $prima = $this->nonTornano[0]['riga_id'] ?? null;

        return $prima === null ? null : RegistroModifica::query()->find($prima);
    }

    /**
     * La frase della bozza: «Il registro è integro. 4.812 righe dal 01/11/2026; nessuna è stata toccata fuori dal programma.» oppure «Una riga non torna.
     * Quella del 10/12/2026 alle 11:03 (regole del recupero dei crediti) è stata cambiata fuori dal programma, …». Nello stesso stile, per una riga senza
     * la firma del programma: «… è stata aggiunta fuori dal programma, …».
     */
    public function frase(): string
    {
        $fuso = (string) config('app.user_timezone', 'Europe/Rome');
        if ($this->integro) {
            $quante = number_format($this->righe, 0, ',', '.');
            $parola = $this->righe === 1 ? 'riga' : 'righe';
            $dal = $this->dal === null ? '' : ' dal ' . $this->dal->copy()->setTimezone($fuso)->format('d/m/Y');
            $nessuna = $this->righe === 1 ? 'non è stata toccata' : 'nessuna è stata toccata';

            return "Il registro è integro. {$quante} {$parola}{$dal}; {$nessuna} fuori dal programma.";
        }

        $riga = $this->primaRigaCheNonTorna();
        $motivo = $this->nonTornano[0]['motivo'] ?? 'valori';
        $quante = count($this->nonTornano);
        $testa = $quante === 1 ? 'Una riga non torna.' : "{$quante} righe non tornano. La prima:";
        $coda = 'per esempio direttamente nel database. Le altre righe tornano.';
        if ($riga === null) {
            return "{$testa} L'ultima riga sigillata non è più nel registro: è stata tolta fuori dal programma, {$coda}";
        }
        $quando = $riga->avvenuta_il->copy()->setTimezone($fuso)->format('d/m/Y \a\l\l\e H:i');
        $oggetto = mb_strtolower(mb_substr($riga->oggetto_nome, 0, 1)) . mb_substr($riga->oggetto_nome, 1);

        if (in_array($motivo, ['mancante', 'inizio'], true)) {
            return "{$testa} Manca la riga prima di quella del {$quando} ({$oggetto}): è stata tolta fuori dal programma, {$coda}";
        }
        if ($motivo === 'aggiunta') {
            // Senza la firma del programma il sigillo non l'ha messa nella catena.
            return "{$testa} Quella del {$quando} ({$oggetto}) è stata aggiunta fuori dal programma, {$coda}";
        }
        if ($motivo === 'firma') {
            // Le impronte tornano, la firma no: la riga è nella catena, ma non com'è nata dal programma.
            return "{$testa} Quella del {$quando} ({$oggetto}) è stata cambiata o aggiunta fuori dal programma, {$coda}";
        }

        return "{$testa} Quella del {$quando} ({$oggetto}) è stata cambiata fuori dal programma, {$coda}";
    }

    /** @return array<string, mixed> per la pagina e per la card «Integrità» */
    public function toArray(): array
    {
        return [
            'integro' => $this->integro,
            'righe' => $this->righe,
            'dal' => $this->dal?->toIso8601String(),
            'non_tornano' => $this->nonTornano,
            'verificata_il' => $this->verificataIl->toIso8601String(),
            'da_sigillare' => $this->daSigillare,
            'frase' => $this->frase(),
        ];
    }
}
