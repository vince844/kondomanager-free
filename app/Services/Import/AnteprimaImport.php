<?php

namespace App\Services\Import;

use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTabella;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Helpers\MoneyHelper;

/**
 * Traduce i dati canonici nella forma che la schermata di conferma mostra — S4 (§14.1).
 *
 * ## L'ordine delle sezioni non è casuale
 *
 * **La titolarità viene per prima**, prima delle persone e prima dei numeri. È la cosa che
 * rende una migrazione utile o inutile: un'unità senza titolare non riceve rate, non compare in
 * morosità e non entra in nessun riparto. Il concorrente, dopo dodici livelli importati, lascia
 * le unità con proprietario e inquilino vuoti (§0.3-D) — e nessuna schermata glielo dice.
 *
 * Subito dopo vengono i **saldi con la riga della quadratura**, che è il momento in cui
 * l'amministratore smette di avere paura: il totale non gliel'abbiamo chiesto, l'abbiamo letto
 * nel suo file.
 */
final class AnteprimaImport
{
    /**
     * @param  array<string, mixed>  $canonici
     * @return array<string, mixed>
     */
    public function costruisci(array $canonici): array
    {
        return [
            'condominio' => $this->condominio($canonici),
            'collisioni' => $this->collisioni($canonici),
            'titolarita' => $this->titolarita($canonici),
            'persone' => $this->persone($canonici),
            'unita' => $this->unita($canonici),
            'tabelle' => $this->tabelle($canonici),
            'saldi' => $this->saldi($canonici),
            'totale_record' => $this->totaleRecord($canonici),
        ];
    }

    /**
     * Chi, fra ciò che sta per entrare, **esiste già in archivio**.
     *
     * È l'unica sezione dell'anteprima che interroga il database invece di guardare solo il file,
     * e c'è per un motivo preciso: prima, il duplicato lo scopriva il commit. Il motore emetteva
     * un rilievo «da decidere» che nessuna schermata sapeva mostrare, e alla seconda importazione
     * sullo stesso archivio l'utente sbatteva contro un «Si è fermata a Persone» senza niente da
     * cliccare. La decisione va offerta **prima** di scrivere, che è anche l'unico momento in cui
     * ha senso porla.
     *
     * `crea_nuovo` non compare per il condominio: lo schema ha un indice unico sul codice
     * fiscale, quindi mostrarlo riporterebbe l'utente contro un errore di database.
     *
     * @param  array<string, mixed>  $canonici
     * @return array<string, mixed>
     */
    private function collisioni(array $canonici): array
    {
        $ricerca = new RicercaEsistenti;
        $voci = [];

        $condominio = $canonici[LivelloCondominio::CHIAVE] ?? null;
        $condominioEsistente = $condominio instanceof CanonicalCondominio
            ? $ricerca->condominio($condominio)
            : null;

        if ($condominio instanceof CanonicalCondominio) {
            $esistente = $condominioEsistente;

            if ($esistente !== null) {
                $voci[] = [
                    'chiave_decisione' => LivelloCondominio::CHIAVE.':'.$condominio->chiave(),
                    'cosa' => 'condominio',
                    'nome' => $condominio->nome,
                    'esistente' => $esistente->nome,
                    'motivo' => $ricerca->motivoCondominio($condominio, $esistente),
                    'scelte' => ['unisci', 'salta'],
                ];
            }
        }

        $esercizio = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        if ($esercizio !== null) {
            $esistente = $ricerca->esercizio($esercizio, $condominioEsistente);

            if ($esistente !== null) {
                $voci[] = [
                    'chiave_decisione' => LivelloEsercizi::CHIAVE.':'.$esercizio->etichetta,
                    'cosa' => 'esercizio',
                    'nome' => $esercizio->etichetta,
                    'esistente' => $esistente->nome,
                    'motivo' => RicercaEsistenti::PER_PERIODO,
                    // «unisci» non c'è: due esercizi sullo stesso periodo sdoppierebbero i saldi,
                    // e il livello accetta solo «salta». Offrire l'altra porterebbe al muro.
                    'scelte' => ['salta'],
                ];
            }
        }

        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];

        foreach ($soggetti as $chiave => $s) {
            $esistente = $ricerca->soggetto($s);

            if ($esistente === null) {
                continue;
            }

            $voci[] = [
                'chiave_decisione' => LivelloSoggetti::CHIAVE.':'.$chiave,
                'cosa' => 'persona',
                'nome' => $s->nome,
                'esistente' => $esistente->nome,
                'motivo' => $ricerca->motivoSoggetto($s),
                'scelte' => ['unisci', 'salta'],
            ];
        }

