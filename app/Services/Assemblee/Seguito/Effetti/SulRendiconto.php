<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito\Effetti;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Gestionale\RendicontoVersione;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\PropostaDelPunto;
use App\Services\Assemblee\Seguito\ContestoDellaDelibera;
use App\Services\Assemblee\Seguito\RigaDelSeguito;
use App\Services\Gestionale\VersioniDelRendiconto;
use Illuminate\Validation\ValidationException;

/**
 * Catena 1, il rendiconto con i suoi due esiti. Il punto «Approvazione del rendiconto 2025 e dello stato di riparto» ha la versione fermata fra i documenti;
 * in sala due votazioni, due delibere (il rendiconto e lo stato di riparto). La riga sta sulla delibera del rendiconto e applica **«Registra l'approvazione»
 * per la sua strada** ({@see VersioniDelRendiconto::approva()}): il giorno della riunione, il numero del verbale di quel giorno, «riparto approvato» dalla
 * seconda delibera, il documento del verbale sottoscritto (V3) senza un secondo file, e i collegamenti `approvazione` e `riparto`.
 *
 * Gli altri esiti: con il riparto respinto la versione è approvata con «riparto: no» e il conguaglio resta una proposta; con il rendiconto respinto la versione
 * resta presentata (e la riga dei 180 giorni resta: la legge dallo stato) e c'è la risposta da dare (R7); con modifiche la riga aspetta una versione fermata
 * dopo il giorno della delibera (R6); rinviato, il punto torna proposto (R8). Un solo voto sul punto (la riunione su carta) vale per i due.
 */
final class SulRendiconto implements Effetto
{
    public function righe(ContestoDellaDelibera $c): array
    {
        if ($c->materia() !== MateriaPunto::RENDICONTO || $c->delibera->oggetto === 'riparto') {
            return $c->materia() === MateriaPunto::RENDICONTO ? $this->righeDelRiparto($c) : [];
        }
        $anno = PropostaDelPunto::anno($c->punto?->esercizio_id);
        $versione = $this->versione($c);
        if ($c->esito() === EsitoDelibera::RESPINTA || $c->esito() === EsitoDelibera::RINVIATA) {
            return [];
        }
        if (! $c->approvata()) {
            return [];
        }
        if ($versione === null) {
            return [RigaDelSeguito::senzaSpunta($c, 'fuori', 'rendiconto', 'Rendiconto ' . $anno . ': nessuna versione fermata',
                'Il punto non ha una versione del rendiconto: «Registra l\'approvazione» resta nella pagina del rendiconto.',
                'Il punto non ha una versione fermata del rendiconto.')];
        }
        $riparto = $this->ripartoApprovato($c);
        $titolo = 'Rendiconto ' . $anno . ': versione ' . $versione->numero . ' approvata, e lo stato di riparto ' . ($riparto ? 'approvato' : 'respinto');
        $giaDiQuesta = CollegamentiDelleDelibere::delibera($versione, RuoloCollegamento::APPROVAZIONE->value)?->id === $c->delibera->id;
        if ($versione->stato !== RendicontoVersione::PRESENTATA && ! $giaDiQuesta) {
            $perche = $versione->stato === RendicontoVersione::APPROVATA ? 'La versione ' . $versione->numero . ' è già approvata'
                . ($versione->data_delibera !== null ? ' dal ' . $versione->data_delibera->format('d/m/Y') : '') . '.'
                : 'La versione ' . $versione->numero . ' non è presentata: si approva una versione presentata.';

            return [RigaDelSeguito::senzaSpunta($c, 'fuori', 'rendiconto', $titolo, $perche, $perche, $versione, false, [], RuoloCollegamento::APPROVAZIONE->value)];
        }
        if ($c->conModifiche() && ! $giaDiQuesta && $this->fermataDopo($c, $versione) === false) {
            return [
                RigaDelSeguito::senzaSpunta($c, 'fuori', 'rendiconto', $titolo, 'L\'assemblea ha cambiato il rendiconto: si approva la versione fermata dopo il '
                    . $c->giorno() . '.', 'Correggi e ferma una versione nuova: si approva quella.', $versione, false, ['aspetta' => true],
                    RuoloCollegamento::APPROVAZIONE->value),
                RigaDelSeguito::cosa($c, TipoCosaDaFare::RIFAI_VERSIONE, 'rendiconto', 'Rendiconto ' . $anno . ': correggi e ferma una versione nuova',
                    'Approvato con modifiche: la versione nuova si approva qui, con «Applica».', 'Correggi il rendiconto ' . $anno . ' e ferma una versione nuova'),
            ];
        }
        $frase = $c->suCarta() && $c->documentoDelVerbale() === null ? 'Si applica adesso. Il verbale si aggancia alla versione quando lo carichi.'
            : 'Si applica adesso. Si chiude il compito «Rendiconto da portare in assemblea».';
        if (! $riparto) {
            $frase .= ' Con lo stato di riparto respinto il conguaglio resta una proposta.';
        }

        return [RigaDelSeguito::applica($c, $versione, RuoloCollegamento::APPROVAZIONE->value, 'rendiconto', $titolo, $frase)];
    }

