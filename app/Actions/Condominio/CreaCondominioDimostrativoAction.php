<?php

namespace App\Actions\Condominio;

use App\Models\Condominio;
use Database\Seeders\CondominioDemoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Costruisce il condominio dimostrativo, e lo sa rimuovere.
 *
 * ## Perché sta in un'azione e non nel controller
 *
 * Perché la parte difficile non è crearlo — quello lo fa il seeder — ma **poterlo togliere**. I
 * vincoli del database impediscono di eliminare un condominio che ha movimenti contabili
 * (`pagamenti_fornitori`, `deleghe_f24` e `casse` sono in `ON DELETE RESTRICT`), ed è giusto così:
 * un amministratore non deve poter cancellare la contabilità di un condominio vero premendo un
 * pulsante.
 *
 * ⚠️ **Il condominio dimostrativo è l'unico caso in cui quella regola non ha ragione di esistere**,
 * perché quei movimenti li ha scritti il programma e non c'è nessuna contabilità reale da
 * proteggere. Da qui la colonna `is_demo`, e da qui il fatto che questa rimozione **rifiuta** di
 * toccare qualunque condominio che non la porti.
 */
class CreaCondominioDimostrativoAction
{
    /**
     * @return array{condominio: Condominio, avvisi: list<string>}
     */
    public function esegui(): array
    {
        $seeder = new CondominioDemoSeeder();
        $seeder->run();

        return [
            'condominio' => $seeder->condominio(),
            'avvisi'     => $seeder->avvisi,
        ];
    }

    /**
     * Rimuove un condominio dimostrativo e tutto ciò che gli sta attaccato.
     *
     * ⚠️ **L'ordine non è decorativo.** Si cancella dalle foglie verso la radice, perché ogni
     * vincolo `RESTRICT` sulla strada fermerebbe tutto: prima i pagamenti e le deleghe che
     * puntano ai conti contabili, poi le scritture, poi le casse, e solo alla fine il condominio.
     *
     * @throws \RuntimeException se il condominio non è dimostrativo
     */
    public function rimuovi(Condominio $condominio): void
    {
        if (! $condominio->is_demo) {
            // Non è una difesa teorica: senza, un errore di rotta o un id cambiato a mano
            // cancellerebbe la contabilità di un condominio vero senza che niente lo fermi.
            throw new \RuntimeException(
                'Questo condominio non è dimostrativo: non può essere rimosso da qui.'
            );
        }

        DB::transaction(function () use ($condominio) {
            $id = $condominio->id;

            $contiContabili = DB::table('conti_contabili')->where('condominio_id', $id)->pluck('id');
            $scritture      = DB::table('scritture_contabili')->where('condominio_id', $id)->pluck('id');
            $fatture        = DB::table('fatture_passive')->where('condominio_id', $id)->pluck('id');
            $pagamenti      = DB::table('pagamenti_fornitori')->where('condominio_id', $id)->pluck('id');

            /*
             * ⚠️ **L'ordine è derivato dai vincoli del database, non indovinato.** Interrogando
             * `information_schema` per i vincoli che **non** sono a cascata, i blocchi verso
             * un'entità del condominio sono sei:
             *
             *   pagamenti_fornitori  -> condomini, conti_contabili   RESTRICT
             *   deleghe_f24          -> condomini, casse, conti, esercizi   RESTRICT
             *   casse                -> conti_contabili              RESTRICT
             *   saldi                -> gestioni                     RESTRICT
             *   fatture_passive      -> esercizi                     NO ACTION
             *   righe_fattura        -> conti                        NO ACTION
             *
             * Ognuno di questi, se lasciato in piedi, ferma tutta la cancellazione con un errore
             * SQL grezzo. Si va quindi dalle foglie alla radice, e **solo alla fine** si cancella il
             * condominio: da lì in giù il resto scende a cascata.
             *
             * Provato: due tentativi precedenti hanno fallito proprio perché l'ordine era stato
             * dedotto a mente invece che dallo schema.
             */

            // 1. I pagamenti e le deleghe F24, che bloccano condominio, casse, conti ed esercizi.
            DB::table('riga_f24_pagamento')->whereIn('pagamento_fornitore_id', $pagamenti)->delete();
            DB::table('pagamenti_fornitori')->where('condominio_id', $id)->delete();
            DB::table('deleghe_f24')->where('condominio_id', $id)->delete();

            // 2. Le fatture con tutto ciò che ci pende: bloccano gli esercizi e le voci di spesa.
            DB::table('fattura_scrittura')->whereIn('fattura_passiva_id', $fatture)->delete();
            DB::table('fattura_coperture')->whereIn('fattura_passiva_id', $fatture)->delete();
            DB::table('righe_fattura')->whereIn('fattura_passiva_id', $fatture)->delete();
            DB::table('fatture_passive')->where('condominio_id', $id)->delete();

            // 3. Le scritture contabili e le loro righe.
            DB::table('righe_scritture')->whereIn('scrittura_id', $scritture)->delete();
            DB::table('scritture_contabili')->where('condominio_id', $id)->delete();

            // 4. I saldi, che bloccano le gestioni.
            DB::table('saldi')->where('condominio_id', $id)->delete();

            // 5. Le casse, che bloccano i conti contabili.
            DB::table('casse')->where('condominio_id', $id)->delete();

            // 6. Il condominio: da qui scendono a cascata immobili, tabelle, esercizi, gestioni,
            //    piani dei conti, voci, piani rate, rate e quote.
            $condominio->delete();

            // 7. I conti contabili, che nessuno porta via.
            DB::table('conti_contabili')->whereIn('id', $contiContabili)->delete();

            // 8. Il fornitore dimostrativo, ma **solo se non ha più fatture**: è una rubrica
            //    condivisa fra i condomini, e potrebbe servire a qualcun altro.
            $fornitore = DB::table('fornitori')
                ->where('ragione_sociale', 'Impresa Manutenzioni Demo s.r.l.')
                ->first();

            if ($fornitore && ! DB::table('fatture_passive')->where('fornitore_id', $fornitore->id)->exists()) {
                DB::table('fornitori')->where('id', $fornitore->id)->delete();
            }
        });
    }

    /**
     * Esiste già un condominio dimostrativo?
     *
     * ⚠️ **Uno alla volta, per scelta.** Due demo non servono a niente — chi vuole capire il
     * programma ne guarda una — e costano: i numeri di documento, il codice e l'email sono unici a
     * database, e due create nello stesso secondo collidevano davvero. Il pulsante si spegne quando
     * ce n'è già una, e si riaccende quando viene rimossa.
     */
    public function esisteGia(): ?Condominio
    {
        return Condominio::where('is_demo', true)->first();
    }
}
