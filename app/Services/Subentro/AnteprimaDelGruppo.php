<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Subentro;

use App\Actions\Subentro\RegistraPassaggiInsiemeAction;
use App\Actions\Subentro\RegistraSubentroAction;
use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use App\Models\User;
use App\Services\Assemblee\Sala\TesteDellaSala;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Il pannello «Cosa cambierà» dei passaggi registrati insieme (decisione 705): più persone che escono con lo stesso atto.
 *
 * **La prova a secco.** Il pannello deve dire le cifre che si scrivono («anteprima = scrittura»), e quello di chi esce per seconda, calcolato da solo, non
 * basta: dopo una fine di locazione con il conguaglio scritto la sua fermata cambia di un centesimo, e cambiano anche le teste e, con la riserva, le voci
 * dell'ordinaria (le sonde del progetto della tappa 7). Senza un secondo motore, il pannello fa quello che farà la registrazione e lo toglie: in una
 * transazione registra **in prova** un passaggio per persona, nell'ordine, con {@see RegistraSubentroAction::execute()} (`prova`), tiene il pannello che
 * ognuno ha scritto, e alla fine torna indietro. Niente resta (righe, saldi, quote, registro delle modifiche); le funzioni «dopo il commit» non partono.
 * Un rifiuto vero (la quota che sfora, la riga già chiusa) torna con il nome della persona.
 *
 * **Il costo.** Ogni calcolo scrive e toglie N passaggi, con i lock dell'unità per la durata del calcolo, come una registrazione: l'insieme è raro e N piccolo.
 *
 * Poi compone il pannello dell'insieme ({@see componi()}), nella forma che la pagina legge (§1.9 del progetto della tappa 7): i fatti per tutti in testa, una
 * parte per persona con il suo pannello intero, il totale di chi entra, le teste una volta, i motivi della spunta per persona, l'impronta.
 */
final class AnteprimaDelGruppo
{
    /**
     * @param array<string,mixed> $dati `AnteprimaPassaggioRequest::datiPassaggio()` con `membri`
     * @return array<string,mixed>
     */
    public function calcola(Condominio $condominio, Immobile $immobile, array $dati, User $utente): array
    {
        $membri = array_values((array) $dati['membri']);
        $nomi = RegistraPassaggiInsiemeAction::nomiDiChiEsce($membri);
        // Lette prima della prova: la quota che chi entra ha già con quel ruolo (il terzo fratello che compra le parti degli altri due), e le teste.
        $giaSua = $this->quotaGiaDiChiEntra($immobile, $dati);
        $teste = $this->teste($condominio, $immobile, $dati, $membri);
        $pannelli = [];
        $finito = new class('La prova a secco del pannello dell\'insieme si toglie.') extends \RuntimeException {};

        try {
            DB::transaction(function () use ($condominio, $immobile, $utente, $membri, $nomi, $finito, &$pannelli) {
                $scritti = [];
                foreach ($membri as $i => $membro) {
                    $membro['nomi_uscenti'] = $nomi;
                    // Coda 786: chi esce con lo stesso atto e i passaggi già scritti dalla prova, per le frasi che non li contano fra «gli altri».
                    $membro = array_merge($membro, RegistraPassaggiInsiemeAction::datiDelGruppo($membri, $i, $scritti));
                    $membro['allegato_titolo'] = null;
                    if ($i > 0) {
                        $membro['ordinaria_impronta'] = null;
                    }
                    try {
                        $esito = app(RegistraSubentroAction::class)->execute($condominio, $immobile, $membro, $utente, prova: true);
                    } catch (ValidationException $e) {
                        throw RegistraPassaggiInsiemeAction::conIlNome($e, $nomi[$i] ?? null, $i, $immobile, $nomi);
                    }
                    $pannelli[] = $esito['anteprima'];
                    $scritti[] = (int) $esito['subentro']->id;
                }
                // La prova finisce qui: la transazione torna indietro, e con lei tutto ciò che i passaggi hanno scritto.
                throw $finito;
            }, 1);
        } catch (\RuntimeException $e) {
            if ($e !== $finito) {
                throw $e;
            }
        }

        return $this->componi($condominio, $immobile, $dati, $pannelli, $giaSua, $teste);
    }

