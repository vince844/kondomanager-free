<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\ModalitaAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Models\RegistroModifica;
use App\Models\Tabella;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
use App\Services\Assemblee\UnitaDelPunto;
use App\Services\RegistroModifiche\Schede\TabellaNelRegistro;
use Illuminate\Support\Facades\DB;

/**
 * Il controllo dell'apertura (R4; art. 1136 c. 6 c.c.: «L'assemblea non può deliberare, se non consta che tutti gli aventi diritto sono stati regolarmente
 * convocati»). Chi ha diritto alla data della seduta ({@see ConvocatiDellAssemblea::alGiorno()}, le stesse regole dell'avviso: l'usufruttuario e il nudo
 * proprietario, il conduttore per i suoi punti) e:
 *
 * - **non risulta fra i convocati** con un avviso spedito ({@see ElencoDeiConvocati::spedito()}), anche il nuovo titolare entrato dopo l'invio;
 * - l'ha ricevuto **fuori tempo** ({@see TerminiDellaConvocazione::inTempo()});
 * - l'ha **rifiutato**: vale come ricevuto, e il presidente lo deve sapere.
 *
 * Queste sono **le cose da scegliere**: decide il presidente, «Rinvia l'assemblea» o «Apri, e il verbale lo scrive» con il motivo. **Le cose da guardare**,
 * che non chiedono la scelta: la videoconferenza senza il consenso registrato (art. 66 c. 6 disp. att.), la PEC non comunicata dalla persona, le deleghe che
 * con la fotografia della seduta superano il quinto, il segno «di un gruppo» di una tabella dei punti cambiato dopo l'invio dell'avviso
 * ({@see segniCambiati()}, Coda 734).
 */
final class ControlloDellApertura
{
    public const FRASE_LEGGE = 'L\'assemblea non può deliberare se non consta che tutti gli aventi diritto sono stati regolarmente convocati (art. 1136 c. 6 c.c.): '
        . 'scegli «Apri, e il verbale lo scrive» con il motivo, o «Rinvia l\'assemblea».';

    public function __construct(
        private readonly ConvocatiDellAssemblea $convocati,
        private readonly ElencoDeiConvocati $elenco,
        private readonly TerminiDellaConvocazione $termini,
        private readonly FotografiaDellaSeduta $fotografie,
        private readonly DelegheDellAssemblea $deleghe,
    ) {}

