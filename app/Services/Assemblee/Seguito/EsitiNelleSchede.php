<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\RuoloOggettoPunto;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\OggettoDelPunto;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\SchedaDelCondominio;
use Illuminate\Database\Eloquent\Model;

/**
 * Che cosa dice una scheda dell'assemblea che l'ha decisa (R7, R8, R11; P4 del progetto della tappa): «Respinto dall'assemblea del 15/12/2026, punto 2
 * (verbale n. 42).» sul piano, «Ratifica respinta …» sulla fattura, «Autorizzazione respinta …» sull'accordo, «Rinviato dall'assemblea del …»; e i segni
 * delle delibere che la scheda usa: «Revocata il …», «Annullata dal giudice il …», «Impugnata il … · non ferma niente». **Nessuna conseguenza nei conti**:
 * sono frasi, e le schede le mostrano accanto alla frase della delibera.
 */
final class EsitiNelleSchede
{
    /**
     * Le frasi di una scheda: le delibere respinte o rinviate di un punto nato da lei (dalla più recente), e i segni delle delibere che la usano.
     *
     * @return list<array{tipo: string, frase: string, delibera_id: int, assemblea_id: int}>
     */
    public static function perLaScheda(Model $scheda): array
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        $id = (int) $scheda->getKey();
        $frasi = [];
        $punti = OggettoDelPunto::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)
            ->whereIn('ruolo', [RuoloOggettoPunto::ORIGINE->value, RuoloOggettoPunto::DOCUMENTO->value])->pluck('punto_id');
        $esiti = Delibera::query()->whereIn('punto_id', $punti)->whereIn('esito', [EsitoDelibera::RESPINTA->value, EsitoDelibera::RINVIATA->value])
            ->with(['punto', 'assemblea'])->orderByDesc('deliberata_il')->orderByDesc('id')->get();
        foreach ($esiti as $d) {
            $c = ContestoDellaDelibera::per($d);
            $frasi[] = ['tipo' => (string) $d->esito->value, 'frase' => ($d->esito === EsitoDelibera::RESPINTA ? self::respinta($scheda, $c)
                : self::rinviata($scheda, $c)) . '.', 'delibera_id' => (int) $d->id, 'assemblea_id' => (int) $d->assemblea_id];
        }
        $collegate = CollegamentoAllaDelibera::query()->where('oggetto_tipo', $tipo)->where('oggetto_id', $id)->pluck('delibera_id')->unique();
        foreach (Delibera::query()->whereIn('id', $collegate)->with(['punto', 'assemblea', 'revocataDa.punto'])->orderBy('id')->get() as $d) {
            foreach (SegniDellaDelibera::frasi($d) as $segno) {
                $frasi[] = $segno + ['delibera_id' => (int) $d->id, 'assemblea_id' => (int) $d->assemblea_id];
            }
        }

        return $frasi;
    }

    /** «Respinto dall'assemblea del 15/12/2026, punto 2 (verbale n. 42)», con la parola della scheda. */
    public static function respinta(Model $scheda, ContestoDellaDelibera $c): string
    {
        return self::parola($scheda, 'respinto') . ' ' . $c->dallAssemblea();
    }

    /** «Rinviato dall'assemblea del 15/12/2026, punto 4». */
    public static function rinviata(Model $scheda, ContestoDellaDelibera $c): string
    {
        return self::parola($scheda, 'rinviato') . ' ' . $c->dallAssemblea();
    }

    /** Ciò che resta com'è dopo una respinta: la scheda non cambia, e la frase lo dice. */
    public static function conseguenza(Model $scheda): string
    {
        return match (SchedaDelCondominio::tipoDi($scheda)) {
            'piano_rate' => 'Il piano resta in bozza: nessuna rata parte.',
            'fattura_passiva' => 'La fattura pagata resta pagata, quella non pagata resta ferma come oggi; i compiti dello sforo restano aperti fino alla tua risposta.',
            'accordo_rientro' => 'L\'accordo resta registrato, senza autorizzazione: non si chiude da solo.',
            'rendiconto_versione' => 'La versione resta presentata, e la riga dei 180 giorni resta.',
            'gestione' => 'La gestione resta com\'è: nessun piano nasce.',
            'cassa' => 'Il fondo resta vincolato.',
            default => 'Nessuna conseguenza nei conti.',
        };
    }

    /** La domanda del compito: «Il preventivo 2027 è stato respinto», «La ratifica della fattura n. 45 è stata respinta». */
    public static function domanda(Model $scheda): string
    {
        $nome = SchedaDelCondominio::nomeDi($scheda);

        return match (SchedaDelCondominio::tipoDi($scheda)) {
            'fattura_passiva' => 'La ratifica della ' . lcfirst(explode(' · ', $nome)[0]) . ' è stata respinta',
            'accordo_rientro' => 'L\'autorizzazione dell\'' . lcfirst($nome) . ' è stata respinta',
            'rendiconto_versione' => 'Il rendiconto è stato respinto',
            'gestione' => 'I lavori della ' . lcfirst($nome) . ' sono stati respinti',
            default => $nome . ': respinto',
        };
    }

    /** La parola della scheda: «Respinto» (il piano), «Ratifica respinta» (la fattura), «Autorizzazione respinta» (l'accordo), «Lavori respinti» (la gestione). */
    private static function parola(Model $scheda, string $come): string
    {
        $femminile = $come === 'respinto' ? 'respinta' : 'rinviata';
        $plurale = $come === 'respinto' ? 'respinti' : 'rinviati';

        return match (SchedaDelCondominio::tipoDi($scheda)) {
            'fattura_passiva' => 'Ratifica ' . $femminile,
            'accordo_rientro' => 'Autorizzazione ' . $femminile,
            'rendiconto_versione' => 'Rendiconto ' . $come,
            'gestione' => 'Lavori ' . $plurale,
            'cassa' => 'Utilizzo del fondo ' . $come,
            'dispensa_sei_mesi' => 'Revoca ' . $femminile,
            default => ucfirst($come),
        };
    }

    /** La frase di una delibera per una scheda che la usa: «Delibera del 15/12/2026, punto 2 (verbale n. 42)». */
    public static function fraseDellaDelibera(Delibera $delibera): string
    {
        return DeliberaInParole::breve($delibera);
    }
}
