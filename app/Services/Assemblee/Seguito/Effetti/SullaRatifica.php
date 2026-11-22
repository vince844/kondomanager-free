<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\StatoMemo;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\RatificaDelloSforo;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use Illuminate\Validation\ValidationException;

/**
 * Catena 3, lo sforo e la ratifica, e i lavori urgenti riferiti. Il punto «Ratifica della spesa urgente di € 1.800,00 (fattura n. 45)» nasce dallo sforo o
 * dalla spesa sbloccata per l'urgenza, con la fattura come origine: approvato, la ratifica si lega alla delibera per la strada che c'è già
 * ({@see RatificaDelloSforo::ratifica()}): la fattura è approvata, con il giorno e il numero del verbale; si chiudono «Assemblee da convocare» e «Ratifiche in
 * assemblea» con «Chiuso dalla delibera …» e il memo della fattura. Il pagamento non cambia (decisione 561).
 *
 * Il punto «Comunicazione dei lavori urgenti …», anche senza voto: il collegamento `riferimento` sulla fattura o sul piano d'urgenza, che non porta date
 * (decisione 581: prima si riferisce, poi la ratifica). Respinta: la risposta la dà l'amministratore (R7).
 */
final class SullaRatifica implements Effetto
{
    public function righe(ContestoDellaDelibera $c): array
    {
        $materia = $c->materia();
        if (! in_array($materia, [MateriaPunto::RATIFICA, MateriaPunto::COMUNICAZIONE_URGENZA], true)) {
            return [];
        }
        $fattura = $c->scheda('fattura_passiva');
        $piano = $c->scheda('piano_rate');
        $righe = [];
        // La comunicazione dei lavori urgenti: il riferimento, anche senza voto, sulla fattura e sul piano d'urgenza.
        $riferita = $materia === MateriaPunto::COMUNICAZIONE_URGENZA
            || ($c->esito() === EsitoDelibera::SENZA_VOTO && $materia === MateriaPunto::RATIFICA);
        if ($riferita && ($c->approvata() || $c->esito() === EsitoDelibera::SENZA_VOTO)) {
            foreach ([$fattura, $piano instanceof PianoRate && $piano->tipo_autorizzazione === 'urgenza' ? $piano : null] as $scheda) {
                if ($scheda === null) {
                    continue;
                }
                $righe[] = RigaDelSeguito::applica($c, $scheda, RuoloCollegamento::RIFERIMENTO->value, 'riferimento',
                    SchedaDelCondominio::nomeDi($scheda) . ': riferita all\'assemblea',
                    $scheda instanceof FatturaPassiva ? 'La fattura resta proposta per la ratifica, con «riferita il ' . $c->giorno() . '».'
                        : 'Il piano d\'urgenza dice «Riferito all\'assemblea del ' . $c->giorno() . '». Nessuna data cambia.');
            }
        }
        if ($materia !== MateriaPunto::RATIFICA || ! $c->approvata() || ! $fattura instanceof FatturaPassiva) {
            return $righe;
        }
        $titolo = SchedaDelCondominio::nomeDi($fattura) . ', ratificata';
        $diQuesta = CollegamentiDelleDelibere::delibera($fattura, RuoloCollegamento::RATIFICA->value)?->id === $c->delibera->id;
        if (! $diQuesta && ! RatificaDelloSforo::aspettaLaRatifica($fattura) && ! RatificaDelloSforo::diPrimaSenzaDelibera($fattura)) {
            return [...$righe, RigaDelSeguito::senzaSpunta($c, 'gia_fatto', 'ratifica', $titolo, 'La fattura non aspetta una ratifica: niente cambia.', null,
                $fattura, false, [], RuoloCollegamento::RATIFICA->value)];
        }
        $memo = MemoDellAssemblea::query()->where('origine_tipo', 'fattura_passiva')->where('origine_id', $fattura->id)
            ->whereIn('stato', [StatoMemo::IN_ATTESA->value, StatoMemo::NEL_PUNTO->value])->orderBy('id')->first();
        $frase = 'Si applica adesso. Si chiudono i due compiti della ratifica' . ($memo !== null ? ' e il tuo memo del '
            . $memo->created_at?->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('d/m') : '') . '.';

        return [...$righe, RigaDelSeguito::applica($c, $fattura, RuoloCollegamento::RATIFICA->value, 'ratifica', $titolo, $frase)];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        $condominio = $c->assemblea->condominio()->firstOrFail();
        $scheda = SchedaDelCondominio::cerca($condominio, (string) ($riga['scheda']['tipo'] ?? ''), (int) ($riga['scheda']['id'] ?? 0))
            ?? throw ValidationException::withMessages(['riga' => 'La scheda non c\'è più.']);
        if ($riga['catena'] === 'riferimento') {
            CollegamentiDelleDelibere::collega($scheda, RuoloCollegamento::RIFERIMENTO->value, $c->delibera, null, $utente);

            return SchedaDelCondominio::nomeDi($scheda) . ': riferita all\'assemblea del ' . $c->giorno() . '.';
        }
        /** @var FatturaPassiva $scheda */
        RatificaDelloSforo::ratifica($scheda, $c->delibera, null, $utente);

        return SchedaDelCondominio::nomeDi($scheda) . ': ratificata il ' . $c->giorno() . '.';
    }
}