    /**
     * @param ?array<string, mixed> $fotografia quella della seduta, se già letta
     * @return array{problemi: list<array{tipo: string, anagrafica_id: int, nome: string, frase: string}>, da_guardare: list<array{tipo: string, frase: string}>,
     *     serve_la_scelta: bool, frase: ?string}
     */
    public function controlla(Assemblea $assemblea, SedutaDellAssemblea $seduta, ?array $fotografia = null): array
    {
        $fotografia ??= $this->fotografie->leggi($assemblea, $seduta);
        $giorno = $seduta->fissata_il->format('Y-m-d');
        $voci = collect($this->elenco->per($assemblea))->keyBy(fn ($v) => (int) $v['anagrafica_id']);
        $ultimo = $this->termini->ultimoGiorno($assemblea);
        $problemi = [];
        $daGuardare = [];

        foreach ($this->convocati->alGiorno($assemblea, $giorno) as $c) {
            $id = (int) $c['anagrafica_id'];
            $chi = $c['nome'] . ' (' . $c['unita_parole'] . ')';
            $voce = $voci->get($id);
            if ($voce === null || ! ElencoDeiConvocati::spedito($voce)) {
                $dal = $this->titolareDal($assemblea, $c);
                $problemi[] = ['tipo' => 'non_convocato', 'anagrafica_id' => $id, 'nome' => $c['nome'],
                    'frase' => $chi . ' non risulta fra i convocati' . ($dal !== null ? ': è titolare dal ' . $dal . '.' : '.')];
                continue;
            }
            $invio = $voce['invio'];
            $ricevuta = $invio?->ricevuta_il === null ? null : substr((string) ($invio->ricevuta_il instanceof \DateTimeInterface ? $invio->ricevuta_il->format('Y-m-d')
                : $invio->ricevuta_il), 0, 10);
            if ($this->termini->inTempo($assemblea, $ricevuta) === false) {
                $problemi[] = ['tipo' => 'fuori_tempo', 'anagrafica_id' => $id, 'nome' => $c['nome'], 'frase' => $chi . ' ha ricevuto l\'avviso '
                    . self::ilGiorno($ricevuta) . ', dopo il ' . $ultimo?->format('d/m/Y') . '.'];
            }
            if ($invio?->esito === RegistroInvio::ESITO_RIFIUTATA) {
                $problemi[] = ['tipo' => 'rifiutato', 'anagrafica_id' => $id, 'nome' => $c['nome'],
                    'frase' => $chi . ' ha rifiutato l\'avviso: vale come ricevuto, e il presidente lo deve sapere.'];
            }
            if (($voce['mezzo']['mezzo'] ?? null) === 'pec' && ($c['pec_comunicata_il'] ?? null) === null) {
                $daGuardare[] = ['tipo' => 'pec_non_comunicata', 'frase' => $c['nome'] . ': l\'avviso è partito per PEC, e la PEC non è comunicata dalla persona.'];
            }
        }

        if (in_array($assemblea->modalita, [ModalitaAssemblea::VIDEOCONFERENZA, ModalitaAssemblea::MISTA], true) && $assemblea->consenso_videoconferenza_il === null) {
            array_unshift($daGuardare, ['tipo' => 'consenso_videoconferenza', 'frase' => 'L\'assemblea è ' . ($assemblea->modalita === ModalitaAssemblea::MISTA
                ? 'anche in videoconferenza' : 'in videoconferenza') . ', e il consenso della maggioranza dei condòmini non è registrato (art. 66 c. 6 disp. att.).']);
        }
        foreach ($this->deleghe->allApertura($assemblea, $fotografia, now(), true) as $frase) {
            $daGuardare[] = ['tipo' => 'delega_oltre_il_quinto', 'frase' => $frase];
        }
        foreach ((array) $fotografia['avvisi'] as $avviso) {
            $daGuardare[] = ['tipo' => 'fotografia', 'frase' => (string) $avviso];
        }
        foreach ($this->segniCambiati($assemblea) as $frase) {
            $daGuardare[] = ['tipo' => 'segno_cambiato', 'frase' => $frase];
        }
        // Prima i problemi nell'ordine dei tipi, poi per nome: la frase della scelta li elenca così.
        $ordine = ['non_convocato' => 0, 'fuori_tempo' => 1, 'rifiutato' => 2];
        usort($problemi, fn ($x, $y) => [$ordine[$x['tipo']], mb_strtolower($x['nome'])] <=> [$ordine[$y['tipo']], mb_strtolower($y['nome'])]);
        $frasi = implode(' ', array_column($problemi, 'frase'));

        return [
            'problemi' => $problemi,
            'da_guardare' => $daGuardare,
            'serve_la_scelta' => $problemi !== [],
            'frase' => $problemi === [] ? null : $frasi . ' ' . self::FRASE_LEGGE,
        ];
    }

