<?php

namespace App\Services\Import\Parser;

use App\Enums\RuoloAnagraficaImmobile;
use App\Helpers\MoneyHelper;
use App\Services\Import\Canonical\CanonicalImmobile;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\Foglio;
use App\Services\Import\HeaderDetector;
use App\Services\Import\Rilievo;

/**
 * Il report `elenco_unita` → soggetti, unità e **titolarità** (livelli 4, 5 e 6 del §5).
 *
 * È la stampa più ricca che Danea produce e l'unica che porti insieme il ruolo, il codice
 * fiscale e il saldo precedente. È anche quella su cui si gioca la differenza dalla
 * concorrenza: chi importa persone e unità come due elenchi separati ottiene un archivio in
 * cui nessuno possiede niente.
 *
 * ## Le conversioni di confine, tutte qui e una volta sola
 *
 * - **Ruolo**: `Pr` / `Co` / `Us` → l'enum di dominio. `Co` è *conduttore*, non *condòmino*:
 *   stessa sillaba, significato opposto.
 * - **Saldo**: virgola decimale → centesimi interi, **e segno invertito**, perché Danea usa
 *   negativo = debito e Kondomanager l'opposto.
 * - **Progressivo**: resta stringa. `016` non è `16`.
 *
 * Tutto ciò che sta a valle riceve dati già nel dominio di Kondomanager, che è la convenzione
 * non negoziabile del progetto.
 */
final class ElencoUnitaParser
{
    /** `Pr` proprietario · `Co` conduttore (inquilino) · `Us` usufruttuario. */
    private const RUOLI = [
        'pr' => RuoloAnagraficaImmobile::PROPRIETARIO,
        'co' => RuoloAnagraficaImmobile::INQUILINO,
        'us' => RuoloAnagraficaImmobile::USUFRUTTUARIO,
    ];

    /**
     * Ruoli cessati. Non diventano titolarità — il §3 esclude i ruoli storici — ma le loro
     * righe **non sono errori**: vanno riconosciute e saltate in silenzio, o l'amministratore
     * si troverebbe due errori per ogni unità che ha cambiato proprietario.
     */
    private const RUOLI_CESSATI = ['ex pr', 'ex co', 'ex us'];

    /**
     * @return array{
     *     soggetti: array<string, CanonicalSoggetto>,
     *     immobili: array<string, CanonicalImmobile>,
     *     titolarita: list<CanonicalTitolarita>,
     *     esito: EsitoVerifica
     * }
     */
    public function estrai(Foglio $foglio, int $rigaIntestazione): array
    {
        $mappa = (new HeaderDetector)->mappaColonne($foglio, $rigaIntestazione);

        $soggetti = [];
        $immobili = [];
        $titolarita = [];
        $rilievi = [];
        $righeDati = 0;

        // Un solo avviso per tutto il file quando `Interno` è vuoto ovunque: sedici avvisi
        // identici sarebbero rumore, e il rumore fa saltare anche quelli che contano.
        $internoSempreVuoto = true;

        for ($i = $rigaIntestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);
            $rigaUtente = Foglio::rigaUtente($i);

            if ($this->vuota($riga)) {
                continue;
            }

            $righeDati++;

            $ruoloGrezzo = HeaderDetector::normalizza($this->valore($riga, $mappa, 'ruolo'));

            if (in_array($ruoloGrezzo, self::RUOLI_CESSATI, true)) {
                // Il saldo di un titolare cessato non si perde: lo raccoglie il livello dei
                // saldi di apertura, che lo porta sul saldo **solidale** dell'unità (art. 63).
                // Qui non c'è titolarità da creare, e non c'è niente da segnalare.
                continue;
            }

            $chiaveImmobile = $this->chiaveImmobile($riga, $mappa);

            if ($chiaveImmobile === null) {
                $rilievi[] = Rilievo::errore(
                    'unita.chiave_incompleta',
                    'Mancano palazzina, gruppo o progressivo: non so a quale unità appartiene questa riga.',
                    'Sono le prime tre colonne del file. Se sono vuote, la riga va tolta o completata.',
                    $rigaUtente,
                );

                continue;
            }

            if (! isset(self::RUOLI[$ruoloGrezzo])) {
                $rilievi[] = Rilievo::errore(
                    'titolarita.ruolo_sconosciuto',
                    sprintf('Il ruolo «%s» non è fra quelli ammessi.', trim($this->valore($riga, $mappa, 'ruolo'))),
                    'I valori che Danea usa sono Pr (proprietario), Co (conduttore, cioè inquilino) '
                    .'e Us (usufruttuario). Correggi il file, oppure assegna il ruolo a mano qui.',
                    $rigaUtente,
                    'Ruolo',
                );

                continue;
            }

            $nome = trim($this->valore($riga, $mappa, 'denominazione'));

            if ($nome === '') {
                $rilievi[] = Rilievo::errore(
                    'soggetto.nome_assente',
                    'La riga non ha un nome: non posso creare una persona senza.',
                    'Completa la colonna Denominazione, oppure togli la riga se l\'unità è sfitta.',
                    $rigaUtente,
                    'Denominazione',
                );

                continue;
            }

            $immobili[$chiaveImmobile] ??= $this->immobile($riga, $mappa, $chiaveImmobile);

            if (trim($this->valore($riga, $mappa, 'interno')) !== '') {
                $internoSempreVuoto = false;
            }

            [$soggetto, $rilieviSoggetto] = $this->soggetto($riga, $mappa, $nome, $rigaUtente);
            $rilievi = [...$rilievi, ...$rilieviSoggetto];

            // Lo stesso proprietario compare su più unità: un soggetto solo, più titolarità.
            $soggetti[$soggetto->chiave()] ??= $soggetto;

            $titolarita[] = new CanonicalTitolarita(
                immobileRef: $chiaveImmobile,
                soggettoRef: $soggetto->chiave(),
                ruolo: self::RUOLI[$ruoloGrezzo],
                saldoPrecedenteCents: $this->saldo($riga, $mappa),
                rigaSorgente: $rigaUtente,
            );
        }