        return [
            'totale' => count($voci),
            'voci' => $voci,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function condominio(array $canonici): ?array
    {
        $c = $canonici[LivelloCondominio::CHIAVE] ?? null;
        $e = $canonici[LivelloEsercizi::CHIAVE] ?? null;

        if ($c === null) {
            return null;
        }

        return [
            'nome' => $c->nome,
            'codice_fiscale' => $c->codiceFiscale,
            'indirizzo' => trim(($c->indirizzo ?? '').' '.($c->cap ?? '').' '.($c->comune ?? '')),
            'esercizio' => $e === null ? null : [
                'etichetta' => $e->etichetta,
                'dal' => $e->dataInizio->format('d/m/Y'),
                'al' => $e->dataFine->format('d/m/Y'),
                'solare' => $e->isSolare(),
            ],
        ];
    }

    /**
     * Chi possiede cosa — e, in evidenza, **le unità che resterebbero senza nessuno**.
     *
     * @param  array<string, mixed>  $canonici
     */
    private function titolarita(array $canonici): array
    {
        /** @var list<CanonicalTitolarita> $righe */
        $righe = $canonici[LivelloTitolarita::CHIAVE] ?? [];
        $unita = $canonici[LivelloUnita::CHIAVE] ?? [];
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];

        $conTitolare = [];
        foreach ($righe as $t) {
            $conTitolare[$t->immobileRef] = true;
        }

        $orfane = array_values(array_diff(array_keys($unita), array_keys($conTitolare)));

        $elenco = [];
        // L'elenco esce **intero**: il taglio è di presentazione e lo fa la schermata, che ha
        // un «Vedile tutte» per toglierlo. Tagliare qui rendeva quel pulsante impossibile —
        // le righe oltre la dodicesima non arrivavano mai al client — e trasformava
        // un'anteprima in un campione senza modo di guardare il resto. Una migrazione tipica
        // è qualche centinaio di righe: non è un peso.
        foreach ($righe as $t) {
            /** @var CanonicalSoggetto|null $soggetto */
            $soggetto = $soggetti[$t->soggettoRef] ?? null;

            $elenco[] = [
                'unita' => $t->immobileRef,
                'persona' => $soggetto?->nome ?? $t->soggettoRef,
                'ruolo' => $t->ruolo->label(),
                'nota' => $soggetto?->note !== null && str_contains($soggetto->note, '%')
                    ? 'quota nelle note del file'
                    : null,
            ];
        }

        return [
            'totale' => count($righe),
            'unita_coinvolte' => count($conTitolare),
            'unita_senza_titolare' => $orfane,
            'elenco' => $elenco,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function persone(array $canonici): array
    {
        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $canonici[LivelloSoggetti::CHIAVE] ?? [];

        $daDividere = [];

        foreach ($soggetti as $chiave => $s) {
            if (preg_match('#\s/\s#', $s->nome) === 1) {
                $daDividere[] = [
                    'chiave' => $chiave,
                    'nome' => $s->nome,
                    'pezzi' => array_map('trim', preg_split('#\s*/\s*#', $s->nome) ?: []),
                ];
            }
        }

        return [
            'totale' => count($soggetti),
            'senza_codice_fiscale' => count(array_filter($soggetti, fn (CanonicalSoggetto $s) => ! $s->haCodiceFiscale())),
            'da_dividere' => $daDividere,
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function unita(array $canonici): array
    {
        $unita = $canonici[LivelloUnita::CHIAVE] ?? [];

        return [
            'totale' => count($unita),
            'elenco' => array_map(
                fn ($u) => ['chiave' => $u->chiave(), 'nome' => $u->denominazione()],
                array_values($unita),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $canonici
     */
    private function tabelle(array $canonici): array
    {
        /** @var array<string, CanonicalTabella> $tabelle */
        $tabelle = $canonici[LivelloTabelle::CHIAVE] ?? [];
        $totaleUnita = count($canonici[LivelloUnita::CHIAVE] ?? []);

        return array_values(array_map(fn (CanonicalTabella $t) => [
            'nome' => $t->nome,
            'partecipanti' => $t->partecipanti(),
            'somma' => round($t->somma(), 4),
            'parziale' => $t->partecipanti() < $totaleUnita,
            'parti_uguali' => $t->isPartiUguali(),
        ], $tabelle));
    }

    /**
     * Il riquadro della quadratura: il totale **letto nel file**, la somma delle righe, lo scarto.
     *
     * @param  array<string, mixed>  $canonici
     */
    private function saldi(array $canonici): ?array
    {
        /** @var CanonicalSaldiApertura|null $saldi */
        $saldi = $canonici[LivelloSaldi::CHIAVE] ?? null;

        if ($saldi === null) {
            return null;
        }

        $cessati = array_filter($saldi->righe, fn (CanonicalSaldo $s) => $s->daTitolareCessato);

        return [
            'righe' => count($saldi->righe),
            'totale_riferimento' => $saldi->totaleRiferimentoCents === null
                ? null
                : MoneyHelper::format($saldi->totaleRiferimentoCents),
            'somma_righe' => MoneyHelper::format($saldi->sommaRigheCents()),
            'arrotondamenti' => MoneyHelper::format($saldi->arrotondamentiCents),
            'scarto' => $saldi->scartoCents() === null ? null : MoneyHelper::format($saldi->scartoCents()),
            'quadra' => $saldi->quadra(),
            'da_titolari_cessati' => count($cessati),
            'importo_cessati' => MoneyHelper::format(
                array_sum(array_map(fn (CanonicalSaldo $s) => $s->importoCents, $cessati)),
            ),
        ];
    }

    /**
     * Il numero che finisce **dentro** il pulsante, non accanto.
     *
     * @param  array<string, mixed>  $canonici
     */
    private function totaleRecord(array $canonici): int
    {
        $tabelle = $canonici[LivelloTabelle::CHIAVE] ?? [];
        $quote = array_sum(array_map(fn (CanonicalTabella $t) => $t->partecipanti(), $tabelle));

        return ($canonici[LivelloCondominio::CHIAVE] !== null ? 1 : 0)
            + (isset($canonici[LivelloEsercizi::CHIAVE]) ? 1 : 0)
            + count($canonici[LivelloSoggetti::CHIAVE] ?? [])
            + count($canonici[LivelloUnita::CHIAVE] ?? [])
            + count($canonici[LivelloTitolarita::CHIAVE] ?? [])
            + count($tabelle)
            + $quote
            + count(($canonici[LivelloSaldi::CHIAVE] ?? null)?->righe ?? []);
    }
}
