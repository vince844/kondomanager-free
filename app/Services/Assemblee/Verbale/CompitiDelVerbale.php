<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Verbale;

use App\Enums\Assemblee\EsitoDelGesto;
use App\Enums\Assemblee\EsitoSeduta;
use App\Enums\Assemblee\StatoAssemblea;
use App\Enums\Assemblee\StatoVerbale;
use App\Enums\EventoTipo;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Assemblee\VersioneDelVerbale;
use App\Models\Evento;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione;
use App\Services\Gestionale\InboxService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * I compiti del verbale e della sala nell'Action inbox (V13; decisioni 595 (1) e 604 (3)), sul modello di quelli della convocazione: l'assemblea in
 * `meta.context.assemblea_id`, il giorno alle 9:00 di Roma, chiusi da soli con la frase quando il fatto c'è. Ciò che il programma crea e chiude da solo non va
 * nel registro delle modifiche (decisione 547 (2)).
 *
 * - **«Registrare il verbale di Via Roma 12 entro il 22/12»**: dalla chiusura della riunione (anche sospesa, o aperta e rinviata dalla sala), sette giorni dopo,
 *   finché il verbale di quella riunione non è registrato («Chiuso: registrato il n. 42»).
 * - **«Trasmettere il verbale n. 42 entro il 29/12»**: dalla registrazione, sette giorni dopo, finché ogni destinatario non ha il suo invio spedito.
 * - **«Riapri la sala giovedì 17/12 alle 18:30»** (decisione 595 (1)): dalla sospensione, il giorno della prosecuzione, con il foglio della sala da ristampare;
 *   si chiude quando la sala si riapre.
 * - **«L'assemblea è ancora in corso»** (604 (3)): una seduta aperta il giorno dopo il suo giorno; si chiude quando la sala la chiude, la sospende o la rinvia.
 * - **«Due versioni da scegliere»** (604 (3)): un gesto della sala o un testo del verbale in conflitto, finché non si sceglie.
 * - **«Da decidere in sala»** (604 (3)): una delega o l'apertura rimaste «da decidere», finché il presidente non decide.
 */
final class CompitiDelVerbale
{
    public const ORA = '09:00';

    /** I giorni dopo la chiusura per registrare, e dopo la registrazione per trasmettere (§0.4 (1) del progetto: nessun termine di legge trovato). */
    public const GIORNI = 7;

    public function sincronizza(Assemblea $assemblea): void
    {
        $assemblea->refresh();
        $this->registrare($assemblea);
        $this->trasmettere($assemblea);
        $this->riapri($assemblea);
        $this->inCorso($assemblea);
        $this->dueVersioni($assemblea);
        $this->daDecidere($assemblea);
        InboxService::clearAdminCache();
    }