    public function applica(ContestoDellaDelibera $c, array $riga, User $utente): string
    {
        /** @var ?RendicontoVersione $versione */
        $versione = RendicontoVersione::query()->whereKey((int) ($riga['scheda']['id'] ?? 0))->where('condominio_id', $c->delibera->condominio_id)->first();
        if ($versione === null) {
            throw ValidationException::withMessages(['riga' => 'La versione del rendiconto non c\'è più.']);
        }
        if ($versione->stato !== RendicontoVersione::PRESENTATA) {
            throw ValidationException::withMessages(['riga' => 'La versione ' . $versione->numero . ' non è presentata: si approva una versione presentata.']);
        }
        $riparto = $this->ripartoApprovato($c);
        app(VersioniDelRendiconto::class)->approva($versione, ['data_delibera' => (string) $c->delibera->deliberata_il?->format('Y-m-d'),
            'numero_verbale' => DeliberaInParole::numeroDelVerbale($c->delibera), 'riparto_approvato' => $riparto, 'nota_approvazione' => null], null, $utente,
            $c->documentoDelVerbale());
        $versione->refresh();
        CollegamentiDelleDelibere::collega($versione, RuoloCollegamento::APPROVAZIONE->value, $c->delibera, null, $utente);
        $delRiparto = $this->deliberaDelRiparto($c);
        if ($delRiparto !== null && $delRiparto->eApprovata()) {
            CollegamentiDelleDelibere::collega($versione, RuoloCollegamento::RIPARTO->value, $delRiparto, null, $utente);
        }

        return 'Versione ' . $versione->numero . ' approvata il ' . $c->giorno() . ($riparto ? ', con lo stato di riparto.' : ', con lo stato di riparto respinto.');
    }

    /**
     * La riunione su carta: il verbale caricato dopo si aggancia alle versioni approvate dalle sue delibere che non hanno ancora il documento (decisione 622
     * (4)). Lo chiama il caricamento del verbale; la scrittura è del modulo del rendiconto ({@see VersioniDelRendiconto::agganciaIlVerbale()}), che non tocca
     * una versione che ne ha già uno.
     */
    public static function agganciaIlVerbale(Assemblea $assemblea): int
    {
        if ($assemblea->verbale_documento_id === null) {
            return 0;
        }
        $delibere = Delibera::query()->where('assemblea_id', $assemblea->id)->pluck('id');
        $versioni = \App\Models\Assemblee\CollegamentoAllaDelibera::query()->whereIn('delibera_id', $delibere)->where('oggetto_tipo', 'rendiconto_versione')
            ->where('ruolo', RuoloCollegamento::APPROVAZIONE->value)->pluck('oggetto_id')->map(fn ($id) => (int) $id)->all();

        return VersioniDelRendiconto::agganciaIlVerbale($versioni, (int) $assemblea->verbale_documento_id);
    }

    /** La delibera dello stato di riparto: una riga che dice dove si applica. @return list<array<string, mixed>> */
    private function righeDelRiparto(ContestoDellaDelibera $c): array
    {
        $delRendiconto = collect($c->delloStessoPunto)->first(fn (Delibera $d) => $d->oggetto !== 'riparto');
        if ($delRendiconto === null || ! $c->approvata()) {
            return [];
        }
        $frase = $delRendiconto->eApprovata() ? 'Si applica con la riga del rendiconto: la versione approvata dice «riparto approvato».'
            : 'Il rendiconto è respinto: lo stato di riparto non si applica.';

        return [RigaDelSeguito::senzaSpunta($c, 'nota', 'rendiconto', 'Lo stato di riparto', $frase)];
    }

    /**
     * La versione del punto: il documento allegato al punto; senza, l'ultima presentata dell'esercizio del punto **fermata entro il giorno della delibera**
     * (quella che l'assemblea poteva avere davanti: una fermata dopo la riunione non si approva al posto suo). Con modifiche (R6), la versione fermata dopo.
     */
    private function versione(ContestoDellaDelibera $c): ?RendicontoVersione
    {
        $allegata = $c->scheda('rendiconto_versione');
        if ($allegata instanceof RendicontoVersione) {
            $nuova = $c->conModifiche() ? $this->fermataDopo($c, $allegata) : null;

            return $nuova instanceof RendicontoVersione ? $nuova : $allegata;
        }
        if ($c->punto?->esercizio_id === null) {
            return null;
        }
        $fine = $c->fineDelGiorno();
        $prima = RendicontoVersione::query()->where('esercizio_id', $c->punto->esercizio_id)->where('condominio_id', $c->delibera->condominio_id)
            ->where('stato', RendicontoVersione::PRESENTATA)->when($fine !== null, fn ($q) => $q->where('created_at', '<=', $fine))->orderByDesc('numero')->first();
        if ($prima !== null && $c->conModifiche()) {
            $nuova = $this->fermataDopo($c, $prima);

            return $nuova instanceof RendicontoVersione ? $nuova : $prima;
        }

        return $prima;
    }

    /** R6: la versione presentata dello stesso esercizio fermata dopo il giorno della delibera, nel fuso dell'utente; `false` se non c'è. */
    private function fermataDopo(ContestoDellaDelibera $c, RendicontoVersione $versione): RendicontoVersione|false
    {
        $fine = $c->fineDelGiorno();
        $nuova = RendicontoVersione::query()->where('esercizio_id', $versione->esercizio_id)->where('stato', RendicontoVersione::PRESENTATA)
            ->when($fine !== null, fn ($q) => $q->where('created_at', '>', $fine))->orderByDesc('numero')->first();

        return $nuova ?? false;
    }

    private function deliberaDelRiparto(ContestoDellaDelibera $c): ?Delibera
    {
        return collect($c->delloStessoPunto)->first(fn (Delibera $d) => $d->oggetto === 'riparto');
    }

    /** «Riparto approvato»: l'esito della seconda delibera; un solo voto sul punto vale per i due. */
    private function ripartoApprovato(ContestoDellaDelibera $c): bool
    {
        $riparto = $this->deliberaDelRiparto($c);

        return $riparto === null ? $c->approvata() : $riparto->eApprovata();
    }
}
