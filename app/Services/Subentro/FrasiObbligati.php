<?php

namespace App\Services\Subentro;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Chi resta obbligato»: il vademecum dell'art. 63 disp. att. c.c. per un passaggio, in frasi.
 *
 * Nato come blocco 3 dell'anteprima (`AnteprimaPassaggio`), dalla 1.11.0-beta.31 S6 vive qui perché le
 * stesse frasi servono **dopo** la registrazione — nello storico dell'unità (sezione «Passaggi
 * registrati»), e in S7 nella situazione debitoria — dove i valori non sono più una proposta ma un fatto
 * registrato in `subentri`: `daSubentro()` li ricostruisce dalla riga (sottotipo dalla `tipologia`,
 * copia autentica da `copia_autentica_il`, proprietari **alla decorrenza**).
 *
 * Due voci per lo stesso testo: in anteprima («`registrato = false`») le frasi possono dire cosa fare —
 * «registra pure il passaggio», «verranno addebitate»; nel vademecum (`registrato = true`) descrivono
 * uno stato, senza imperativi né futuro: quel testo va in una stampa e vale finché non cambia un fatto.
 *
 * Il criterio di legge, in una riga: chi entra risponde in solido per l'esercizio in corso e il
 * precedente (co. 4); chi esce resta obbligato finché il condominio non riceve copia autentica del
 * titolo (co. 5); chi paga in forza della solidarietà ha regresso, salvo diverso accordo (Cass. 11199/2021).
 */
