<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Comunicazione;
use App\Models\User;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Assemblee\Convocazione\AvvisoInBacheca;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Support\Facades\DB;

/**
 * «Avvisa i condòmini» (R12, decisione 622 (6)): la nuova comunicazione si apre **già scritta** con la frase pubblica del punto (mai il testo del punto, che
 * può portare un nome: la bacheca la leggono anche gli inquilini, Cass. 29323/2022), come l'avviso in bacheca dell'assemblea. Salvata, torna legata alla
 * cosa da fare; **pubblicata**, la cosa è fatta e il compito si chiude **in quel momento**, da qualunque strada diventi pubblicata: la sua pagina, «Approva»
 * (il modello, {@see \App\Models\Comunicazione}) o la pubblicazione programmata ({@see \App\Support\Comunicazioni\PubblicazioneProgrammata::pubblica()}).
 * Il programma non la pubblica mai da solo, e leggere il passo 6 non scrive niente.
 */
final class AvvisoAiCondomini
{
    /**
     * Il testo proposto per la pagina della nuova comunicazione.
     *
     * @return array{subject: string, description: string, condomini_ids: list<int>, cosa_da_fare_id: int, priority: string}
     */
    public static function precompilata(CosaDaFareDellaDelibera $cosa): array
    {
        $d = $cosa->delibera()->with(['punto', 'assemblea'])->firstOrFail();
        $frase = trim((string) ($d->punto?->testo_pubblico ?? ''));
        $neutra = $frase === '';
        if ($neutra) {
            // Senza la frase per la bacheca il condòmino legge di che cosa parla il punto: la riga neutra della sua materia, senza nomi (decisione D5).
            $frase = $d->punto !== null ? AvvisoInBacheca::rigaNeutra($d->punto) : 'Una delibera dell\'assemblea';
        }
        // Nel testo la frase segue i due punti: senza la frase per la bacheca la riga neutra comincia con la minuscola.
        $nelTesto = $neutra ? mb_strtolower(mb_substr($frase, 0, 1)) . mb_substr($frase, 1) : $frase;
        $importo = $d->importo ?? $d->punto?->importo;
        $tipo = mb_strtolower((string) ($d->assemblea?->tipo?->parola() ?? ''));
        $testo = 'L\'assemblea' . ($tipo !== '' ? ' ' . $tipo : '') . ' del ' . $d->deliberata_il?->format('d/m/Y') . ' ha approvato: ' . rtrim($nelTesto, '.')
            . ($importo !== null && (int) $importo > 0 && ! $neutra ? ', per una spesa di ' . FormatoDelValore::testo((int) $importo, 'euro') : '') . '.';

        return [
            'subject' => 'Assemblea del ' . $d->deliberata_il?->format('d/m/Y') . ': ' . rtrim($frase, '.'),
            'description' => $testo,
            'condomini_ids' => [(int) $d->condominio_id],
            'cosa_da_fare_id' => (int) $cosa->id,
            // La priorità la chiede il modulo: l'avviso è una comunicazione di routine, la più bassa.
            'priority' => 'bassa',
        ];
    }

    /** La cosa da fare dell'avviso, se è del condominio della comunicazione e chi scrive gestisce le assemblee. */
    public static function cosaPer(int $cosaId, ?User $utente): ?CosaDaFareDellaDelibera
    {
        if ($cosaId === 0 || ! \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($utente)) {
            return null;
        }
        $cosa = CosaDaFareDellaDelibera::query()->find($cosaId);

        return $cosa !== null && $cosa->tipo === TipoCosaDaFare::AVVISA_CONDOMINI ? $cosa : null;
    }

    /** Il ritorno: la comunicazione salvata si lega alla cosa da fare del suo condominio; pubblicata, la cosa è fatta. */
    public static function collega(Comunicazione $comunicazione, int $cosaId, ?User $utente): void
    {
        $cosa = self::cosaPer($cosaId, $utente);
        if ($cosa === null || ! $cosa->eDaFare() || ! $comunicazione->condomini()->where('condomini.id', $cosa->condominio_id)->exists()) {
            return;
        }
        DB::transaction(function () use ($cosa, $comunicazione) {
            $cosa->update(['oggetto_tipo' => 'comunicazione', 'oggetto_id' => (int) $comunicazione->id]);
            self::seEPubblicata($cosa, $comunicazione);
        });
    }

    /**
     * La comunicazione è appena diventata pubblicata (e approvata): le cose «Avvisa i condòmini» legate a lei sono fatte, con il giorno della pubblicazione, e
     * il loro compito si chiude. Lo chiamano il modello della comunicazione (dopo il salvataggio) e la pubblicazione programmata.
     */
    public static function dopoLaPubblicazione(Comunicazione $comunicazione): void
    {
        if (! ((bool) $comunicazione->is_published && (bool) $comunicazione->is_approved) || ! \Illuminate\Support\Facades\Schema::hasTable('delibere_cose_da_fare')) {
            return;
        }
        $cose = CosaDaFareDellaDelibera::query()->where('oggetto_tipo', 'comunicazione')->where('oggetto_id', (int) $comunicazione->id)
            ->where('tipo', TipoCosaDaFare::AVVISA_CONDOMINI->value)->where('stato', StatoCosaDaFare::DA_FARE->value)->get();
        foreach ($cose as $cosa) {
            DB::transaction(fn () => self::seEPubblicata($cosa, $comunicazione));
        }
    }

    private static function seEPubblicata(CosaDaFareDellaDelibera $cosa, Comunicazione $comunicazione): void
    {
        if (! ((bool) $comunicazione->is_published && (bool) $comunicazione->is_approved)) {
            return;
        }
        $giorno = ($comunicazione->pubblicata_il ?? now())->copy()->setTimezone(config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d');
        $cosa->update(['stato' => StatoCosaDaFare::FATTA->value, 'fatta_il' => $giorno]);
        ChiusuraDeiCompiti::chiudi(CoseDaFare::compito($cosa), 'Chiuso: avviso pubblicato in bacheca il ' . \Illuminate\Support\Carbon::parse($giorno)->format('d/m/Y') . '.');
    }
}
