<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Exceptions\Pagamenti;

use App\Helpers\MoneyHelper;
use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoAllocazioneFattura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\FatturaScrittura;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Support\Collection;

/**
 * Il bonifico parlante di una fattura registrata con la ritenuta d'acconto (Coda 754 (2), decisione 696: «Fermarsi e dirlo»).
 *
 * La fattura con la ritenuta scrive la ritenuta su «Debiti v/Erario» già alla registrazione, e il debito verso il fornitore al netto.
 * Con il bonifico parlante la ritenuta la opera la banca e il condominio non la trattiene: se il pagamento la mettesse a zero lasciando
 * la fattura com'è, uscirebbe il netto e la ritenuta resterebbe sull'Erario senza che nessun F24 la versi. **La ritenuta si decide in
 * un posto solo, la fattura**: il pagamento si rifiuta, e la frase dice di togliere prima la ritenuta dalla fattura (motivo «Bonifico
 * parlante»); poi esce l'importo pieno. Una pregressa non si modifica: la frase dice di stornarla e registrarla di nuovo.
 *
 * La lancia `PagamentoFornitoreService` (in registrazione e quando la modifica mette la spunta); il controller la mostra sul campo
 * `bonifico_parlante`. HTTP: 422, come gli altri rifiuti di dominio.
 */
class BonificoParlanteConRitenutaException extends PagamentoException
{
    /**
     * @param  list<int>  $fattureIds  le fatture con la ritenuta che il pagamento voleva pagare con il bonifico parlante
     */
    public function __construct(string $message, public readonly array $fattureIds = [])
    {
        parent::__construct($message);
    }

    /**
     * Il rifiuto per le fatture con la ritenuta di un pagamento con il bonifico parlante, con la frase che dice che cosa fare.
     *
     * @param  Collection<int, FatturaPassiva>  $fatture  solo quelle con la ritenuta
     */
    public static function perLeFatture(Collection $fatture, string $fornitore): self
    {
        $fatture = $fatture->values();
        $perche = 'Con il bonifico parlante la ritenuta la opera la banca e il condominio non la trattiene';
        $numero = fn (FatturaPassiva $f) => 'n. '.($f->numero_documento ?: "#{$f->id}");
        $ritenuta = fn (FatturaPassiva $f) => MoneyHelper::format((int) $f->importo_ritenuta);

        if ($fatture->count() === 1) {
            $f = $fatture->first();
            // L'importo pieno è quello che la fattura chiederà senza la ritenuta: il netto di oggi più la ritenuta.
            $pieno = MoneyHelper::format(abs((int) $f->netto_a_pagare) + (int) $f->importo_ritenuta);
            $fare = self::laVia($f, $pieno);

            return new self("La fattura {$numero($f)} di {$fornitore} è registrata con {$ritenuta($f)} di ritenuta. {$perche}: {$fare}", [(int) $f->id]);
        }

        $elenco = $fatture->map(fn (FatturaPassiva $f) => "{$numero($f)} ({$ritenuta($f)} di ritenuta)")->all();
        $ultima = array_pop($elenco);
        $messaggio = 'Le fatture '.implode(', ', $elenco)." e {$ultima} di {$fornitore} sono registrate con la ritenuta. {$perche}: "
            .'togli prima la ritenuta dalle fatture (Modifica, motivo «Bonifico parlante»), poi registra il pagamento dell\'importo pieno.';

        $conPagamenti = $fatture->filter(fn (FatturaPassiva $f) => $f->stato_pagamento !== null && $f->stato_pagamento !== StatoPagamentoFattura::APERTA);
        if ($conPagamenti->isNotEmpty()) {
            $messaggio .= ' Quelle che hanno già un pagamento ('.$conPagamenti->map($numero)->implode(', ').') non si modificano: storna prima quel pagamento.';
        }

        $pregresse = $fatture->filter(fn (FatturaPassiva $f) => (bool) $f->is_pregresso);
        if ($pregresse->isNotEmpty()) {
            $messaggio .= ' Le pregresse ('.$pregresse->map($numero)->implode(', ').') non si modificano: stornale e registrale di nuovo senza la ritenuta, con il motivo.';
        }

        return new self($messaggio, $fatture->map(fn (FatturaPassiva $f) => (int) $f->id)->all());
    }

    /**
     * La strada vera per togliere la ritenuta da una fattura: la modifica solo se la modifica è possibile oggi. Con un pagamento già
     * registrato (o un altro blocco della modifica: ratifica, copertura dal fondo) la modifica si rifiuta, e la frase lo dice con il
     * pagamento da stornare; l'importo pieno è quello della fattura intera, che si chiede dopo lo storno.
     */
    private static function laVia(FatturaPassiva $f, string $pieno): string
    {
        $blocco = app(FatturaPassivaService::class)->motivoBloccoModifica($f);

        if ($f->is_pregresso) {
            return "la fattura è pregressa e non si modifica, quindi stornala e registrala di nuovo senza la ritenuta, con il motivo «Bonifico parlante»; poi registra il pagamento di {$pieno}.";
        }

        if ($blocco === null) {
            return "togli prima la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante»), poi registra il pagamento di {$pieno}.";
        }

        $pagamenti = FatturaScrittura::query()
            ->where('fattura_passiva_id', $f->id)
            ->whereIn('tipo', TipoAllocazioneFattura::perCalcoloSaldo())
            ->where('importo_allocato', '>', 0)
            ->get()
            ->map(function (FatturaScrittura $r) {
                $p = PagamentoFornitore::confermati()->whereNull('pagamento_padre_id')->where('scrittura_contabile_id', $r->scrittura_contabile_id)->first();

                return $p ? 'il pagamento di '.MoneyHelper::format((int) $r->importo_allocato).' del '.$p->data_pagamento->format('d/m/Y') : null;
            })
            ->filter()
            ->values();

        if ($pagamenti->isNotEmpty()) {
            return 'la fattura ha già '.$pagamenti->implode(' e ')."; per pagarla con il bonifico parlante storna prima quel pagamento, poi togli la ritenuta dalla fattura (Modifica, motivo «Bonifico parlante») e registra il pagamento di {$pieno}.";
        }

        return "la fattura non si modifica adesso. {$blocco} Risolto questo, togli la ritenuta (Modifica, motivo «Bonifico parlante») e registra il pagamento di {$pieno}.";
    }
}
