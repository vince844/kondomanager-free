<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catena 4, lo straordinario: i lavori approvati. **Niente nei conti finché non c'è il piano** (decisione 622 (1)): la cosa da fare «Crea il piano
 * straordinario», con la data e la persona, che si chiude da sola quando il piano si salva con la delibera (R5). Se il punto è nato da una gestione (la
 * gestione è l'origine del punto), la gestione si lega alla delibera (ruolo `delibera`): le fatture e gli stati di avanzamento che verranno la trovano proposta
 * in «Scegli la delibera». Una gestione di cui il punto parla soltanto («Completamento della facciata» che parla di «Facciata 2025») non si lega.
 *
 * **Il fondo speciale** (decisione 622 (2)): la somma di ciò che chiedono i piani della delibera contro l'importo deliberato, con la frase della legge
 * (art. 1135 c. 1 n. 4 c.c.); lo dice, non blocca, e sparisce quando i piani arrivano ai lavori ({@see fondoSpeciale()}).
 *
 * **Approvata con modifiche** (decisione 637 (1)): in sala le modifiche sono un testo («Importo ridotto a € 13.000,00»), non una cifra nuova. La cifra di
 * prima non è più deliberata: la cosa non la porta, e il fondo speciale non confronta con lei.
 */
final class SuiLavori implements Effetto
{
    /**
     * Le materie dei lavori: il piano straordinario e il fondo speciale. Con i lavori e le innovazioni, le materie della legge che portano opere da pagare
     * tutti (decisione 640 (1)): le innovazioni agevolate, la videosorveglianza, i progetti del territorio. Le modalità degli impianti dei singoli no: l'impianto
     * è di un condòmino, non del condominio (art. 1122-bis c. 3 c.c.).
     */
    public const MATERIE = [MateriaPunto::LAVORI, MateriaPunto::INNOVAZIONI, MateriaPunto::INNOVAZIONI_AGEVOLATE, MateriaPunto::VIDEOSORVEGLIANZA,
        MateriaPunto::PROGETTI_TERRITORIO];

    /** Le parole della legge sul fondo speciale (art. 1135 c. 1 n. 4 c.c., riletto su Normattiva il 18/11/2026). */
    public const LEGGE_DEL_FONDO = 'La legge vuole un fondo «di importo pari all\'ammontare dei lavori»; se il contratto paga a stati di avanzamento, il fondo '
        . '«può essere costituito in relazione ai singoli pagamenti dovuti» (art. 1135 n. 4 c.c.). Non blocca niente: decidi tu.';

    public function righe(ContestoDellaDelibera $c): array
    {
        if (! in_array($c->materia(), self::MATERIE, true) || ! $c->approvata()) {
            return [];
        }
        $righe = [];
        $gestione = $c->schedaDOrigine('gestione');
        if ($gestione !== null) {
            $righe[] = RigaDelSeguito::applica($c, $gestione, RuoloCollegamento::DELIBERA->value, 'lavori',
                SchedaDelCondominio::nomeDi($gestione) . ': legata alla delibera',
                'Si applica adesso: le fatture e i piani della gestione trovano la delibera proposta in «Scegli la delibera». Nessun conto cambia.');
        }
        $importo = $c->conModifiche() ? null : ($c->delibera->importo ?? $c->punto?->importo);
        $testo = 'Crea il piano straordinario' . ($importo !== null && (int) $importo > 0 ? ' (' . FormatoDelValore::testo((int) $importo, 'euro') . ')' : '');
        $righe[] = RigaDelSeguito::cosa($c, TipoCosaDaFare::CREA_PIANO_STRAORDINARIO, 'lavori', 'Approvata, ma resta da fare',
            'La delibera è «Da eseguire» finché il piano non c\'è: nasce con la delibera già scelta, e allora la cosa si chiude da sola.', $testo, true, $gestione);

        return $righe;
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        $condominio = $c->assemblea->condominio()->firstOrFail();
        $gestione = SchedaDelCondominio::cerca($condominio, 'gestione', (int) ($riga['scheda']['id'] ?? 0))
            ?? throw ValidationException::withMessages(['riga' => 'La gestione non c\'è più.']);
        CollegamentiDelleDelibere::collega($gestione, RuoloCollegamento::DELIBERA->value, $c->delibera, null, $utente);

        return SchedaDelCondominio::nomeDi($gestione) . ': legata alla delibera del ' . $c->giorno() . '.';
    }

    /**
     * Il fondo speciale di una delibera di lavori: quanto chiedono i piani legati alla delibera (le rate generate, o le voci del piano se le rate non ci sono
     * ancora) contro l'importo deliberato, in centesimi. Null se la delibera non ha un importo, se è approvata con modifiche (la cifra di prima non è
     * deliberata) o non ha piani; `pari` quando i piani arrivano ai lavori.
     *
     * @return ?array{chiesti: int, deliberati: int, pari: bool, frase: ?string, legge: string}
     */
    public static function fondoSpeciale(Delibera $delibera): ?array
    {
        $deliberati = (int) ($delibera->importo ?? 0);
        if ($deliberati <= 0 || $delibera->esito === \App\Enums\Assemblee\EsitoDelibera::APPROVATA_CON_MODIFICHE) {
            return null;
        }
        $piani = CollegamentoAllaDelibera::query()->where('delibera_id', $delibera->id)->where('oggetto_tipo', 'piano_rate')
            ->where('ruolo', RuoloCollegamento::DELIBERA->value)->pluck('oggetto_id')->map(fn ($id) => (int) $id)->all();
        $gestioni = CollegamentoAllaDelibera::query()->where('delibera_id', $delibera->id)->where('oggetto_tipo', 'gestione')
            ->where('ruolo', RuoloCollegamento::DELIBERA->value)->pluck('oggetto_id')->all();
        if ($gestioni !== []) {
            $piani = array_values(array_unique([...$piani, ...PianoRate::query()->whereIn('gestione_id', $gestioni)->pluck('id')->map(fn ($id) => (int) $id)->all()]));
        }
        if ($piani === []) {
            return null;
        }
        $chiesti = 0;
        foreach ($piani as $id) {
            $rate = (int) DB::table('rate')->where('piano_rate_id', $id)->sum('importo_totale');
            $chiesti += $rate !== 0 ? $rate : (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $id)->sum('importo');
        }
        $pari = $chiesti >= $deliberati;
        $euro = fn (int $c) => (string) FormatoDelValore::testo($c, 'euro');

        return ['chiesti' => $chiesti, 'deliberati' => $deliberati, 'pari' => $pari,
            'frase' => $pari ? null : 'Fondo speciale: ' . $euro($chiesti) . ' chiesti su ' . $euro($deliberati) . ' deliberati.', 'legge' => self::LEGGE_DEL_FONDO];
    }
}