    /**
     * Il pannello dell'insieme dai pannelli di ciascuno (quelli della prova, o quelli che la registrazione ha scritto: il riquadro verde ne legge la frase).
     *
     * @param array<string,mixed> $dati
     * @param list<array<string,mixed>> $pannelli nell'ordine dei membri
     * @return array<string,mixed>
     */
    public function componi(Condominio $condominio, Immobile $immobile, array $dati, array $pannelli, ?float $giaSua = null, ?array $teste = null): array
    {
        $membri = array_values((array) $dati['membri']);
        $nomi = RegistraPassaggiInsiemeAction::nomiDiChiEsce($membri);
        $primo = $pannelli[0] ?? [];
        $ultimo = $pannelli[count($pannelli) - 1] ?? [];
        $decorrenza = CarbonImmutable::instance($dati['decorrenza']);
        $fino = self::data($decorrenza->subDay());
        $dal = self::data($decorrenza);
        $elenco = self::elenco($nomi);
        $entrante = (string) ($dati['entrante']?->nome ?? 'Chi entra');
        $riserva = ($dati['sottotipo'] ?? null) === Subentro::RISERVA_USUFRUTTO;
        $sommaUscenti = round((float) array_sum(array_map(fn (array $m) => (float) $m['quota'], $membri)), 2);
        $giaSua ??= 0.0;
        $finale = round($giaSua + $sommaUscenti, 2);
        $ruolo = mb_strtolower(RuoloAnagraficaImmobile::tryFrom((string) $dati['tipologia'])?->label() ?? (string) $dati['tipologia']);
        $quotaDiChiEntra = $giaSua > 0
            ? sprintf('aveva già il %s %%, dopo l\'atto avrà il %s %%', self::quota($giaSua), self::quota($finale))
            : sprintf('al %s %%', self::quota($finale));

        $frasiAnagrafica = $riserva
            ? [sprintf('%s risulteranno titolari della proprietà piena fino al %s e dell\'usufrutto dal %s, sulla stessa quota.', $elenco, $fino, $dal),
                sprintf('%s risulterà nudo proprietario dal %s, %s.', $entrante, $dal, $quotaDiChiEntra)]
            : [sprintf('%s risulteranno titolari fino al %s. %s dal %s, come %s%s.', $elenco, $fino, $entrante, $dal, $ruolo,
                $giaSua > 0 ? ': ' . $quotaDiChiEntra : ' ' . $quotaDiChiEntra)];
        [$frasiPertinenze, $nomiPertinenze] = $this->pertinenze($immobile, $dati, $membri);

        return [
            'insieme' => true,
            'impronta' => RegistraPassaggiInsiemeAction::impronta($pannelli),
            'riferimento' => [
                'uscente_fino_al' => $decorrenza->subDay()->toDateString(),
                'entrante_dal' => $decorrenza->toDateString(),
                'frase' => $riserva
                    ? sprintf('%s risulteranno titolari della proprietà piena fino al %s compreso e dell\'usufrutto dal %s. %s nudo proprietario dal %s.', $elenco, $fino, $dal, $entrante, $dal)
                    : sprintf('%s risulteranno titolari fino al %s compreso. %s dal %s.', $elenco, $fino, $entrante, $dal),
            ],
            'anagrafica' => ['frasi' => [...$frasiAnagrafica, ...$frasiPertinenze], 'pertinenze' => $nomiPertinenze],
            'parti' => array_map(fn (array $m, int $i) => [
                'riga_id' => (int) $m['riga_uscente']->id,
                'anagrafica_id' => (int) $m['riga_uscente']->anagrafica_id,
                'nome' => $nomi[$i],
                'quota' => round((float) $m['quota'], 2),
                'pertinenze' => $immobile->pertinenze()->whereIn('id', (array) ($m['pertinenze'] ?? []))->pluck('nome')->values()->all(),
                'anteprima' => $pannelli[$i] ?? null,
            ], $membri, array_keys($membri)),
            'totale' => $this->totale($dati, $membri, $pannelli, $nomi, $entrante, $sommaUscenti),
            // La solidarietà con tutti i nomi: ogni passaggio la scrive così (`nomi_uscenti`), e si dice una volta.
            'obbligati' => [
                'frasi' => (array) ($primo['obbligati']['frasi'] ?? []),
                'copia_autentica_mancante' => (bool) ($primo['obbligati']['copia_autentica_mancante'] ?? false),
            ],
            'invarianti' => ['frasi' => $this->invarianti($primo, $ultimo, $nomiPertinenze, $teste)],
            // Con la riserva la scelta e le voci che si spostano sono quelle del primo: dopo di lui le voci sono già spostate.
            'ordinaria' => $primo['ordinaria'] ?? ['applicabile' => false],
            'cancello' => $this->cancello($pannelli, $nomi),
        ];
    }