        if ($righeDati > 0 && $internoSempreVuoto) {
            $rilievi[] = Rilievo::avviso(
                'unita.interno_dedotto',
                'La colonna «Interno» è vuota in tutte le righe del file.',
                'Kondomanager richiede un interno per ogni unità: uso il progressivo di Danea. '
                .'Se i tuoi interni sono diversi, correggili dopo l\'importazione.',
            );
        }

        return [
            'soggetti' => $soggetti,
            'immobili' => $immobili,
            'titolarita' => $titolarita,
            'esito' => new EsitoVerifica($righeDati, $rilievi),
        ];
    }

    /**
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     * @return array{0: CanonicalSoggetto, 1: list<Rilievo>}
     */
    private function soggetto(array $riga, array $mappa, string $nome, int $rigaUtente): array
    {
        $rilievi = [];
        $cf = strtoupper(trim($this->valore($riga, $mappa, 'codfisc'))) ?: null;

        if ($cf === null) {
            $rilievi[] = Rilievo::avviso(
                'soggetto.cf_mancante',
                sprintf('«%s» non ha il codice fiscale.', $nome),
                'Lo riconosco per nome e cognome. Se esistono due persone con lo stesso nome, '
                .'te lo chiedo prima di unirle.',
                $rigaUtente,
                'CodFisc',
            );
        }

        // Trappola 8: due persone in una cella sola. **Non si divide in automatico**: non
        // sappiamo se «ROSSI G. / BIANCHI N.» siano due comproprietari o un nome doppio, e
        // sbagliando si creerebbe una persona che non esiste. È una decisione dell'utente,
        // ed è la stessa che il mockup mostra nella schermata di conferma.
        if (preg_match('#\s/\s#', $nome) === 1) {
            $rilievi[] = Rilievo::daDecidere(
                'soggetto.due_persone_in_una_cella',
                sprintf('«%s» sembra contenere due persone in un campo solo.', $nome),
                'Scegli «dividi in due» per creare due soggetti distinti sull\'unità, '
                .'oppure «lascia com\'è» se è il nome di una sola persona o società.',
                $rigaUtente,
                'Denominazione',
                // La chiave con cui l'anteprima registrerà la risposta. Deve coincidere con
                // `CanonicalSoggetto::chiave()`, che è ciò che il servizio di verifica cerca.
                'soggetto:'.($cf ?? 'nome:'.mb_strtolower(trim($nome))),
            );
        }

        $note = trim($this->valore($riga, $mappa, 'note'));

        // Trappola 9: la comproprietà con le quote non ha un campo nel tracciato, e vive nelle
        // note scritte a mano. È l'unica traccia di un'informazione contabilmente rilevante:
        // se la ignoriamo, si perde per sempre.
        if ($note !== '' && str_contains($note, '%')) {
            $rilievi[] = Rilievo::avviso(
                'titolarita.quota_solo_nelle_note',
                sprintf('Le note di questa riga parlano di quote: «%s».', mb_strimwidth(preg_replace('/\s+/', ' ', $note) ?? '', 0, 90, '…')),
                'Il tracciato di Danea non ha un campo per le quote di comproprietà, quindi la '
                .'importo al 100% al primo intestatario. Correggila dopo, sulla scheda dell\'unità.',
                $rigaUtente,
                'Note',
            );
        }

        $telefoni = array_values(array_filter([
            trim($this->valore($riga, $mappa, 'tel1')),
            trim($this->valore($riga, $mappa, 'tel2')),
            trim($this->valore($riga, $mappa, 'tel3')),
            trim($this->valore($riga, $mappa, 'fax')),
        ], fn (string $v) => $v !== ''));

        return [new CanonicalSoggetto(
            nome: $nome,
            codiceFiscale: $cf,
            partitaIva: trim($this->valore($riga, $mappa, 'partiva')) ?: null,
            indirizzo: trim($this->valore($riga, $mappa, 'indirizzo')) ?: null,
            cap: trim($this->valore($riga, $mappa, 'cap')) ?: null,
            comune: trim($this->valore($riga, $mappa, 'citta')) ?: null,
            provincia: trim($this->valore($riga, $mappa, 'prov')) ?: null,
            email: trim($this->valore($riga, $mappa, 'email')) ?: null,
            pec: trim($this->valore($riga, $mappa, 'pec')) ?: null,
            telefoni: $telefoni,
            recapitoSpedizioni: $this->recapitoSpedizioni($riga, $mappa),
            note: $note ?: null,
        ), $rilievi];
    }

    /**
     * Il blocco `Spedizioni:` — il domicilio per le comunicazioni, diverso dalla residenza.
     *
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     */
    private function recapitoSpedizioni(array $riga, array $mappa): ?array
    {
        $campi = [
            'denominazione' => 'spedizioni denominazione',
            'indirizzo' => 'spedizioni indirizzo',
            'cap' => 'spedizioni cap',
            'comune' => 'spedizioni citta',
            'provincia' => 'spedizioni prov',
        ];

        $recapito = [];

        foreach ($campi as $chiave => $etichetta) {
            $valore = trim($this->valore($riga, $mappa, $etichetta));

            if ($valore !== '') {
                $recapito[$chiave] = $valore;
            }
        }

        return $recapito === [] ? null : $recapito;
    }

    /**
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     */
    private function immobile(array $riga, array $mappa, string $chiave): CanonicalImmobile
    {
        [$palazzina, $gruppo, $progressivo] = explode('-', $chiave, 3);

        $interno = trim($this->valore($riga, $mappa, 'interno'));

        return new CanonicalImmobile(
            palazzina: $palazzina,
            gruppo: $gruppo,
            progressivo: $progressivo,
            piano: trim($this->valore($riga, $mappa, 'piano')) ?: null,
            // `immobili.interno` è NOT NULL: quando l'export non lo porta — e negli otto file
            // reali non lo porta mai — si usa il progressivo, che è l'unico identificativo
            // stabile che abbiamo. L'avviso lo dice, una volta per file.
            interno: $interno !== '' ? $interno : $progressivo,
            tipo: trim($this->valore($riga, $mappa, 'tipo')) ?: null,
            // Trappola 12: `Sub. cat.` vale `5,22` ed è **testo**, non un decimale. Parsarlo
            // come numero lo distrugge.
            datiCatastali: trim($this->valore($riga, $mappa, 'sub cat')) ?: null,
            note: trim($this->valore($riga, $mappa, 'note')) ?: null,
        );
    }

    /**
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     */
    private function chiaveImmobile(array $riga, array $mappa): ?string
    {
        $palazzina = trim($this->valore($riga, $mappa, 'palazzina'));
        $gruppo = trim($this->valore($riga, $mappa, 'gruppo unita'));
        $progressivo = trim($this->valore($riga, $mappa, 'progressivo'));

        // Il gruppo può legittimamente essere `0`: `empty()` qui direbbe che manca.
        if ($palazzina === '' || $gruppo === '' || $progressivo === '') {
            return null;
        }

        return implode('-', [$palazzina, $gruppo, $progressivo]);
    }

    /**
     * Il saldo precedente, in centesimi e **nella convenzione di Kondomanager**.
     *
     * Danea: negativo = debito. Kondomanager: positivo = debito. L'inversione è qui, al
     * confine, una volta sola — come la conversione in centesimi. Un secondo cambio di segno
     * a valle è lo stesso difetto del `* 100` difensivo che è costato la beta.32.
     *
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     */
    private function saldo(array $riga, array $mappa): ?int
    {
        $grezzo = trim($this->valore($riga, $mappa, 'saldo prec'));

        if ($grezzo === '') {
            return null;
        }

        return -MoneyHelper::toCents($grezzo);
    }

    /**
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     */
    private function valore(array $riga, array $mappa, string $colonna): string
    {
        $indice = $mappa[$colonna] ?? null;

        return $indice === null ? '' : (string) ($riga[$indice] ?? '');
    }

    /**
     * @param  list<mixed>  $riga
     */
    private function vuota(array $riga): bool
    {
        foreach ($riga as $cella) {
            if (trim((string) ($cella ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
