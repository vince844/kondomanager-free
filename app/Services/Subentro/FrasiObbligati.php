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
     * L'eccezione dei saldi intestati all'unità (decisione 28.8 a), nella stessa forma qui e nella nota di solidarietà
     * (`NotaSolidarieta`). Non dice a chi vadano: un piano straordinario generato dopo il passaggio li addebita al nudo
     * proprietario nella riserva, e nella vendita piena li divide fra chi compra e chi vende (ramo B2 di `GenerateSaldiAction`,
     * sentinella della Coda 174).
     */
    public const SALVO_SALDI_DELL_UNITA = ', salvo i saldi intestati all\'unità, che si addebitano quando si genera il piano';

    /**
     * @param 'vendita'|'inizio_locazione'|'fine_locazione'|'usufrutto' $tipo
     * @param array{sottotipo?: ?string, copia_autentica?: bool, copia_autentica_il?: ?CarbonImmutable, regime_contratto?: ?string, immobili?: list<int>} $dati
     *        `immobili`: l'unità e le pertinenze del passaggio, per sapere se hanno saldi intestati all'unità (decisione 28.8 a);
     *        senza, la frase della vendita nomina l'eccezione comunque
     * @param Collection<int, TitolaritaImmobile> $proprietari  i proprietari che restano (fine locazione)
     * @param string|array|null $nudo  chi torna proprietario pieno all'estinzione dell'usufrutto: il nome, o con più nudi
     *                                 (S8-30) l'elenco `[{nome, quota, parte?}]` — le frasi li nominano tutti con la quota;
     *                                 `parte` è la quota che torna piena quando la nuda si riunisce all'usufrutto solo in parte
     * @param bool $altriTitolari  all'estinzione, sull'unità restano titolari diversi da chi torna proprietario pieno (un altro
     *                             usufrutto con i suoi nudi, il proprietario pieno dell'altra metà) (1.11.0-beta.43)
     * @param bool $usufruttoCheResta  all'estinzione, sull'unità resta in corso un altro usufrutto
     * @return list<string>
     */
    public function frasi(string $tipo, array $dati, Condominio $condominio, ?string $uscente, ?string $entrante, CarbonImmutable $dal, Collection $proprietari, string|array|null $nudo, bool $registrato = false, bool $altriTitolari = false, bool $usufruttoCheResta = false): array
    {
        $dalA = $this->data($dal);

        switch ($tipo) {
            case 'vendita':
                [$corrente, $precedente] = $this->esercizi($condominio, $dal);
                $chiEntra = $entrante ?? 'Chi entra';
                $chiEsce = $uscente ?? 'chi esce';
                // Decisione 28.8 a: un saldo intestato all'unità (l'arretrato dell'appartamento, non di una persona) si addebita
                // quando si genera il piano, e un piano generato dopo il passaggio può addebitarlo a chi compra (sul piano
                // straordinario: tutto nella riserva, metà nella vendita piena). La frase lo dice quando l'unità o una pertinenza
                // ne ha, senza dire a chi vada; se quei saldi debbano seguire chi era titolare nel periodo è la Coda 174.
                $salvo = $this->saldiIntestatiAllUnita($dati['immobili'] ?? null) ? self::SALVO_SALDI_DELL_UNITA : '';
                if (($dati['sottotipo'] ?? null) === Subentro::RISERVA_USUFRUTTO) {
                    return $this->frasiRiserva($chiEntra, $chiEsce, $dalA, $corrente, $precedente, $dati, $registrato, $salvo);
                }
                $frasi = [sprintf(
                    '%s risponde in solido con %s per i contributi di questa unità relativi all\'esercizio %s e all\'esercizio %s (art. 63 co. 4 disp. att. c.c.). Il programma non intesta nulla a %s%s: la nota resta qui e nella situazione debitoria dell\'unità, e chi paga in forza della solidarietà ha regresso verso il venditore per quanto ha pagato al condominio, salvo diverso accordo fra le parti (Cass. 11199/2021).',
                    $chiEntra, $chiEsce, $corrente, $precedente, $chiEntra, $salvo,
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
                    // 1.11.0-beta.43: quando sull'unità restano altri titolari (un altro usufrutto con i suoi nudi, il proprietario
                    // pieno dell'altra metà) o la nuda torna piena solo in parte, chi torna proprietario pieno lo è della parte
                    // dell'usufrutto che finisce, non dell'unità. Del resto rispondono, come prima, i suoi titolari — chi torna pieno
                    // compreso, se ne resta nudo proprietario —, e dove resta un usufrutto nudo proprietario e usufruttuario in
                    // solido (art. 67 ult. co.). Si decide dalle righe, non dalla somma delle quote (rilievi V1 e V2).
                    $lista = is_array($nudo) ? array_values($nudo) : [['nome' => $nudo]];
                    $inParte = collect($lista)->contains(fn ($n) => isset($n['parte']));
                    if ($altriTitolari || $inParte) {
                        $piu = count($lista) > 1;
                        $nomi = array_map(fn ($n) => ($n['nome'] ?? 'il nudo proprietario') . ($piu || isset($n['parte'])
                            ? ' (per ' . NudiDellEstinzione::percentuale((float) ($n['parte'] ?? $n['quota'] ?? 0)) . ')' : ''), $lista);
                        $ultimo = array_pop($nomi);

                        return [sprintf('Dal %s %s %s della parte dell\'usufrutto che finisce e ne %s; per il resto dell\'unità rispondono, come prima, i titolari di quella parte%s. Le rate già emesse a %s restano sue.',
                            $dalA, $nomi === [] ? $ultimo : implode(', ', $nomi) . ' e ' . $ultimo, $piu ? 'tornano proprietari pieni' : 'torna proprietario pieno',
                            $piu ? 'rispondono, ciascuno per la sua parte' : 'risponde',
                            $usufruttoCheResta || $inParte ? ' (il nudo proprietario e l\'usufruttuario in solido, art. 67 ult. co. disp. att. c.c.)' : '',
                            $uscente ?? 'chi esce')];
                    }
                    if (is_array($nudo) && count($nudo) > 1) {
                        $elenco = array_map(fn ($n) => sprintf('%s (%s %%)', $n['nome'] ?? '?', rtrim(rtrim(number_format((float) ($n['quota'] ?? 0), 2, ',', '.'), '0'), ',')), $nudo);
                        $ultimo = array_pop($elenco);

                        return [sprintf('Dal %s %s tornano proprietari pieni e rispondono di tutte le spese dell\'unità, ciascuno per la sua quota. Le rate già emesse a %s restano sue.', $dalA, implode(', ', $elenco) . ' e ' . $ultimo, $uscente ?? 'chi esce')];
                    }
                    $nome = is_array($nudo) ? ($nudo[0]['nome'] ?? null) : $nudo;

                    return [sprintf('Dal %s %s torna proprietario pieno e risponde di tutte le spese dell\'unità. Le rate già emesse a %s restano sue.', $dalA, $nome ?? 'il nudo proprietario', $uscente ?? 'chi esce')];
                }

                // Decisione 29.3 (beta.38): dal 2013 verso il condominio nudo proprietario e usufruttuario rispondono in solido
                // (art. 67 ult. co.); la natura della spesa conta fra le parti (artt. 1004 e 1005 c.c.). La forma è quella della
                // riserva (`frasiRiserva`), con le persone scambiate: qui chi esce resta nudo proprietario.
                return [sprintf('Dal %s %s, nudo proprietario, e %s, usufruttuario, rispondono in solido verso il condominio (art. 67 ult. co. disp. att. c.c.); fra di loro le spese ordinarie sono dell\'usufruttuario (art. 1004 c.c.), quelle straordinarie del nudo proprietario (art. 1005 c.c.). Come il programma li addebita è scritto nella guida «Ruoli e usufrutto».', $dalA, $uscente ?? 'chi esce', $entrante ?? 'chi entra')];
        }

        return [];
    }

    /**
     * La vendita con riserva d'usufrutto (1.11.0-beta.38, decisione 28). Quello che è solido si afferma: dal giorno
     * dell'atto nudo proprietario e usufruttuario rispondono in solido (art. 67 ult. co., testo verificato il
     * 28/09/2026), e fra loro vale la natura della spesa (artt. 1004 e 1005 c.c.). Quello che non lo è si dice come punto
     * aperto: se chi compra la nuda proprietà risponda anche dell'arretrato di chi vende (art. 63 co. 4) nessuna sentenza
     * né commento lo chiarisce (ricerca del 29/09/2026) — decide l'amministratore (decisione 28.2). La copia autentica non
     * libera chi vende: resta usufruttuario, e per i contributi successivi risponde in solido (decisione 28.3).
     *
     * @return list<string>
     */
    private function frasiRiserva(string $chiEntra, string $chiEsce, string $dalA, string $corrente, string $precedente, array $dati, bool $registrato, string $salvo): array
    {
        $frasi = [
            // Decisione 28.5 (rilievo B1 della Fase 1-bis): fra le parti vale la natura della spesa, ma il programma addebita
            // secondo i coefficienti — il rinvio alla guida è lo stesso della costituzione.
            sprintf('Dal %s %s, nudo proprietario, e %s, usufruttuario, rispondono in solido verso il condominio (art. 67 ult. co. disp. att. c.c.); fra di loro le spese ordinarie sono dell\'usufruttuario (art. 1004 c.c.), quelle straordinarie del nudo proprietario (art. 1005 c.c.). Come il programma li addebita è scritto nella guida «Ruoli e usufrutto».', $dalA, $chiEntra, $chiEsce),
            // Decisione 28.8 a: con un saldo intestato all'unità la frase nomina l'eccezione, come nella vendita piena.
            sprintf('Se %s risponda anche dei contributi non pagati da %s negli esercizi %s e %s (art. 63 co. 4 disp. att. c.c.), come chi compra in una vendita piena, la giurisprudenza non l\'ha chiarito: decide l\'amministratore. Il programma non intesta nulla a %s per quel periodo%s.', $chiEntra, $chiEsce, $corrente, $precedente, $chiEntra, $salvo),
        ];
        $copiaIl = $dati['copia_autentica_il'] ?? null;
        $frasi[] = $registrato && ($dati['copia_autentica'] ?? false) && $copiaIl !== null
            ? sprintf('Copia autentica del titolo ricevuta il %s: per i contributi successivi %s non è liberato, perché resta usufruttuario e risponde in solido con il nudo proprietario (art. 67 ult. co. disp. att. c.c.).', $this->data($copiaIl), $chiEsce)
            : sprintf('La copia autentica del titolo si registra come per ogni vendita, ma non libera %s: resta usufruttuario, e per i contributi successivi risponde in solido con il nudo proprietario (art. 67 ult. co. disp. att. c.c.).', $chiEsce);

        return $frasi;
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
            'sottotipo'          => $tipo === 'usufrutto' ? ($subentro->tipologia === 'proprietario' ? 'estinzione' : 'costituzione') : ($subentro->riservaUsufrutto() ? Subentro::RISERVA_USUFRUTTO : null),
            'copia_autentica'    => $subentro->copia_autentica_il !== null,
            'copia_autentica_il' => $subentro->copia_autentica_il ? CarbonImmutable::parse($subentro->copia_autentica_il->toDateString()) : null,
            'regime_contratto'   => $subentro->regime_contratto,
            // Decisione 28.8 a: l'unità e le pertinenze passate con lo stesso atto.
            'immobili'           => $this->immobiliDelPassaggio($subentro),
        ];

        $estinzione = $tipo === 'usufrutto' && $subentro->tipologia === 'proprietario';
        // 1.11.0-beta.43: il registro dice quali nude sono tornate piene e, se solo in parte, per quanto; la quota è quella del giorno
        // del passaggio (una riga cambiata sul posto la si legge dal «prima» del registro, rilievo V4). Le righe in corso alla decorrenza
        // dicono se sull'unità restano altri titolari e un altro usufrutto. Senza il registro (passaggi di prima), chi è tornato pieno
        // sono i proprietari in corso alla decorrenza (S8-30), come prima.
        $quotaAllora = [];
        foreach ((array) ($subentro->registro['righe'] ?? []) as $op) {
            if (($op['operazione'] ?? null) === 'modificata' && isset($op['prima']['quota'])) {
                $quotaAllora[(int) ($op['id'] ?? 0)] = (float) $op['prima']['quota'];
            }
        }
        $righeNudi = $estinzione && ! empty($subentro->registro['nudi']['righe'])
            ? TitolaritaImmobile::with('anagrafica')->whereIn('id', (array) $subentro->registro['nudi']['righe'])->orderBy('id')->get() : collect();
        $dalRegistro = $righeNudi->map(fn (TitolaritaImmobile $t) => ['nome' => $t->anagrafica?->nome, 'quota' => $quotaAllora[(int) $t->id] ?? (float) $t->quota]
            + (isset($subentro->registro['nudi']['consolida'][(string) $t->id]) ? ['parte' => (float) $subentro->registro['nudi']['consolida'][(string) $t->id]] : []))->all();
        $restano = $dalRegistro !== [] && $subentro->immobile !== null
            ? $subentro->immobile->titolarita()->whereIn('tipologia', ['proprietario', 'nuda_proprietario', 'usufruttuario'])->get()
                ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza) && ! $righeNudi->contains('anagrafica_id', $t->anagrafica_id))
            : collect();

        return $this->frasi(
            $tipo, $dati, $subentro->condominio,
            $subentro->uscente?->nome, $subentro->entrante?->nome,
            $decorrenza, $proprietari,
            match (true) {
                $dalRegistro !== [] => $dalRegistro,
                $estinzione && $proprietari->count() > 1 => $proprietari->map(fn (TitolaritaImmobile $t) => ['nome' => $t->anagrafica?->nome, 'quota' => (float) $t->quota])->all(),
                $tipo === 'usufrutto' => $subentro->entrante?->nome,
                default => null,
            },
            registrato: true,
            altriTitolari: $restano->isNotEmpty(),
            usufruttoCheResta: $restano->contains('tipologia', 'usufruttuario'),
        );
    }

    /**
     * L'unità di un passaggio registrato e le pertinenze passate con lo stesso atto (i passaggi figli): dove il vademecum e la
     * nota di solidarietà cercano i saldi intestati all'unità (decisione 28.8 a). Per il passaggio di una pertinenza è la
     * pertinenza sola.
     *
     * @return list<int>
     */
    public function immobiliDelPassaggio(Subentro $subentro): array
    {
        return array_values(array_filter([(int) $subentro->immobile_id, ...$subentro->pertinenze()->pluck('immobile_id')->map(fn ($id) => (int) $id)->all()]));
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

    /**
     * Le unità hanno saldi intestati all'unità (`anagrafica_id` nullo, non di un fornitore)? Sono quelli che
     * `GenerateSaldiAction` (ramo B2) addebita ai titolari che legge quando il piano si genera (decisione 28.8 a). Applicati o
     * no: la frase dice come il programma li tratta, e resta vera anche quando un piano li ha già assorbiti. Senza l'elenco
     * delle unità la risposta è sì — la frase nomina l'eccezione, che è vera comunque.
     *
     * @param list<int>|null $immobileIds
     */
    public function saldiIntestatiAllUnita(?array $immobileIds): bool
    {
        if ($immobileIds === null) {
            return true;
        }

        return $immobileIds !== [] && DB::table('saldi')->whereIn('immobile_id', $immobileIds)->whereNull('anagrafica_id')->whereNull('fornitore_id')->where('saldo_iniziale', '!=', 0)->exists();
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
