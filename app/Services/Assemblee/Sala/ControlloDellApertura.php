<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\ModalitaAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ConvocatiDellAssemblea;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\TerminiDellaConvocazione;
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
 * con la fotografia della seduta superano il quinto.
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