    /**
     * Il segno «di un gruppo» di una tabella dei punti cambiato dopo la prima spedizione (Coda 734, §1.1 del progetto della tappa 3): l'avviso ha detto chi
     * vota sul punto con il segno di allora, e la sala conta con quello di adesso. Il segno di allora si legge nel registro delle modifiche della tabella:
     * il primo cambio dopo la spedizione ha il valore di prima, l'ultimo il giorno. Messo e tolto di nuovo (com'era all'invio), niente. La tabella del gruppo
     * dell'assemblea dei condòmini di un gruppo non conta: il suo segno non si toglie finché l'assemblea è aperta.
     *
     * @return list<string>
     */
    private function segniCambiati(Assemblea $assemblea): array
    {
        $primoInvio = DB::table('registro_invii')->where('tipo', RegistroInvio::TIPO_CONVOCAZIONE)->where('documentabile_type', $assemblea->getMorphClass())
            ->where('documentabile_id', $assemblea->id)->min('emesso_il');
        if ($primoInvio === null) {
            return [];
        }
        $gruppo = UnitaDelPunto::gruppoDellAssemblea($assemblea);
        $numeri = [];
        foreach ($assemblea->punti()->whereNotNull('tabella_id')->orderBy('numero')->get(['numero', 'tabella_id', 'maggioranza']) as $p) {
            if ((int) $p->tabella_id !== $gruppo && $p->maggioranza !== Maggioranza::SENZA_VOTO) {
                $numeri[(int) $p->tabella_id][] = (string) $p->numero;
            }
        }
        if ($numeri === []) {
            return [];
        }
        $righe = RegistroModifica::query()->where('oggetto_tipo', TabellaNelRegistro::TIPO)->whereIn('oggetto_id', array_keys($numeri))
            ->where('avvenuta_il', '>', $primoInvio)->orderBy('avvenuta_il')->orderBy('id')->get(['oggetto_id', 'campi', 'avvenuta_il']);
        $tabelle = Tabella::query()->whereIn('id', array_keys($numeri))->get(['id', 'nome', 'di_un_gruppo'])->keyBy('id');
        $frasi = [];
        foreach ($numeri as $id => $diQuesta) {
            $cambi = $righe->where('oggetto_id', $id)->map(fn (RegistroModifica $r) => ['campo' => collect((array) $r->campi)->firstWhere('chiave', 'di_un_gruppo'),
                'il' => $r->avvenuta_il])->filter(fn ($c) => $c['campo'] !== null)->values();
            $tabella = $tabelle->get($id);
            if ($cambi->isEmpty() || $tabella === null || (bool) ($cambi->first()['campo']['valore_prima'] ?? false) === (bool) $tabella->di_un_gruppo) {
                continue;
            }
            $giorno = $cambi->last()['il']->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
            $punti = (count($diQuesta) === 1 ? 'sul punto ' : 'sui punti ') . ConvocatiDellAssemblea::elenco($diQuesta);
            $frasi[] = (bool) $tabella->di_un_gruppo
                ? 'La tabella «' . $tabella->nome . '» è segnata «di un gruppo» dal ' . $giorno . ', dopo l\'invio dell\'avviso: ' . $punti
                    . ' votano solo i condòmini delle sue unità, e l\'avviso non lo dice.'
                : 'La tabella «' . $tabella->nome . '» non è più segnata «di un gruppo» dal ' . $giorno . ', dopo l\'invio dell\'avviso: ' . $punti
                    . ' l\'avviso dice che votano solo i condòmini delle sue unità, e in sala vota tutto il perimetro del punto.';
        }

        return $frasi;
    }

    /** Da quando la persona è titolare dell'unità, quando lo è da dopo la prima spedizione: «10/12/2026». @param array<string, mixed> $convocato */
    private function titolareDal(Assemblea $assemblea, array $convocato): ?string
    {
        $unita = array_map(fn ($u) => (int) $u['immobile_id'], (array) $convocato['unita']);
        $dal = DB::table('anagrafica_immobile')->where('anagrafica_id', (int) $convocato['anagrafica_id'])->whereIn('immobile_id', $unita)->where('attivo', true)
            ->max('data_inizio');
        $giorno = $dal === null ? null : substr((string) $dal, 0, 10);
        $primoInvio = DB::table('registro_invii')->where('tipo', RegistroInvio::TIPO_CONVOCAZIONE)->where('documentabile_type', $assemblea->getMorphClass())
            ->where('documentabile_id', $assemblea->id)->min('emesso_il');

        return $giorno !== null && $giorno > '2000-01-01' && ($primoInvio === null || $giorno >= substr((string) $primoInvio, 0, 10))
            ? \Carbon\CarbonImmutable::parse($giorno)->format('d/m/Y') : null;
    }

    /** «l'11/12/2026», «il 05/12/2026». */
    private static function ilGiorno(?string $giorno): string
    {
        if ($giorno === null) {
            return '';
        }
        $g = \Carbon\CarbonImmutable::parse($giorno);

        return (in_array($g->day, [1, 8, 11], true) ? 'l\'' : 'il ') . $g->format('d/m/Y');
    }
}
