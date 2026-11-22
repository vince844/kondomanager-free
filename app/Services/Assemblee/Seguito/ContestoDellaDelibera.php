<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloOggettoPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ciò che una catena legge di una delibera, letto una volta: il punto, la materia, gli oggetti del punto (la scheda da cui nasce, quelle di cui parla, i
 * documenti), le altre delibere dello stesso punto (il rendiconto ha due votazioni: il rendiconto e lo stato di riparto), il verbale del suo giorno.
 */
final class ContestoDellaDelibera
{
    /** @param list<Delibera> $delloStessoPunto */
    public function __construct(
        public readonly Delibera $delibera,
        public readonly ?PuntoDellOrdineDelGiorno $punto,
        public readonly Assemblea $assemblea,
        public readonly array $delloStessoPunto,
    ) {}

    public static function per(Delibera $delibera): self
    {
        $delibera->loadMissing(['punto.oggetti', 'assemblea']);
        $altre = $delibera->punto_id === null ? [] : Delibera::query()->where('punto_id', $delibera->punto_id)->whereKeyNot($delibera->id)
            ->orderBy('votazione')->orderBy('id')->get()->all();

        return new self($delibera, $delibera->punto, $delibera->assemblea, $altre);
    }

    public function materia(): ?MateriaPunto
    {
        return $this->punto?->materia;
    }

    public function esito(): ?EsitoDelibera
    {
        return $this->delibera->esito;
    }

    public function approvata(): bool
    {
        return $this->delibera->eApprovata();
    }

    public function conModifiche(): bool
    {
        return $this->delibera->esito === EsitoDelibera::APPROVATA_CON_MODIFICHE;
    }

    /** La prima scheda di quel tipo fra gli oggetti del punto (la scheda da cui nasce per prima), risolta nel condominio; null se non c'è più. */
    public function scheda(string $tipo, ?RuoloOggettoPunto $ruolo = null): ?Model
    {
        $oggetti = ($this->punto?->oggetti ?? collect())->filter(fn (OggettoDelPunto $o) => $o->oggetto_tipo === $tipo && ($ruolo === null || $o->ruolo === $ruolo))
            ->sortBy(fn (OggettoDelPunto $o) => [$o->ruolo === RuoloOggettoPunto::ORIGINE ? 0 : 1, $o->id]);
        foreach ($oggetti as $o) {
            $scheda = SchedaDelCondominio::cerca($this->assemblea->condominio()->firstOrFail(), $tipo, (int) $o->oggetto_id);
            if ($scheda !== null) {
                return $scheda;
            }
        }

        return null;
    }

    /** La scheda di quel tipo da cui il punto **nasce** (il ruolo «origine»): la sola che una catena cambia con la delibera. Un punto che ne parla non la tocca. */
    public function schedaDOrigine(string $tipo): ?Model
    {
        return $this->scheda($tipo, RuoloOggettoPunto::ORIGINE);
    }

    /**
     * La fine del giorno della delibera nel fuso dell'utente, come istante: una versione fermata dopo è «fermata dopo il giorno della delibera» (R6). Una
     * versione fermata alle 00:30 di Roma del 16/12 è del 16/12, anche se in UTC è ancora il 15/12.
     */
    public function fineDelGiorno(): ?Carbon
    {
        $giorno = $this->delibera->deliberata_il?->format('Y-m-d');

        return $giorno === null ? null : Carbon::parse($giorno . ' 23:59:59', (string) config('app.user_timezone', 'Europe/Rome'))
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * Fino a quando ciò che si ferma è «lo spedito» (decisione 622 (8)): la prima spedizione della convocazione; senza spedizioni nel programma (la riunione
     * tenuta su carta, convocata fuori), la fine del giorno della delibera.
     */
    public function limiteDellaSpedizione(): ?Carbon
    {
        return \App\Services\Assemblee\Convocazione\RendicontoPerLAssemblea::primaSpedizione($this->assemblea) ?? $this->fineDelGiorno();
    }

    /** L'origine del punto nel programma (la fattura, il piano, l'accordo, la gestione, il fondo, il rendiconto), per «Respinta» (R7). */
    public function origine(): ?Model
    {
        foreach (['fattura_passiva', 'piano_rate', 'accordo_rientro', 'gestione', 'cassa', 'dispensa_sei_mesi', 'rendiconto_versione'] as $tipo) {
            $scheda = $this->scheda($tipo, $tipo === 'rendiconto_versione' ? null : RuoloOggettoPunto::ORIGINE);
            if ($scheda !== null) {
                return $scheda;
            }
        }

        return null;
    }

    /** «15/12/2026». */
    public function giorno(): string
    {
        return (string) $this->delibera->deliberata_il?->format('d/m/Y');
    }

    /** «dall'assemblea del 15/12/2026, punto 2 (verbale n. 42)». */
    public function dallAssemblea(): string
    {
        // «(verbale n. 42)», o «(verbale da caricare)» per l'assemblea tenuta su carta finché il verbale firmato non c'è: la stessa frase delle schede.
        return 'dall\'assemblea del ' . $this->giorno() . ($this->punto !== null ? ', punto ' . $this->punto->numero : '')
            . DeliberaInParole::verbale($this->delibera);
    }

    /** L'assemblea tenuta su carta, o la riunione registrata a mano: il riepilogo si apre con gli esiti (decisione 622 (4)). */
    public function suCarta(): bool
    {
        return $this->assemblea->stato === StatoAssemblea::REGISTRATA_A_MANO;
    }

    /**
     * Il documento della copia sottoscritta del verbale del giorno della delibera (V3): il verbale registrato della sala, o quello caricato per la riunione
     * su carta. Null finché non c'è: il rendiconto approvato lo prende quando si carica.
     */
    public function documentoDelVerbale(): ?int
    {
        $numero = VerbaliDellAssemblea::numeroDelGiorno((int) $this->assemblea->id, $this->delibera->deliberata_il?->format('Y-m-d'));
        if ($numero !== null) {
            $id = VerbaleDellAssemblea::query()->where('assemblea_id', $this->assemblea->id)->where('stato', StatoVerbale::REGISTRATO->value)
                ->where('numero', $numero)->orderByDesc('id')->value('documento_id');

            return $id === null ? null : (int) $id;
        }

        return $this->assemblea->verbale_documento_id === null ? null : (int) $this->assemblea->verbale_documento_id;
    }
}