    private function registrare(Assemblea $a): void
    {
        $aperti = $this->aperti($a, EventoTipo::REGISTRARE_VERBALE)->keyBy(fn (Evento $e) => (int) ($e->meta['context']['seduta_id'] ?? 0));
        $restano = [];
        foreach (VerbaliDellAssemblea::riunioni($a) as $s) {
            if ($s->esito === null || $s->chiusa_il === null) {
                continue;
            }
            $registrato = VerbaliDellAssemblea::registratoDellaSeduta((int) $s->id);
            if ($registrato !== null) {
                ChiusuraDeiCompiti::chiudi($aperti->get((int) $s->id), 'Chiuso: registrato il n. ' . $registrato->numero . '.');
                continue;
            }
            // Un'assemblea annullata e riconvocata: la riunione aperta e rinviata dalla sala ha il suo verbale; quella mai aperta no.
            $restano[(int) $s->id] = true;
            if ($aperti->has((int) $s->id)) {
                continue;
            }
            $entro = CarbonImmutable::parse(ParoleDelVerbale::giornoDi($s->chiusa_il))->addDays(self::GIORNI);
            $nome = (string) $a->condominio?->nome;
            InboxService::createTask(EventoTipo::REGISTRARE_VERBALE, 'Registrare il verbale di ' . $nome . ' ' . ConsegneDellaConvocazione::entro($entro),
                'Assemblea ' . mb_strtolower($a->nome()) . ': riunione del ' . $s->fissata_il->format('d/m/Y') . ', da generare, firmare e registrare.',
                self::alleNove(CarbonImmutable::parse(ParoleDelVerbale::giornoDi($s->chiusa_il))), $a->updated_by ?? $a->created_by, (int) $a->condominio_id,
                ['assemblea_id' => (int) $a->id, 'seduta_id' => (int) $s->id], self::indirizzo($a), ['assemblea_id' => (int) $a->id, 'scadenza' => $entro->format('Y-m-d')]);
        }
        foreach ($aperti as $id => $c) {
            if (! isset($restano[$id]) && ! $c->is_completed) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: il verbale non è più da registrare.');
            }
        }
    }

    private function trasmettere(Assemblea $a): void
    {
        $aperti = $this->aperti($a, EventoTipo::TRASMETTERE_VERBALE)->keyBy(fn (Evento $e) => (int) ($e->meta['context']['verbale_id'] ?? 0));
        $restano = [];
        $destinatari = app(DestinatariDelVerbale::class);
        foreach (VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->where('stato', StatoVerbale::REGISTRATO->value)->get() as $v) {
            $d = $destinatari->per($v);
            $daSpedire = $d['quanti'] - $d['spediti'];
            if ($daSpedire === 0) {
                ChiusuraDeiCompiti::chiudi($aperti->get((int) $v->id), 'Chiuso: il verbale n. ' . $v->numero . ' è partito per tutti.');
                continue;
            }
            $restano[(int) $v->id] = true;
            $riga = 'Assemblea ' . mb_strtolower($a->nome()) . ': ' . ($daSpedire === 1 ? '1 da spedire' : $daSpedire . ' da spedire') . ' su ' . $d['quanti'] . '.';
            $compito = $aperti->get((int) $v->id);
            if ($compito !== null) {
                $compito->update(['description' => $riga, 'meta' => array_merge((array) $compito->meta, ['riga' => $riga])]);
                continue;
            }
            $entro = CarbonImmutable::parse(ParoleDelVerbale::giornoDi($v->registrato_il))->addDays(self::GIORNI);
            InboxService::createTask(EventoTipo::TRASMETTERE_VERBALE, 'Trasmettere il verbale n. ' . $v->numero . ' ' . ConsegneDellaConvocazione::entro($entro), $riga,
                self::alleNove(CarbonImmutable::parse(ParoleDelVerbale::giornoDi($v->registrato_il))), $v->registrato_da, (int) $a->condominio_id,
                ['assemblea_id' => (int) $a->id, 'verbale_id' => (int) $v->id], self::indirizzo($a), ['assemblea_id' => (int) $a->id, 'riga' => $riga,
                    'scadenza' => $entro->format('Y-m-d')]);
        }
        foreach ($aperti as $id => $c) {
            if (! isset($restano[$id])) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: il verbale non è più da trasmettere.');
            }
        }
    }

    /** «Riapri la sala giovedì 17/12 alle 18:30»: dalla sospensione al giorno della prosecuzione; si chiude quando la sala si riapre. */
    private function riapri(Assemblea $a): void
    {
        $aperti = $this->aperti($a, EventoTipo::RIAPRIRE_SALA)->keyBy(fn (Evento $e) => (int) ($e->meta['context']['seduta_id'] ?? 0));
        $restano = [];
        $sedute = $a->sedute()->get();
        foreach ($sedute->filter(fn (SedutaDellAssemblea $s) => $s->esito === EsitoSeduta::SOSPESA) as $sospesa) {
            $prosegue = $sedute->first(fn (SedutaDellAssemblea $x) => $x->convocazione === $sospesa->convocazione && (int) $x->numero > (int) $sospesa->numero);
            if ($prosegue === null || $prosegue->aperta_il !== null || $prosegue->esito !== null || $a->stato !== StatoAssemblea::IN_CORSO) {
                continue;
            }
            $restano[(int) $prosegue->id] = true;
            if ($aperti->has((int) $prosegue->id)) {
                continue;
            }
            $quando = \App\Services\Assemblee\PassiDellAssemblea::giornoDellaSeduta($prosegue->fissata_il);
            InboxService::createTask(EventoTipo::RIAPRIRE_SALA, 'Riapri la sala ' . $quando, 'Assemblea ' . mb_strtolower($a->nome()) . ': restano '
                . $this->puntiCheRestano($a) . '. Ristampa il foglio della sala con i soli punti che restano.',
                self::alleNove(CarbonImmutable::parse($prosegue->fissata_il->format('Y-m-d'))), $a->updated_by ?? $a->created_by, (int) $a->condominio_id,
                ['assemblea_id' => (int) $a->id, 'seduta_id' => (int) $prosegue->id], self::indirizzo($a, 'sala'), ['assemblea_id' => (int) $a->id]);
        }
        foreach ($aperti as $id => $c) {
            if (! isset($restano[$id])) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: la sala si è riaperta.');
            }
        }
    }

    /** «L'assemblea è ancora in corso»: una seduta aperta, dal giorno dopo il suo giorno. */
    private function inCorso(Assemblea $a): void
    {
        $aperti = $this->aperti($a, EventoTipo::SALA_IN_CORSO)->keyBy(fn (Evento $e) => (int) ($e->meta['context']['seduta_id'] ?? 0));
        $aperta = \App\Services\Assemblee\Sala\StatiDellaSala::sedutaAperta($a);
        foreach ($aperti as $id => $c) {
            if ($aperta === null || (int) $aperta->id !== $id) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: la sala ha chiuso, sospeso o rinviato la seduta.');
            }
        }
        if ($aperta === null || $aperti->has((int) $aperta->id)) {
            return;
        }
        $domani = CarbonImmutable::parse($aperta->fissata_il->format('Y-m-d'))->addDay();
        InboxService::createTask(EventoTipo::SALA_IN_CORSO, 'L\'assemblea di ' . $a->condominio?->nome . ' del ' . $aperta->fissata_il->format('d/m') . ' è ancora in corso',
            'La seduta è aperta dal ' . $aperta->fissata_il->format('d/m/Y') . ': chiudila, sospendila o rinviala dalla sala.', self::alleNove($domani),
            $a->updated_by ?? $a->created_by, (int) $a->condominio_id, ['assemblea_id' => (int) $a->id, 'seduta_id' => (int) $aperta->id], self::indirizzo($a, 'sala'),
            ['assemblea_id' => (int) $a->id]);
    }

    /**
     * «Due versioni da scegliere»: i gesti della sala e i testi del verbale in conflitto, ancora da scegliere, e i testi del verbale arrivati tardi (la
     * discussione dopo la generazione, il testo dopo la registrazione). Un compito per assemblea; la riga dice che cosa c'è, ognuna con il suo nome.
     */
    private function dueVersioni(Assemblea $a): void
    {
        $sala = GestoDellaSala::query()->where('assemblea_id', $a->id)->where('esito', EsitoDelGesto::IN_CONFLITTO->value)->whereNull('scelto_il')
            ->where('tipo', '!=', GestiDelVerbale::NEL_LIBRO)->count();
        $perAttesa = VersioneDelVerbale::query()->whereIn('verbale_id', VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->select('id'))
            ->where('in_attesa', true)->whereNull('scelta_il')->get()->countBy('attesa');
        $verbale = (int) $perAttesa->sum();
        $quante = $sala + $verbale;
        $aperti = $this->aperti($a, EventoTipo::DUE_VERSIONI);
        if ($quante === 0) {
            foreach ($aperti as $c) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: le due versioni sono scelte.');
            }

            return;
        }
        $due = (int) ($perAttesa[VersioneDelVerbale::DUE_VERSIONI] ?? 0);
        $dopoLaGenerazione = (int) ($perAttesa[VersioneDelVerbale::DOPO_LA_GENERAZIONE] ?? 0);
        $dopoLaRegistrazione = (int) ($perAttesa[VersioneDelVerbale::DOPO_LA_REGISTRAZIONE] ?? 0);
        $pezzi = array_values(array_filter([
            $sala + $due > 0 ? (($sala + $due) === 1 ? '1 scelta' : ($sala + $due) . ' scelte') . ' fra due versioni' . ($due > 0 ? ', ' . $due . ' nel verbale' : '') : null,
            $dopoLaGenerazione > 0 ? ($dopoLaGenerazione === 1 ? '1 discussione arrivata' : $dopoLaGenerazione . ' discussioni arrivate') . ' dopo la generazione, da '
                . 'mettere nel verbale' : null,
            $dopoLaRegistrazione > 0 ? ($dopoLaRegistrazione === 1 ? '1 testo arrivato' : $dopoLaRegistrazione . ' testi arrivati') . ' dopo la registrazione, da vedere' : null,
        ]));
        $riga = ucfirst(implode('; ', $pezzi)) . '.';
        if ($aperti->isNotEmpty()) {
            $aperti->first()->update(['description' => $riga]);

            return;
        }
        InboxService::createTask(EventoTipo::DUE_VERSIONI, 'Due versioni da scegliere: assemblea di ' . $a->condominio?->nome . ' del ' . $a->data?->format('d/m'), $riga,
            \App\Helpers\DateHelper::adessoComeSiLegge(), $a->updated_by ?? $a->created_by, (int) $a->condominio_id, ['assemblea_id' => (int) $a->id],
            self::indirizzo($a, $sala > 0 ? 'sala' : 'verbale'), ['assemblea_id' => (int) $a->id]);
    }

    /** «Da decidere in sala»: le deleghe e l'apertura rimaste «da decidere». */
    private function daDecidere(Assemblea $a): void
    {
        $deleghe = DelegaDellAssemblea::query()->where('assemblea_id', $a->id)->where('stato', 'valida')->get()
            ->filter(fn (DelegaDellAssemblea $d) => (bool) ($d->dati['da_decidere'] ?? false))->count();
        $apertura = $a->sedute()->get()->filter(fn (SedutaDellAssemblea $s) => ($s->apertura['scelta'] ?? null) === \App\Services\Assemblee\Sala\StatiDellaSala::DA_DECIDERE
            && $s->esito === null)->count();
        $aperti = $this->aperti($a, EventoTipo::DA_DECIDERE_IN_SALA);
        if ($deleghe + $apertura === 0 || ! in_array($a->stato, [StatoAssemblea::CONVOCATA, StatoAssemblea::IN_CORSO], true)) {
            foreach ($aperti as $c) {
                ChiusuraDeiCompiti::chiudi($c, 'Chiuso: il presidente ha deciso.');
            }

            return;
        }
        $pezzi = array_values(array_filter([$deleghe > 0 ? ($deleghe === 1 ? '1 delega' : $deleghe . ' deleghe') : null,
            $apertura > 0 ? 'l\'apertura' : null]));
        $riga = 'Da decidere: ' . implode(' e ', $pezzi) . '. Decide il presidente, in sala.';
        if ($aperti->isNotEmpty()) {
            $aperti->first()->update(['description' => $riga]);

            return;
        }
        InboxService::createTask(EventoTipo::DA_DECIDERE_IN_SALA, 'Da decidere in sala: assemblea di ' . $a->condominio?->nome, $riga, \App\Helpers\DateHelper::adessoComeSiLegge(),
            $a->updated_by ?? $a->created_by, (int) $a->condominio_id, ['assemblea_id' => (int) $a->id], self::indirizzo($a, 'sala'), ['assemblea_id' => (int) $a->id]);
    }

    /** «i punti 6, 7 e 8»: i punti senza un esito. */
    private function puntiCheRestano(Assemblea $a): string
    {
        $numeri = \App\Services\Assemblee\Sala\StatiDellaSala::puntiSenzaEsito($a)->pluck('numero')->map(fn ($n) => (int) $n)->all();

        return $numeri === [] ? 'nessun punto' : ParoleDelVerbale::punti($numeri);
    }

    /** @return Collection<int, Evento> */
    public function aperti(Assemblea $assemblea, EventoTipo $tipo): Collection
    {
        return Evento::query()->where('meta->type', $tipo->value)->where('meta->context->assemblea_id', (int) $assemblea->id)
            ->where('is_completed', false)->orderBy('id')->get();
    }

    private static function alleNove(CarbonImmutable $giorno): Carbon
    {
        // Scritto come si legge, come i compiti della convocazione (`GiornoDelCompitoNelGiornoDiRomaTest`).
        return Carbon::parse($giorno->format('Y-m-d') . ' ' . self::ORA . ':00', (string) config('app.timezone'));
    }

    /** La pagina del verbale (o della sala), quando la rotta c'è. */
    public static function indirizzo(Assemblea $assemblea, string $dove = 'verbale'): ?string
    {
        $rotta = $dove === 'sala' ? 'admin.gestionale.assemblee.sala' : 'admin.gestionale.assemblee.verbale.pagina';

        return Route::has($rotta) ? route($rotta, ['condominio' => $assemblea->condominio_id, 'assemblea' => $assemblea->id]) : null;
    }
}
