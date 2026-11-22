<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\StatoMemo;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\DichiarazioneAVerbale;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\User;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\EsitiNelleSchede;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use Illuminate\Validation\ValidationException;

/**
 * Ciò che vale per ogni punto, qualunque sia la catena (decisione 522 (2)–(5)):
 *
 * - **Respinta** (R7): nessuna conseguenza nei conti; se il punto ha un'origine nel programma (la fattura, il piano, l'accordo, la gestione, il fondo, il
 *   rendiconto, un memo) la risposta da dare, «Riproponi in assemblea» o «Chiudi con il motivo», con il compito «Delibere respinte».
 * - **Rinviata** (R8): il memo torna in attesa (lo fa già la sala) e il sospeso torna proposto con «rinviato dal …»: una frase.
 * - **Le varie e i punti senza voto** (R9): «Portalo alla prossima assemblea» apre la finestra del memo con l'origine nel punto e il testo delle dichiarazioni.
 * - **«Avvisa i condòmini»** (R12, decisione 622 (6)): acceso di serie per le cose di tutti (riscaldamento, regolamento, lavori, innovazioni, destinazione
 *   d'uso), spento per le altre, **assente** dove porterebbe un nome (accordi, dispense, ratifiche, liti, nomina) e dove non c'è niente da dire. Le materie
 *   con la maggioranza della legge (decisione 640 (1)) come la materia di prima più vicina: acceso per le innovazioni agevolate, la videosorveglianza e i
 *   progetti del territorio (come innovazioni e lavori) e per il sito internet (come il regolamento); assente per la tutela della destinazione d'uso, come
 *   le liti, perché dice chi usa male le parti comuni; spento per la rettifica delle tabelle, come le tabelle, e per le modalità degli impianti dei singoli,
 *   che parlano dell'impianto di una persona.
 */
final class SulPunto implements Effetto
{
    /** Le cose di tutti: l'avviso è acceso di serie. */
    public const AVVISO_DI_SERIE = [MateriaPunto::RISCALDAMENTO, MateriaPunto::REGOLAMENTO, MateriaPunto::LAVORI, MateriaPunto::INNOVAZIONI,
        MateriaPunto::DESTINAZIONE_USO, MateriaPunto::INNOVAZIONI_AGEVOLATE, MateriaPunto::VIDEOSORVEGLIANZA, MateriaPunto::PROGETTI_TERRITORIO,
        MateriaPunto::SITO_INTERNET];

    /** Dove l'avviso non c'è: porta nomi, o non cambia la vita del palazzo (Cass. 29323/2022: un nome in bacheca non deve partire nemmeno per sbaglio). */
    public const AVVISO_MAI = [MateriaPunto::ACCORDO, MateriaPunto::DISPENSA, MateriaPunto::RATIFICA, MateriaPunto::COMUNICAZIONE_URGENZA, MateriaPunto::LITI,
        MateriaPunto::NOMINA, MateriaPunto::VARIE, MateriaPunto::COMUNICAZIONE, MateriaPunto::TUTELA_DESTINAZIONE_USO];

    public function righe(ContestoDellaDelibera $c): array
    {
        $righe = [];
        $esito = $c->esito();
        if ($esito === EsitoDelibera::RESPINTA) {
            $origine = $c->origine();
            $memo = $c->punto === null ? null : MemoriaDelCondominio::delPunto($c->punto)->first();
            if ($origine === null && $memo === null) {
                return [RigaDelSeguito::senzaSpunta($c, 'nota', 'respinta', 'Respinta', 'Nessuna conseguenza nei conti.')];
            }
            $frase = $origine === null ? 'Il memo resta chiuso: la risposta la dai tu.' : EsitiNelleSchede::conseguenza($origine);

            return [RigaDelSeguito::cosa($c, TipoCosaDaFare::DOPO_IL_RIFIUTO, 'respinta', $origine === null ? 'Respinta' : EsitiNelleSchede::respinta($origine, $c),
                $frase . ' Il programma non decide le conseguenze: «Riproponi in assemblea» o «Chiudi con il motivo».',
                ($origine === null ? 'Respinta' : EsitiNelleSchede::domanda($origine)) . ': che cosa fai?', true, $origine)];
        }
        if ($esito === EsitoDelibera::RINVIATA) {
            return [RigaDelSeguito::senzaSpunta($c, 'nota', 'rinviata', 'Rinviata', 'Il punto torna nella memoria del condominio, «rinviato dal ' . $c->giorno()
                . '»: si propone alla prossima assemblea con la sua origine.')];
        }
        if ($esito === EsitoDelibera::SENZA_VOTO && in_array($c->materia(), [MateriaPunto::VARIE, MateriaPunto::COMUNICAZIONE], true) && $c->punto !== null) {
            $memo = MemoDellAssemblea::query()->where('origine_tipo', 'punto')->where('origine_id', $c->punto->id)
                ->whereIn('stato', [StatoMemo::IN_ATTESA->value, StatoMemo::NEL_PUNTO->value, StatoMemo::DELIBERATO->value])->exists();
            $testo = DichiarazioneAVerbale::query()->where('punto_id', $c->punto->id)->orderBy('id')->pluck('testo')->filter()->implode(' ');
            $righe[] = RigaDelSeguito::senzaSpunta($c, 'memo', 'varie', $c->materia() === MateriaPunto::VARIE ? 'Varie ed eventuali' : 'Comunicazione',
                'Nelle ' . ($c->materia() === MateriaPunto::VARIE ? 'varie' : 'comunicazioni') . ' non si vota: «Portalo alla prossima assemblea», come un tuo memo, con l\'origine «'
                . ($c->materia() === MateriaPunto::VARIE ? 'varie' : 'punto') . ' del ' . $c->giorno() . '».', null, $c->punto, $memo,
                ['memo' => ['testo' => trim($testo), 'origine_tipo' => 'punto', 'origine_id' => (int) $c->punto->id]]);
        }
        if ($c->approvata() && ! in_array($c->materia(), self::AVVISO_MAI, true)) {
            $diSerie = in_array($c->materia(), self::AVVISO_DI_SERIE, true);
            $righe[] = RigaDelSeguito::cosa($c, TipoCosaDaFare::AVVISA_CONDOMINI, 'avviso', 'Avvisa i condòmini',
                'Preparo la comunicazione in bacheca con la frase pubblica del punto, come bozza: la rileggi prima di pubblicarla. La bacheca la leggono anche gli '
                . 'inquilini.', 'Avvisa i condòmini: ' . trim((string) ($c->punto?->testo_pubblico ?: $c->punto?->testo ?: 'la delibera')), $diSerie);
        }

        return $righe;
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        throw ValidationException::withMessages(['riga' => 'Questa riga si fa dalla sua finestra.']);
    }
}