final class FrasiObbligati
{
    /**
     * @param 'vendita'|'inizio_locazione'|'fine_locazione'|'usufrutto' $tipo
     * @param array{sottotipo?: ?string, copia_autentica?: bool, copia_autentica_il?: ?CarbonImmutable, regime_contratto?: ?string} $dati
     * @param Collection<int, TitolaritaImmobile> $proprietari  i proprietari che restano (fine locazione)
     * @param string|array|null $nudo  chi torna proprietario pieno all'estinzione dell'usufrutto: il nome, o con più nudi
     *                                 (S8-30) l'elenco `[{nome, quota}]` — le frasi li nominano tutti con la quota
     * @return list<string>
     */
    public function frasi(string $tipo, array $dati, Condominio $condominio, ?string $uscente, ?string $entrante, CarbonImmutable $dal, Collection $proprietari, string|array|null $nudo, bool $registrato = false): array
    {
        $dalA = $this->data($dal);

        switch ($tipo) {
            case 'vendita':
                [$corrente, $precedente] = $this->esercizi($condominio, $dal);
                $chiEntra = $entrante ?? 'Chi entra';
                $chiEsce = $uscente ?? 'chi esce';
                $frasi = [sprintf(
                    '%s risponde in solido con %s per i contributi di questa unità relativi all\'esercizio %s e all\'esercizio %s (art. 63 co. 4 disp. att. c.c.). Il programma non intesta nulla a %s: la nota resta qui e nella situazione debitoria dell\'unità, e chi paga in forza della solidarietà ha regresso verso il venditore per quanto ha pagato al condominio, salvo diverso accordo fra le parti (Cass. 11199/2021).',
                    $chiEntra, $chiEsce, $corrente, $precedente, $chiEntra,
                )];
                $copiaIl = $dati['copia_autentica_il'] ?? null;
                if (! ($dati['copia_autentica'] ?? false)) {
                    $frasi[] = $registrato
                        ? sprintf('Finché il condominio non riceve copia autentica del titolo, l\'obbligo verso il condominio resta a %s (art. 63 co. 5 disp. att. c.c.): la liberazione decorre dal giorno in cui la copia arriva, e quel giorno si registra qui.', $chiEsce)
                        : sprintf('Finché non ricevi copia autentica del titolo, l\'obbligo verso il condominio resta a %s (art. 63 co. 5 disp. att. c.c.). Registra pure il passaggio: l\'anagrafe si aggiorna sulla comunicazione scritta del condòmino (art. 1130 n. 6 c.c.), il titolo serve a un altro effetto.', $chiEsce);
                } elseif ($registrato && $copiaIl !== null) {
                    $frasi[] = sprintf('Copia autentica del titolo ricevuta il %s: da quel giorno %s è liberato verso il condominio per i contributi successivi (art. 63 co. 5 disp. att. c.c.). La solidarietà del comma 4 sui contributi dell\'esercizio in corso e del precedente non dipende dalla copia e resta.', $this->data($copiaIl), $chiEsce);
                }

                return $frasi;

            case 'inizio_locazione':
                $frasi = [$registrato
                    ? 'Verso il condominio continua a rispondere il proprietario. All\'inquilino sono addebitate solo le voci di spesa il cui coefficiente indica “inquilino”.'
                    : 'Verso il condominio continua a rispondere il proprietario. All\'inquilino verranno addebitate solo le voci di spesa il cui coefficiente indica “inquilino”.'];
                $voci = $this->vociACaricoDellInquilino($condominio);
                $frasi[] = $voci->isEmpty()
                    ? ($registrato
                        ? 'Nessuna voce di spesa è intestata all\'inquilino: la locazione non cambia nessun importo.'
                        : 'Nessuna voce di spesa è oggi intestata all\'inquilino: registrare la locazione non cambierà nessun importo.')
                    : sprintf('%d %s una quota a carico dell\'inquilino: %s.', $voci->count(), $voci->count() === 1 ? 'voce di spesa ha' : 'voci di spesa hanno', $this->elenco($voci->take(4)->all()) . ($voci->count() > 4 ? ' e altre' : ''));

                // Sul box locato il regime del contratto cambia cosa spetta all'inquilino (§6.6).
                $regime = $dati['regime_contratto'] ?? null;
                if ($regime === 'atipica') {
                    $frasi[] = 'Verso il condominio risponde il proprietario. Il rimborso delle spese si regola nel contratto: sul box locato a un privato non si applicano né la ripartizione dell\'art. 9 L. 392/1978 né il diritto di voto dell\'art. 10.';
                } elseif ($regime === 'uso_diverso') {
                    $frasi[] = 'Il conduttore ha diritto di voto sulle spese e sulle modalità di gestione del riscaldamento e del condizionamento (art. 10 L. 392/1978, richiamato dall\'art. 41), e la ripartizione degli oneri accessori segue l\'art. 9.';
                } elseif ($regime === 'comodato') {
                    $frasi[] = 'In comodato il comodatario non è conduttore: verso il condominio risponde solo il proprietario, e nessuna voce gli viene addebitata per legge.';
                }

                return $frasi;

            case 'fine_locazione':
                $frasi = [sprintf('Dal %s le voci a carico dell\'inquilino tornano a %s. Le rate già emesse a %s restano sue.', $dalA, $this->nomiOClausola($proprietari, 'al proprietario'), $uscente ?? 'chi esce')];
                if ($entrante) {
                    $frasi[] = sprintf('%s risponde delle voci a carico dell\'inquilino dal %s.', $entrante, $dalA);
                }

                return $frasi;

            case 'usufrutto':
                if (($dati['sottotipo'] ?? 'costituzione') === 'estinzione') {
                    if (is_array($nudo) && count($nudo) > 1) {
                        $elenco = array_map(fn ($n) => sprintf('%s (%s %%)', $n['nome'] ?? '?', rtrim(rtrim(number_format((float) ($n['quota'] ?? 0), 2, ',', '.'), '0'), ',')), $nudo);
                        $ultimo = array_pop($elenco);

                        return [sprintf('Dal %s %s tornano proprietari pieni e rispondono di tutte le spese dell\'unità, ciascuno per la sua quota. Le rate già emesse a %s restano sue.', $dalA, implode(', ', $elenco) . ' e ' . $ultimo, $uscente ?? 'chi esce')];
                    }
                    $nome = is_array($nudo) ? ($nudo[0]['nome'] ?? null) : $nudo;

                    return [sprintf('Dal %s %s torna proprietario pieno e risponde di tutte le spese dell\'unità. Le rate già emesse a %s restano sue.', $dalA, $nome ?? 'il nudo proprietario', $uscente ?? 'chi esce')];
                }

                return [sprintf('Dal %s verso il condominio rispondono entrambi, secondo la natura della spesa: ordinaria all\'usufruttuario %s (art. 1004 c.c.), straordinaria al nudo proprietario %s (art. 1005 c.c.). Come il programma li addebita è scritto nella guida «Ruoli e usufrutto».', $dalA, $entrante ?? 'che entra', $uscente ?? 'chi esce')];
        }

        return [];
    }

