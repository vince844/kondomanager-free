<?php

namespace App\Services\Subentro;

use App\Enums\NaturaGestione;
use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La nota **calcolata** dell'art. 63 co. 4 disp. att. c.c. (1.11.0-beta.31, B2 S7, §7 del progetto).
 *
 * Chi subentra risponde in solido con il cedente per i contributi dell'anno in corso e di quello precedente.
 * Il programma **non intesta** nulla a chi entra — salvo i saldi intestati all'unità, che un piano generato dopo il
 * passaggio può addebitare anche a chi entra (la nota lo dice, decisione 28.8 a) — e **non sollecita**: le quote emesse a
 * chi è uscito restano sue (invariante 20, la morosità resta all'uscente). Ma chi legge la posizione di chi è entrato — o
 * l'elenco delle rate aperte di un'unità — deve sapere che quel debito esiste e che la legge glielo fa rispondere: la
 * nota lo dice, con il residuo ancora aperto a nome di chi è uscito su quell'unità, finché il passaggio è
 * nella finestra della solidarietà (decorrenza dall'inizio dell'esercizio precedente a quello aperto).
 *
 * Solo le vendite: nella locazione e nell'usufrutto la solidarietà dell'art. 63 co. 4 non c'è. Nella vendita con
 * riserva d'usufrutto (1.11.0-beta.38, decisione 28.2) la nota afferma la solidarietà dell'art. 67 ult. co. dal giorno
 * dell'atto e dice che l'arretrato è un punto aperto su cui decide l'amministratore: nessuna fonte chiarisce se chi
 * compra la sola nuda proprietà risponda ex art. 63 co. 4. L'arretrato sono i contributi sorti prima dell'atto; quelli
 * sorti dopo, che chi vende deve da usufruttuario, la nota li dice a parte, in solido (testi T4 della Fase 1-bis). Per
 * l'ordinaria decide la scadenza della rata, per le spese straordinarie la data della delibera (cantiere C6, decisione 25).
 * Nella vendita della sola nuda proprietà, con un usufruttuario sull'unità alla data dell'atto, la nota della vendita dice
 * anche che dal giorno dell'atto chi compra risponde in solido con l'usufruttuario (art. 67 ult. co., decisione 28.8 b): per
 * nome quando l'usufrutto grava con certezza sulla quota venduta, altrimenti senza nomi (`usufruttuariAlla`).
 */
final class NotaSolidarieta
{
    public function __construct(private readonly FrasiObbligati $frasi = new FrasiObbligati())
    {
    }

    /**
     * Le note per una persona (come entrante) e/o per un'unità, nel condominio.
     *
     * @return list<array{subentro_id:int, immobile:string, uscente:string, entrante:string, decorrenza:string, esercizi:string, residuo_uscente_cents:int, residuo_uscente_formattato:string, testo:string}>
     */
    public function per(Condominio $condominio, ?Anagrafica $entrante = null, ?Immobile $immobile = null): array
    {
        if ($entrante === null && $immobile === null) {
            return [];
        }

        $finestraDal = $this->inizioFinestra($condominio);

        $query = Subentro::with(['uscente', 'entrante', 'immobile'])
            ->where('condominio_id', $condominio->id)
            ->where('tipo_passaggio', 'vendita')
            ->whereNotNull('anagrafica_uscente_id')
            ->whereNotNull('anagrafica_entrante_id');
        if ($finestraDal !== null) {
            $query->whereDate('decorrenza', '>=', $finestraDal->toDateString());
        }
        if ($entrante !== null) {
            $query->where('anagrafica_entrante_id', $entrante->id);
        }
        if ($immobile !== null) {
            $query->where('immobile_id', $immobile->id);
        }

        return $query->orderByDesc('decorrenza')->get()->map(function (Subentro $s) use ($condominio) {
            $decorrenza = CarbonImmutable::parse($s->decorrenza->toDateString());
            [$corrente, $precedente] = $this->frasi->esercizi($condominio, $decorrenza);
            // Testi T4 della Fase 1-bis (beta.38): nella riserva il residuo di chi vende ha due parti. I contributi sorti prima
            // dell'atto sono l'arretrato su cui decide l'amministratore (art. 63 co. 4, decisione 28.2); quelli sorti dal giorno
            // dell'atto chi vende li deve da usufruttuario, e per quelli la solidarietà è certa (art. 67 ult. co.). Tutti
            // insieme, il «punto aperto» cresceva a ogni emissione. Nella vendita piena il residuo resta uno solo. «Sorti»: per
            // l'ordinaria la scadenza della rata; per la straordinaria la data della delibera (cantiere C6, decisione 25) — una
            // spesa deliberata quando chi vende era proprietario pieno è arretrato anche se la rata scade dopo l'atto.
            $riserva = $s->riservaUsufrutto();
            $allAtto = $riserva ? $this->residuoAllAtto((int) $s->anagrafica_uscente_id, (int) $s->immobile_id, $decorrenza->toDateString()) : null;
            $residuo = $allAtto['prima'] ?? $this->residuoAperto((int) $s->anagrafica_uscente_id, (int) $s->immobile_id);
            $dopoLAtto = $allAtto['dopo'] ?? 0;
            $unita = $s->immobile?->nome . ($s->immobile?->interno ? " (Int. {$s->immobile->interno})" : '');
            // Con sole quote ordinarie il criterio è la scadenza, e la frase dice quella; con una straordinaria fra le non
            // pagate dice «sorti», e in fondo il criterio.
            $criterio = $allAtto !== null && $allAtto['straordinarie'];
            $alGiorno = $decorrenza->locale('it')->translatedFormat('j F Y');
            $primaDellAtto = $riserva ? ($criterio ? ' per contributi sorti prima del ' : ' con scadenza prima del ') . $alGiorno : '';

            $residuoFrase = $residuo > 0
                ? sprintf('a nome di %s risultano oggi %s non pagati su questa unità%s', $s->uscente?->nome ?? 'chi è uscito', MoneyHelper::format($residuo), $primaDellAtto)
                : sprintf('a nome di %s non risulta oggi nulla di non pagato su questa unità%s', $s->uscente?->nome ?? 'chi è uscito', $primaDellAtto);
            // Decisione 28.8 b (e 28.4): nella vendita della sola nuda proprietà — la rivendita dopo una riserva, o la nuda
            // proprietà nata da una costituzione — chi compra risponde dal giorno dell'atto anche con l'usufruttuario (art. 67
            // ult. co.). La nota nomina gli usufruttuari solo quando l'usufrutto grava con certezza sulla quota venduta; se no
            // dice «l'usufruttuario della quota acquistata», senza nomi: le righe non dicono su quale quota stia ciascun
            // usufrutto (sonda B1). Nella vendita piena l'usufruttuario non c'è, e il testo resta quello di sempre.
            $usufruttuari = ! $riserva && $s->tipologia === 'nuda_proprietario' ? $this->usufruttuariAlla($s, $decorrenza) : ['nomi' => [], 'certi' => false];
            $chiCompra = $s->entrante?->nome ?? 'chi è entrato';
            $conUsufruttuario = match (true) {
                $usufruttuari['nomi'] === [] => '',
                ! $usufruttuari['certi'] => sprintf(' Dal %s %s, nudo proprietario, risponde in solido con l\'usufruttuario della quota acquistata per i contributi di %s (art. 67 ult. co. disp. att. c.c.).', $alGiorno, $chiCompra, $unita),
                count($usufruttuari['nomi']) === 1 => sprintf(' Dal %s %s, nudo proprietario, e %s, usufruttuario, rispondono in solido per i contributi di %s (art. 67 ult. co. disp. att. c.c.).', $alGiorno, $chiCompra, $usufruttuari['nomi'][0], $unita),
                default => sprintf(' Dal %s %s, nudo proprietario, risponde in solido con gli usufruttuari %s per i contributi di %s (art. 67 ult. co. disp. att. c.c.).', $alGiorno, $chiCompra, $this->elenco($usufruttuari['nomi']), $unita),
            };
            // Decisione 28.8 a: un saldo intestato all'unità, o a una pertinenza passata con lo stesso atto, si addebita quando
            // si genera il piano, e un piano generato dopo il passaggio può addebitarlo a chi compra: «Nessuna quota è
            // intestata a …» nomina l'eccezione, con la forma delle frasi su chi resta obbligato. A chi vada è la Coda 174.
            $salvo = $this->frasi->saldiIntestatiAllUnita($this->frasi->immobiliDelPassaggio($s)) ? FrasiObbligati::SALVO_SALDI_DELL_UNITA : '';
            $testo = $s->riservaUsufrutto() ? sprintf(
                'Dal %s %s, nudo proprietario, e %s, usufruttuario, rispondono in solido per i contributi di %s (art. 67 ult. co. disp. att. c.c.)%s. Se %s risponda anche di quelli non pagati da %s prima dell\'atto, negli esercizi %s e %s (art. 63 co. 4), la giurisprudenza non l\'ha chiarito: decide l\'amministratore. Nessuna quota è intestata a %s per quel periodo%s: %s.%s',
                $alGiorno, $s->entrante?->nome ?? 'chi è entrato', $s->uscente?->nome ?? 'chi è uscito', $unita,
                $dopoLAtto > 0 ? sprintf(': a nome di %s ne risultano oggi %s non pagati, %s', $s->uscente?->nome ?? 'chi è uscito', MoneyHelper::format($dopoLAtto), $criterio ? 'per contributi sorti da quel giorno in poi' : 'con scadenza da quel giorno in poi') : '',
                $s->entrante?->nome ?? 'chi è entrato', $s->uscente?->nome ?? 'chi è uscito', $corrente, $precedente,
                $s->entrante?->nome ?? 'chi è entrato', $salvo, $residuoFrase,
                ($criterio ? ' Per le spese straordinarie conta la data della delibera, per le altre la scadenza della rata.' : '')
                    . (($allAtto['senza_delibera'] ?? 0) > 0 ? sprintf(' Le spese straordinarie senza data della delibera (%s) sono contate prima dell\'atto.', MoneyHelper::format($allAtto['senza_delibera'])) : ''),
            ) : sprintf(
                '%s risponde in solido con %s per i contributi di %s relativi agli esercizi %s e %s (art. 63 co. 4 disp. att. c.c.). Nessuna quota è intestata a %s per quel periodo%s: %s. Chi paga in forza della solidarietà ha regresso verso il venditore, salvo diverso accordo (Cass. 11199/2021).%s',
                $s->entrante?->nome ?? 'Chi è entrato', $s->uscente?->nome ?? 'chi è uscito', $unita, $corrente, $precedente,
                $s->entrante?->nome ?? 'chi è entrato', $salvo,
                $residuoFrase,
                $conUsufruttuario,
            );

            return [
                'subentro_id'   => (int) $s->id,
                'immobile'      => $unita,
                'uscente'       => $s->uscente?->nome,
                'entrante'      => $s->entrante?->nome,
                'decorrenza'    => $decorrenza->toDateString(),
                'esercizi'      => "{$corrente} e {$precedente}",
                'residuo_uscente_cents' => $residuo,
                'residuo_uscente_formattato' => MoneyHelper::format($residuo),
                'testo'         => $testo,
            ];
        })->values()->all();
    }

    /**
     * L'inizio della finestra di solidarietà: il primo giorno dell'esercizio **precedente** a quello aperto. Senza esercizi
     * aperti la finestra non taglia (si guarda tutto). Senza un precedente registrato lo si deduce, un anno prima di quello
     * aperto, come fa `FrasiObbligati::esercizi` per le frasi: la solidarietà dell'art. 63 co. 4 vale comunque, e una
     * vendita datata nell'anno prima ha la sua nota anche se quell'esercizio non è in banca dati (decisione 28.8 d).
     */
    private function inizioFinestra(Condominio $condominio): ?CarbonImmutable
    {
        $aperto = Esercizio::where('condominio_id', $condominio->id)->where('stato', 'aperto')->orderBy('data_inizio')->first();
        if ($aperto === null) {
            return null;
        }
        $precedente = Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_fine', '<', $aperto->data_inizio->toDateString())
            ->orderByDesc('data_fine')->first();

        return $precedente !== null
            ? CarbonImmutable::parse($precedente->data_inizio->toDateString())
            : CarbonImmutable::parse($aperto->data_inizio->toDateString())->subYear();
    }

    /**
     * Gli usufruttuari dell'unità alla data dell'atto, per nome, e se l'usufrutto grava **con certezza** sulla quota venduta
     * (decisione 28.8 b). Le righe di titolarità non dicono su quale quota stia ciascun usufrutto: è certo solo quando le quote
     * degli usufrutti sommano quelle delle nude proprietà e c'è un solo usufruttuario (grava su tutte) o un solo nudo
     * proprietario, chi compra (tutte gravano sulla sua). Con due nudi proprietari e due usufruttuari, o con una nuda proprietà
     * senza il suo usufrutto registrato, la nota non nomina persone: non si tira a indovinare. Chi compra non è fra i nomi: non
     * risponde in solido con sé stesso.
     *
     * @return array{nomi: list<string>, certi: bool}
     */
    private function usufruttuariAlla(Subentro $s, CarbonImmutable $decorrenza): array
    {
        if ($s->immobile === null) {
            return ['nomi' => [], 'certi' => false];
        }
        [$usufrutti, $nude] = $s->immobile->titolarita()->with('anagrafica')->whereIn('tipologia', ['usufruttuario', 'nuda_proprietario'])
            ->orderBy('anagrafica_immobile.id')->get()
            ->filter(fn (TitolaritaImmobile $t) => $t->inCorsoIl($decorrenza))
            ->partition(fn (TitolaritaImmobile $t) => $t->tipologia === 'usufruttuario');
        $quoteTornano = abs((float) $usufrutti->sum(fn ($t) => (float) $t->quota) - (float) $nude->sum(fn ($t) => (float) $t->quota)) < 0.005;
        $unoSolo = $usufrutti->pluck('anagrafica_id')->unique()->count() === 1 || $nude->pluck('anagrafica_id')->unique()->count() === 1;

        return [
            'nomi' => $usufrutti->filter(fn (TitolaritaImmobile $t) => (int) $t->anagrafica_id !== (int) $s->anagrafica_entrante_id)
                ->map(fn (TitolaritaImmobile $t) => $t->anagrafica?->nome)->filter()->unique()->values()->all(),
            'certi' => $quoteTornano && $unoSolo,
        ];
    }

    private function elenco(array $nomi): string
    {
        if (count($nomi) <= 1) {
            return $nomi[0] ?? '';
        }
        $ultimo = array_pop($nomi);

        return implode(', ', $nomi) . ' e ' . $ultimo;
    }

    /** Il residuo non pagato a nome di chi è uscito sull'unità: quote emesse con importo > pagato. */
    private function residuoAperto(int $anagraficaId, int $immobileId): int
    {
        return (int) $this->nonPagate($anagraficaId, $immobileId)
            ->selectRaw('COALESCE(SUM(rate_quote.importo - rate_quote.importo_pagato), 0) as residuo')
            ->value('residuo');
    }

    /**
     * Lo stesso residuo nella riserva d'usufrutto, diviso al giorno dell'atto (testi T4 e cantiere C6): prima c'è l'arretrato
     * di chi vende, su cui decide l'amministratore (art. 63 co. 4, decisione 28.2); dal giorno dell'atto, i contributi che
     * deve da usufruttuario, in solido con il nudo proprietario (art. 67 ult. co.). Le quote ordinarie si dividono per
     * scadenza della rata. Quelle dei piani straordinari — la natura la dice la gestione (decisione 11), come in
     * `CompetenzaDelPiano` — per **data della delibera** (`piani_rate.data_delibera_assemblea`, decisione 25): una spesa
     * deliberata quando chi vende era proprietario pieno è sua anche se la rata scade dopo l'atto. Senza la data della
     * delibera la quota va con l'arretrato: la lettura prudente, su cui decide l'amministratore, e la nota lo dice. La
     * competenza dichiarata sulla fattura (decisione 26) qui non si legge: la nota non divide una rata per giorni.
     *
     * @return array{prima: int, dopo: int, straordinarie: bool, senza_delibera: int} in centesimi
     */
    private function residuoAllAtto(int $anagraficaId, int $immobileId, string $giorno): array
    {
        $esito = ['prima' => 0, 'dopo' => 0, 'straordinarie' => false, 'senza_delibera' => 0];
        $righe = $this->nonPagate($anagraficaId, $immobileId)
            ->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
            ->leftJoin('gestioni', 'gestioni.id', '=', 'piani_rate.gestione_id')
            ->get(['rate.data_scadenza', 'piani_rate.data_delibera_assemblea', 'gestioni.tipo as natura', DB::raw('rate_quote.importo - rate_quote.importo_pagato as residuo')]);
        foreach ($righe as $r) {
            $residuo = (int) $r->residuo;
            $straordinaria = NaturaGestione::daStringa($r->natura) === NaturaGestione::Straordinaria;
            $delibera = $r->data_delibera_assemblea ? substr((string) $r->data_delibera_assemblea, 0, 10) : null;
            $dopo = $straordinaria ? ($delibera !== null && $delibera >= $giorno) : substr((string) $r->data_scadenza, 0, 10) >= $giorno;
            $esito[$dopo ? 'dopo' : 'prima'] += $residuo;
            $esito['straordinarie'] = $esito['straordinarie'] || $straordinaria;
            if ($straordinaria && $delibera === null) {
                $esito['senza_delibera'] += $residuo;
            }
        }

        return $esito;
    }

    /** Le quote emesse a nome di chi è uscito sull'unità con un residuo da pagare: la base dei due conti qui sopra. */
    private function nonPagate(int $anagraficaId, int $immobileId): \Illuminate\Database\Query\Builder
    {
        return DB::table('rate_quote')
            ->join('rate', 'rate.id', '=', 'rate_quote.rata_id')
            ->where('rate_quote.anagrafica_id', $anagraficaId)
            ->where('rate_quote.immobile_id', $immobileId)
            ->where('rate.stato', 'emessa')
            ->where('rate_quote.stato', '!=', 'annullata')
            ->whereRaw('rate_quote.importo > rate_quote.importo_pagato')
            ->where('rate_quote.importo', '>', 0);
    }
}
