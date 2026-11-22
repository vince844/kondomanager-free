<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\Cassa;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo sblocco di un fondo vincolato con la delibera dell'assemblea (la deroga): il pezzo che stava nel controllore delle casse (`CassaController::update`), in
 * un servizio che chiamano la pagina del fondo e il seguito delle delibere. La guardia del modello resta la verità (`Cassa::booted`: un fondo vincolato si
 * sblocca solo con la motivazione); dalla delibera la motivazione è la frase della delibera **in testa**, con il testo di prima dopo.
 */
final class DerogaDelFondo
{
    /** Dalla pagina del fondo: lega la delibera scelta, o toglie il legame se la delibera è tolta o lo sblocco è spento. Il testo della motivazione resta. */
    public static function dallaPagina(Cassa $cassa, ?Delibera $delibera, bool $toltaOSpenta, ?User $utente): void
    {
        if ($delibera !== null && $utente !== null) {
            CollegamentiDelleDelibere::collega($cassa, RuoloCollegamento::DEROGA->value, $delibera, null, $utente);

            return;
        }
        if ($toltaOSpenta && $utente !== null) {
            CollegamentiDelleDelibere::scollega($cassa, RuoloCollegamento::DEROGA->value, $utente);
        }
    }

    /** Il fondo si può sbloccare con una delibera? Un fondo, vincolato (non generico). */
    public static function siSblocca(Cassa $cassa): bool
    {
        return $cassa->tipo === 'fondo' && ($cassa->sottotipo_fondo ?? 'generico') !== 'generico';
    }

    /** «Sblocca il fondo» dalla delibera dell'assemblea: lo sblocco con la frase della delibera in testa, e il collegamento, in una transazione. */
    public static function sblocca(Cassa $cassa, Delibera $delibera, User $utente): void
    {
        if (! self::siSblocca($cassa)) {
            throw ValidationException::withMessages(['cassa' => 'Il fondo «' . $cassa->nome . '» non è vincolato: non c\'è niente da sbloccare.']);
        }
        $frase = 'Sbloccato con la ' . lcfirst(DeliberaInParole::breve($delibera, scritta: true)) . '.';
        $prima = trim((string) $cassa->motivazione_override);
        DB::transaction(function () use ($cassa, $delibera, $utente, $frase, $prima) {
            $cassa->update(['is_override_assemblea' => true, 'motivazione_override' => $prima === '' || str_starts_with($prima, $frase) ? ($prima === '' ? $frase : $prima)
                : $frase . "\n" . $prima]);
            CollegamentiDelleDelibere::collega($cassa, RuoloCollegamento::DEROGA->value, $delibera, null, $utente);
        });
    }
}
