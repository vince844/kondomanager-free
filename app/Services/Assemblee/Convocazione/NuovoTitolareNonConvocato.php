<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Convocazione;

use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Evento;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Gestionale\InboxService;
use Carbon\Carbon;

/**
 * Il nuovo titolare che non ha ricevuto la convocazione (§4.1 del progetto della tappa 2; decisione 492 (13); legge §12): «si convoca chi risulta
 * dall'anagrafe alla data dell'invio; vota chi è titolare alla data della riunione». Un passaggio registrato dopo l'invio per un'unità del perimetro, **con
 * la decorrenza non dopo il giorno della riunione** (`assemblee.data`), apre il compito «Verdi Sara (Int. 7) non ha ricevuto la convocazione del 15/12/2026,
 * e all'assemblea vota lei», con «Manda la convocazione»; con la decorrenza dopo il giorno della riunione vota ancora chi esce, e il compito non nasce. Se il
 * passaggio si annulla, il compito si chiude (§13, rilievo 26). Non c'è una pronuncia su questo caso: decide l'amministratore.
 */
final class NuovoTitolareNonConvocato
{
    public function __construct(private readonly ConvocatiDellAssemblea $convocati) {}

    /** Dopo un passaggio registrato: un compito per ogni assemblea già convocata in cui chi entra vota e non ha l'avviso. Rende quanti compiti sono nati. */
    public function dopoIlPassaggio(Subentro $subentro): int
    {
        $decorrenza = $subentro->decorrenza?->format('Y-m-d');
        if ($decorrenza === null) {
            return 0;
        }
        $nati = 0;
        $assemblee = Assemblea::query()->where('condominio_id', $subentro->condominio_id)->where('stato', StatoAssemblea::CONVOCATA->value)
            ->whereDate('data', '>=', $decorrenza)->get();
        foreach ($assemblee as $assemblea) {
            $fissati = ConvocatoDellAssemblea::query()->where('assemblea_id', $assemblea->id)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all();
            if ($fissati === []) {
                continue;
            }
            $giorno = $assemblea->data?->format('Y-m-d') ?? $decorrenza;
            $entranti = collect($this->convocati->alGiorno($assemblea, $giorno))
                ->filter(fn ($c) => collect($c['unita'])->contains(fn ($u) => (int) $u['immobile_id'] === (int) $subentro->immobile_id))
                ->reject(fn ($c) => in_array((int) $c['anagrafica_id'], $fissati, true));
            foreach ($entranti as $c) {
                if ($this->compitoAperto($assemblea, (int) $c['anagrafica_id']) !== null) {
                    continue;
                }
                $this->creaIlCompito($assemblea, $subentro, $c);
                $nati++;
            }
        }

        return $nati;
    }

    /** Dopo un passaggio annullato: i suoi compiti si chiudono. */
    public function dopoLAnnullamento(Subentro $subentro): void
    {
        $compiti = Evento::query()->where('meta->type', EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO->value)->where('meta->context->subentro_id', (int) $subentro->id)
            ->where('is_completed', false)->get();
        foreach ($compiti as $c) {
            ChiusuraDeiCompiti::chiudi($c, 'Chiuso: il passaggio è annullato.');
        }
        InboxService::clearAdminCache();
    }

    /**
     * Dopo una spedizione registrata: il compito di chi adesso ha il suo avviso spedito si chiude ({@see ElencoDeiConvocati::spedito()}: la lettera solo
     * stampata non basta).
     *
     * @param list<int> $spediti le anagrafiche con l'avviso spedito
     */
    public function dopoLaSpedizione(Assemblea $assemblea, array $spediti): void
    {
        $compiti = Evento::query()->where('meta->type', EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO->value)->where('meta->context->assemblea_id', (int) $assemblea->id)
            ->where('is_completed', false)->get();
        foreach ($compiti as $c) {
            if (in_array((int) ($c->meta['context']['anagrafica_id'] ?? 0), $spediti, true)) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: la convocazione è partita anche per lei.');
            }
        }
    }

    /**
     * La nota arancio del passo «Convocazione»: una per compito aperto.
     *
     * @return list<array{titolo: string, frase: string, anagrafica_id: ?int}>
     */
    public function venduti(Assemblea $assemblea): array
    {
        return Evento::query()->where('meta->type', EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO->value)->where('meta->context->assemblea_id', (int) $assemblea->id)
            ->where('is_completed', false)->orderBy('id')->get()
            ->map(fn (Evento $e) => ['titolo' => (string) ($e->meta['nota_titolo'] ?? $e->title), 'frase' => (string) ($e->meta['nota_frase'] ?? $e->description),
                'anagrafica_id' => isset($e->meta['context']['anagrafica_id']) ? (int) $e->meta['context']['anagrafica_id'] : null])->values()->all();
    }

    private function compitoAperto(Assemblea $assemblea, int $anagraficaId): ?Evento
    {
        return Evento::query()->where('meta->type', EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO->value)->where('meta->context->assemblea_id', (int) $assemblea->id)
            ->where('meta->context->anagrafica_id', $anagraficaId)->where('is_completed', false)->first();
    }

    /** @param array<string, mixed> $c il nuovo convocato */
    private function creaIlCompito(Assemblea $assemblea, Subentro $subentro, array $c): Evento
    {
        $unita = Immobile::query()->find($subentro->immobile_id);
        $nomeUnita = $unita !== null ? ConvocatiDellAssemblea::nomeDellUnita($unita) : 'l\'unità';
        $uscente = $subentro->anagrafica_uscente_id === null ? null : Anagrafica::query()->whereKey($subentro->anagrafica_uscente_id)->value('nome');
        $giorno = $assemblea->data?->format('d/m/Y') ?? '';
        $nome = (string) $c['nome'];
        $titolo = $nome . ' (' . $nomeUnita . ') non ha ricevuto la convocazione del ' . $giorno . ', e all\'assemblea vota ' . $nome;
        $cosa = match ((string) $subentro->tipo_passaggio) {
            'vendita' => 'è stato venduto',
            'successione' => 'è passato agli eredi',
            default => 'ha cambiato titolare',
        };
        $notaTitolo = 'Dopo l\'invio, l\'' . $nomeUnita . ' ' . $cosa . '.';
        $notaFrase = 'Il passaggio' . ($uscente !== null ? ' da ' . $uscente : '') . ' a ' . $nome . ' è registrato con decorrenza '
            . $subentro->decorrenza->format('d/m') . '. ' . $nome . ' non ha ricevuto la convocazione, e all\'assemblea vota ' . $nome
            . '. Un compito nell\'Action inbox te lo ricorda; decidi tu come procedere.';

        return InboxService::createTask(EventoTipo::NUOVO_TITOLARE_NON_CONVOCATO, $titolo, $notaFrase,
            Carbon::parse(now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('Y-m-d') . ' ' . CompitiDellaConvocazione::ORA . ':00',
                (string) config('app.timezone')),
            $subentro->utente_id === null ? null : (int) $subentro->utente_id, (int) $assemblea->condominio_id,
            ['assemblea_id' => (int) $assemblea->id, 'subentro_id' => (int) $subentro->id, 'anagrafica_id' => (int) $c['anagrafica_id']],
            CompitiDellaConvocazione::indirizzo($assemblea),
            ['assemblea_id' => (int) $assemblea->id, 'riga' => $notaFrase, 'nota_titolo' => $notaTitolo, 'nota_frase' => $notaFrase]);
    }
}