    /** La quota che chi entra ha già sull'unità con il ruolo del passaggio, il giorno prima dell'atto, in una riga senza fine. */
    private function quotaGiaDiChiEntra(Immobile $immobile, array $dati): float
    {
        $entrante = $dati['entrante'] ?? null;
        if ($entrante === null) {
            return 0.0;
        }
        $giornoPrima = CarbonImmutable::instance($dati['decorrenza'])->subDay();

        return round((float) $immobile->titolarita()->where('anagrafica_id', $entrante->id)->where('tipologia', (string) $dati['tipologia'])->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($giornoPrima) && $t->data_fine === null)->sum(fn (TitolaritaImmobile $t) => (float) $t->quota), 2);
    }

    /**
     * Le pertinenze dell'unità: quelle spuntate passano con la parte di chi ne è titolare (se non è di tutti, la frase dice di chi), le altre restano.
     *
     * @param list<array<string,mixed>> $membri
     * @return array{0: list<string>, 1: list<string>}
     */
    private function pertinenze(Immobile $immobile, array $dati, array $membri): array
    {
        $tutte = $immobile->pertinenze()->get();
        $scelte = $tutte->whereIn('id', (array) ($dati['pertinenze'] ?? []))->values();
        $escluse = $tutte->whereNotIn('id', (array) ($dati['pertinenze'] ?? []))->values();
        $frasi = [];
        if ($scelte->isNotEmpty()) {
            $frasi[] = sprintf('Il passaggio si applica anche a: %s.', self::elenco($scelte->map(function (Immobile $p) use ($membri) {
                $di = array_values(array_map(fn (array $m) => (string) ($m['riga_uscente']->anagrafica?->nome ?? '?'),
                    array_filter($membri, fn (array $m) => in_array((int) $p->id, array_map('intval', (array) ($m['pertinenze'] ?? [])), true))));

                return count($di) === count($membri) ? (string) $p->nome : sprintf('%s, con la parte di %s', $p->nome, self::elenco($di));
            })->all()));
        }
        if ($escluse->isNotEmpty()) {
            $frasi[] = sprintf('%s: il passaggio non %s tocca, %s a chi ne è titolare oggi.', self::elenco($escluse->pluck('nome')->all()),
                $escluse->count() === 1 ? 'la' : 'le', $escluse->count() === 1 ? 'resta' : 'restano');
        }

        return [$frasi, $scelte->pluck('nome')->values()->all()];
    }

    /**
     * Quello che arriva a chi entra in tutto: la somma delle coppie (con la cifra di ciascuna di chi esce) e le quote in bozza che passano a suo nome.
     *
     * @param list<array<string,mixed>> $membri
     * @param list<array<string,mixed>> $pannelli
     * @param list<string> $nomi
     * @return array{quota_che_esce: float, conguaglio_entrante: int, conguaglio_entrante_formattato: string, bozze: int, frase: string}
     */
    private function totale(array $dati, array $membri, array $pannelli, array $nomi, string $entrante, float $sommaUscenti): array
    {
        $perPersona = [];
        $bozze = [];
        foreach ($pannelli as $i => $p) {
            $c = $p['rate']['conguaglio'] ?? null;
            if (is_array($c) && ($c['coppie'] ?? []) !== []) {
                $perPersona[] = [$nomi[$i] ?? '?', (int) ($c['totale_entrante'] ?? 0)];
            }
            foreach ((array) ($c['bozze_riassegnate'] ?? []) as $b) {
                $bozze[] = $b;
            }
        }
        $totale = (int) array_sum(array_column($perPersona, 1));
        $pezzi = [];
        if ($perPersona !== [] && ! empty($dati['rinuncia_conguaglio'])) {
            $pezzi[] = 'il conguaglio non si scrive, perché le parti lo hanno regolato fra loro';
        } elseif ($perPersona !== [] && $totale !== 0) {
            $stessoVerso = collect($perPersona)->every(fn (array $x) => $x[1] === 0 || ($x[1] > 0) === ($totale > 0));
            $dettaglio = $stessoVerso
                ? self::elenco(array_map(fn (array $x) => sprintf('%s da %s', MoneyHelper::format(abs($x[1])), $x[0]), $perPersona))
                : implode(', ', array_map(fn (array $x) => sprintf('%s %s %s', $x[0], MoneyHelper::format(abs($x[1])), $x[1] < 0 ? 'a credito' : 'a debito'), $perPersona));
            $pezzi[] = sprintf('%s di conguaglio %s (%s)', MoneyHelper::format(abs($totale)), $totale > 0 ? 'a debito' : 'a credito', $dettaglio);
        }
        if ($bozze !== []) {
            $scadenze = collect($bozze)->pluck('scadenza')->filter()->unique()->values();
            $importo = (int) collect($bozze)->sum(fn ($b) => (int) ($b['quota_pura'] ?? 0));
            $n = count($bozze);
            $pezzi[] = sprintf('%s in bozza%s, %s%s, che da ora paga a suo nome', $n === 1 ? '1 quota' : $n . ' quote',
                $scadenze->count() === 1 ? ' sulla rata del ' . self::data(CarbonImmutable::parse((string) $scadenze[0])) : '',
                MoneyHelper::format($importo), $n === 1 ? '' : ' in tutto');
        }

        return [
            'quota_che_esce' => $sommaUscenti,
            'conguaglio_entrante' => $totale,
            'conguaglio_entrante_formattato' => MoneyHelper::format(abs($totale)),
            'bozze' => count($bozze),
            'frase' => $pezzi === []
                ? sprintf('In tutto %s: nessun conguaglio da scrivere e nessuna quota in bozza che passa.', FrasiObbligati::a($entrante))
                : sprintf('In tutto %s: %s.', FrasiObbligati::a($entrante), implode(' e ', $pezzi)),
        ];
    }

    /**
     * Le teste in assemblea prima e dopo tutti i passaggi, come le conta la sala ({@see TesteDelCondominio::primaEDopo()}), lette prima della prova: i titolari
     * di oggi dell'unità e delle pertinenze spuntate, senza tutte le persone che escono e con chi entra (con la riserva chi esce resta usufruttuario). Non
     * dal pannello dell'ultimo: con l'atto registrato prima del giorno in cui vale le righe che la prova scrive oggi non sono ancora in corso, e il suo
     * «dopo» conterebbe ancora chi è uscito con i passaggi di prima.
     *
     * @param list<array<string,mixed>> $membri
     * @return array{scelta: string, prima: int, dopo: int}|null
     */
    private function teste(Condominio $condominio, Immobile $immobile, array $dati, array $membri): ?array
    {
        $entrante = $dati['entrante'] ?? null;
        if ($entrante === null) {
            return null;
        }
        $riserva = ($dati['sottotipo'] ?? null) === Subentro::RISERVA_USUFRUTTO;
        $sala = app(\App\Services\Assemblee\Sala\TesteDelCondominio::class);
        $dopo = function (array $titolari, array $escono) use ($entrante, $dati, $riserva): array {
            $esce = fn (array $t) => collect($escono)->contains(fn (array $e) => $e[0] === (int) $t['anagrafica_id'] && $e[1] === (string) $t['ruolo']);
            $restano = array_values(array_map(fn (array $t) => ['anagrafica_id' => (int) $t['anagrafica_id'], 'ruolo' => (string) $t['ruolo']], array_filter($titolari, fn (array $t) => ! $esce($t))));
            foreach ($riserva ? $escono : [] as [$id]) {
                $restano[] = ['anagrafica_id' => $id, 'ruolo' => 'usufruttuario'];
            }
            $restano[] = ['anagrafica_id' => (int) $entrante->id, 'ruolo' => $riserva ? 'nuda_proprietario' : (string) $dati['tipologia']];

            return $restano;
        };
        $chi = fn (array $m) => [(int) $m['riga_uscente']->anagrafica_id, (string) $m['riga_uscente']->tipologia];
        $perUnita = [(int) $immobile->id => $dopo($sala->titolariDiOggi($immobile), array_map($chi, $membri))];
        foreach ($immobile->pertinenze()->whereIn('id', (array) ($dati['pertinenze'] ?? []))->get() as $pertinenza) {
            $suoi = array_values(array_map($chi, array_filter($membri, fn (array $m) => in_array((int) $pertinenza->id, array_map('intval', (array) ($m['pertinenze'] ?? [])), true))));
            if ($suoi !== []) {
                $perUnita[(int) $pertinenza->id] = $dopo($sala->titolariDiOggi($pertinenza), $suoi);
            }
        }
        $conto = $sala->primaEDopo($condominio, $perUnita);

        return ['scelta' => (string) $conto['scelta'], 'prima' => (int) $conto['prima'], 'dopo' => (int) $conto['dopo']];
    }

    /**
     * Cosa non cambia, con le teste contate una volta, prima e dopo tutti i passaggi ({@see teste()}); senza (il riquadro verde, che non le legge), «oggi» dal
     * pannello del primo e «dopo i passaggi» da quello dell'ultimo.
     *
     * @param list<string> $nomiPertinenze
     * @param array{scelta: string, prima: int, dopo: int}|null $conto
     * @return list<string>
     */
    private function invarianti(array $primo, array $ultimo, array $nomiPertinenze, ?array $conto = null): array
    {
        $prima = $conto ?? ($primo['invarianti']['teste'] ?? null);
        $dopo = $conto ?? ($ultimo['invarianti']['teste'] ?? null);
        $teste = is_array($prima) && is_array($dopo)
            ? sprintf('Teste in assemblea, come le conta la sala con la scelta del condominio («%s»): oggi %d, dopo i passaggi %d.',
                $prima['scelta'] === TesteDellaSala::UNA_TESTA ? 'una testa' : 'due teste', (int) $prima['prima'], (int) $dopo['dopo'])
            : 'Teste in assemblea: le conta la sala con la scelta del condominio; il numero si vede dopo aver scelto chi esce e chi entra.';

        return [
            'Millesimi: invariati.',
            'Tabelle millesimali: invariate.',
            $teste,
            $nomiPertinenze === []
                ? 'Pertinenze: il collegamento è descrittivo, non sposta importi.'
                : sprintf('Pertinenze: il collegamento è descrittivo, e le unità che hai spuntato («%s») seguono il passaggio con l\'unità, con i loro importi.', implode('», «', $nomiPertinenze)),
        ];
    }

    /**
     * La spunta: i motivi raggruppati per persona; serve se uno la chiede. Le informazioni e gli avvisi senza doppioni.
     *
     * @param list<array<string,mixed>> $pannelli
     * @param list<string> $nomi
     * @return array{richiesto: bool, gruppi: list<array{nome: string, motivi: list<string>}>, informazioni: list<string>, avvisi: list<string>}
     */
    private function cancello(array $pannelli, array $nomi): array
    {
        $gruppi = [];
        $informazioni = [];
        $avvisi = [];
        foreach ($pannelli as $i => $p) {
            $motivi = array_values((array) ($p['cancello']['motivi'] ?? []));
            if ($motivi !== []) {
                $gruppi[] = ['nome' => $nomi[$i] ?? '?', 'motivi' => $motivi];
            }
            $informazioni = [...$informazioni, ...(array) ($p['cancello']['informazioni'] ?? [])];
            $avvisi = [...$avvisi, ...(array) ($p['cancello']['avvisi'] ?? [])];
        }

        return [
            'richiesto' => collect($pannelli)->contains(fn (array $p) => ! empty($p['cancello']['richiesto'])),
            'gruppi' => $gruppi,
            'informazioni' => array_values(array_unique($informazioni)),
            'avvisi' => array_values(array_unique($avvisi)),
        ];
    }

    private static function data(CarbonImmutable $d): string
    {
        return $d->locale('it')->translatedFormat('j F Y');
    }

    private static function quota(float $q): string
    {
        return rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
    }

    /** «Anna», «Anna e Bruno», «Anna, Bruno e Carla». @param list<string> $voci */
    private static function elenco(array $voci): string
    {
        $voci = array_values(array_filter($voci, fn ($v) => $v !== null && $v !== ''));
        if (count($voci) <= 1) {
            return (string) ($voci[0] ?? '');
        }
        $ultimo = array_pop($voci);

        return implode(', ', $voci) . ' e ' . $ultimo;
    }
}