    /**
     * Il vademecum di un passaggio **registrato**, dai fatti in `subentri`. Il sottotipo dell'usufrutto si
     * legge dalla `tipologia` di chi entra (`proprietario` = estinzione, `usufruttuario` = costituzione);
     * la copia autentica è un fatto se `copia_autentica_il` è compilata; i proprietari «che restano» sono
     * quelli in corso **alla decorrenza**, non quelli di oggi.
     *
     * @return list<string>
     */
    public function daSubentro(Subentro $subentro): array
    {
        $subentro->loadMissing(['condominio', 'immobile', 'uscente', 'entrante']);
        $decorrenza = CarbonImmutable::parse($subentro->decorrenza->toDateString());
        $tipo = (string) $subentro->tipo_passaggio;

        $proprietari = $subentro->immobile
            ? $subentro->immobile->titolarita()->with('anagrafica')->where('tipologia', 'proprietario')->get()
                ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))->values()
            : collect();

        $dati = [
            'sottotipo'          => $tipo === 'usufrutto' ? ($subentro->tipologia === 'proprietario' ? 'estinzione' : 'costituzione') : null,
            'copia_autentica'    => $subentro->copia_autentica_il !== null,
            'copia_autentica_il' => $subentro->copia_autentica_il ? CarbonImmutable::parse($subentro->copia_autentica_il->toDateString()) : null,
            'regime_contratto'   => $subentro->regime_contratto,
        ];

        return $this->frasi(
            $tipo, $dati, $subentro->condominio,
            $subentro->uscente?->nome, $subentro->entrante?->nome,
            $decorrenza, $proprietari,
            // Estinzione: chi è tornato pieno sono i proprietari in corso alla decorrenza (S8-30: possono essere più d'uno).
            $tipo === 'usufrutto' && $subentro->tipologia === 'proprietario' && $proprietari->count() > 1
                ? $proprietari->map(fn (TitolaritaImmobile $t) => ['nome' => $t->anagrafica?->nome, 'quota' => (float) $t->quota])->all()
                : ($tipo === 'usufrutto' ? $subentro->entrante?->nome : null),
            registrato: true,
        );
    }

    /**
     * L'esercizio in corso alla decorrenza e quello precedente, nominati per anno quando sono solari
     * («esercizio 2026 e esercizio 2025») e per date altrimenti. Se in banca dati manca, si deduce
     * dalla decorrenza: la solidarietà dell'art. 63 co. 4 vale comunque.
     *
     * @return array{0: string, 1: string}
     */
    public function esercizi(Condominio $condominio, CarbonImmutable $dal): array
    {
        $corrente = Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_inizio', '<=', $dal->toDateString())
            ->whereDate('data_fine', '>=', $dal->toDateString())
            ->orderBy('data_inizio')
            ->first();

        $precedente = $corrente
            ? Esercizio::where('condominio_id', $condominio->id)->whereDate('data_fine', '<', $corrente->data_inizio->toDateString())->orderByDesc('data_fine')->first()
            : null;

        return [
            $corrente ? $this->nomeEsercizio($corrente) : (string) $dal->year,
            $precedente ? $this->nomeEsercizio($precedente) : (string) (($corrente?->data_inizio?->year ?? $dal->year) - 1),
        ];
    }

    private function nomeEsercizio(Esercizio $e): string
    {
        $solare = $e->data_inizio->format('m-d') === '01-01' && $e->data_fine->format('m-d') === '12-31' && $e->data_inizio->year === $e->data_fine->year;

        return $solare ? (string) $e->data_inizio->year : sprintf('%s (dal %s al %s)', $e->nome, $this->data($e->data_inizio), $this->data($e->data_fine));
    }

    /** I conti del condominio con almeno un coefficiente a carico dell'inquilino. */
    public function vociACaricoDellInquilino(Condominio $condominio): Collection
    {
        return DB::table('conto_tabella_ripartizioni as r')
            ->join('conto_tabella_millesimale as m', 'm.id', '=', 'r.conto_tabella_millesimale_id')
            ->join('conti as c', 'c.id', '=', 'm.conto_id')
            ->join('piani_conti as p', 'p.id', '=', 'c.piano_conto_id')
            ->join('gestioni as g', 'g.id', '=', 'p.gestione_id')
            ->where('g.condominio_id', $condominio->id)
            ->where('g.attiva', true)
            ->where('c.attivo', true)
            ->where('r.soggetto', 'inquilino')
            ->where('r.percentuale', '>', 0)
            ->distinct()
            ->orderBy('c.nome')
            ->pluck('c.nome');
    }

    private function data(CarbonImmutable|\DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->locale('it')->translatedFormat('j F Y');
    }

    private function elenco(array $voci): string
    {
        $voci = array_values(array_filter($voci));
        if (count($voci) <= 1) {
            return $voci[0] ?? '';
        }
        $ultimo = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultimo;
    }

    private function nomiOClausola(Collection $titolari, string $clausola): string
    {
        $nomi = $titolari->map(fn ($t) => $t->anagrafica?->nome)->filter()->values()->all();

        return $nomi === [] ? $clausola : $this->elenco($nomi);
    }
}
