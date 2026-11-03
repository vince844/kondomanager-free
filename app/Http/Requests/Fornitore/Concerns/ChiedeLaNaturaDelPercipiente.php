<?php

namespace App\Http\Requests\Fornitore\Concerns;

use App\Enums\Fiscale\TipoRitenuta;
use App\Models\Fornitore;

/**
 * Quando l'anagrafica deve pretendere la natura del percipiente (Coda 119, 03/09/2026).
 *
 * ## Perché non è semplicemente «obbligatoria se soggetto a ritenuta»
 *
 * ⚠️ **Quella regola l'abbiamo già scritta una volta, ed è costata la beta.6.** I tre campi
 * dell'override erano `required_if:soggetto_ritenuta,true`, e il risultato fu che ogni
 * fornitore già a database con la spunta e uno dei tre vuoto diventava **impossibile da
 * salvare** — anche solo per correggergli il telefono. Il commento in
 * `UpdateFornitoreRequest` lo racconta ancora. Rifarla identica su un altro campo sarebbe
 * ripetere lo stesso errore con un nome diverso.
 *
 * Qui la regola è più stretta in tre punti, e ognuno ha una ragione verificabile:
 *
 * 1. **Solo dove la natura decide qualcosa.** `TipoRitenuta::codiceTributo()` fa dipendere
 *    il codice dalla natura per un regime solo, l'appalto (1019 IRPEF / 1020 IRES). Per
 *    lavoro autonomo, provvigioni e non residenti è sempre 1040; per il lavoro dipendente
 *    sempre 1001. Chiedere il campo lì significherebbe pretendere un dato che sul modello
 *    F24 non cambia una virgola — ed è la stessa distinzione che fa il blocco a valle,
 *    presa dalla stessa fonte perché le due non possano rispondere diversamente.
 *
 * 2. **Mai ai forfetari.** `RitenutaService` esce con `MotivoEsclusioneRitenuta::FORFETARIO`
 *    prima ancora di guardare `soggetto_ritenuta`: quel fornitore non genererà mai una
 *    ritenuta, quindi non arriverà mai a un F24.
 *
 * 3. **Presa d'atto sul pregresso, non blocco.** Una scheda già incompleta si salva lo
 *    stesso finché si sta cambiando altro — l'IBAN, il telefono. L'obbligo scatta quando la
 *    si crea, quando si accende la spunta, o quando si mette le mani nel blocco fiscale:
 *    cioè quando l'incompletezza la stai producendo tu adesso, non quando la stai
 *    ereditando. Il pregresso lo prende il blocco sull'F24, che è il momento in cui il dato
 *    serve davvero e in cui l'amministratore ha una ragione per procurarselo.
 *
 * Il punto 3 è una scelta di Vincenzo del 03/09/2026, ed è lo stesso schema già adottato
 * per lo sforo di budget in modifica: presa d'atto, non override.
 */
trait ChiedeLaNaturaDelPercipiente
{
    /**
     * @param  Fornitore|null  $esistente  La scheda già a database, in modifica. In creazione è
     *                                     `null` e non c'è nessun pregresso da rispettare.
     */
    protected function laNaturaDelPercipienteServe(?Fornitore $esistente = null): bool
    {
        if (! $this->boolean('soggetto_ritenuta')) {
            return false;
        }

        if ($this->boolean('regime_forfetario')) {
            return false;
        }

        $regime = TipoRitenuta::dedotto(
            $this->input('tipo_ritenuta'),
            $this->input('perc_ritenuta'),
        );

        if (! $regime->dipendeDallaNatura()) {
            return false;
        }

        return ! $this->schedaEreditataIncompleta($esistente);
    }

    /**
     * Questa richiesta costituisce una **presa di posizione** sulla ritenuta del fornitore?
     *
     * ⚠️ Serve a `ritenuta_decisa_il` (Coda 116), che distingue «no» da «non gliel'ha mai
     * chiesto nessuno». Alla creazione la risposta è sempre sì: il riquadro fiscale è nel
     * modulo e chi salva l'ha avuto davanti. In modifica no — chi cambia l'IBAN non si è
     * pronunciato su niente, e marcare quella scheda come decisa sarebbe registrare una
     * risposta che nessuno ha dato, cioè il difetto che questa colonna esiste per chiudere.
     */
    public function costituisceUnaPresaDiPosizione(?Fornitore $esistente = null): bool
    {
        return $esistente === null || $this->bloccoFiscaleToccato($esistente);
    }

    /**
     * La presa d'atto: la scheda era **già** in questo stato e non la sto cambiando su
     * quel fronte.
     */
    private function schedaEreditataIncompleta(?Fornitore $esistente): bool
    {
        if (! $esistente) {
            return false;
        }

        $eraGiaIncompleta = (bool) $esistente->soggetto_ritenuta
            && blank($esistente->natura_percipiente);

        if (! $eraGiaIncompleta) {
            return false;
        }

        return ! $this->bloccoFiscaleToccato($esistente);
    }

    /**
     * Se questa richiesta cambia uno dei tre campi che decidono se e come si trattiene.
     *
     * ⚠️ Il confronto è sul **valore**, non sulla presenza della chiave: i moduli mandano
     * sempre il carico completo, quindi «la chiave c'è» non distingue chi ha toccato il
     * riquadro da chi ha cambiato solo l'IBAN.
     */
    private function bloccoFiscaleToccato(Fornitore $esistente): bool
    {
        $normalizza = static fn ($v) => blank($v)
            ? null
            : (string) ($v instanceof \BackedEnum ? $v->value : $v);

        if ($this->boolean('soggetto_ritenuta') !== (bool) $esistente->soggetto_ritenuta) {
            return true;
        }

        if ($normalizza($this->input('tipo_ritenuta')) !== $normalizza($esistente->tipo_ritenuta)) {
            return true;
        }

        // L'aliquota è decimale: `4` e `4.00` sono lo stesso numero scritto in due modi, e
        // confrontarli come stringhe direbbe che il riquadro è stato toccato ogni volta.
        $prima = $esistente->perc_ritenuta === null ? null : (float) $esistente->perc_ritenuta;
        $adesso = blank($this->input('perc_ritenuta')) ? null : (float) $this->input('perc_ritenuta');

        return $prima !== $adesso;
    }
}
