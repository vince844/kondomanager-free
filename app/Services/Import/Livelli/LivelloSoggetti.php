<?php

namespace App\Services\Import\Livelli;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\ImportBatchItem;
use App\Services\Import\Canonical\CanonicalSoggetto;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\RicercaEsistenti;
use App\Services\Import\Rilievo;

/**
 * Livello 4 — le persone.
 *
 * Non dipende dal condominio: in Kondomanager `anagrafiche` **non ha** `condominio_id`, i
 * soggetti sono globali e il legame con l'immobile lo tiene la pivot. È una differenza di
 * modello rispetto a Danea che vale la pena conoscere: lo stesso proprietario che possiede
 * unità in tre condomìni diversi qui è **una persona sola**, non tre.
 *
 * ## I tre vincoli di schema che decidono il comportamento
 *
 * `anagrafiche` ha `indirizzo` NOT NULL e indici unici su `codice_fiscale`, `email` e `pec`.
 * Ognuno dei tre produce un comportamento diverso, e nessuno dei tre produce un errore SQL:
 *
 * - **codice fiscale** già presente → è la stessa persona: decisione dell'utente;
 * - **email o PEC** già usate da un **altro** soggetto → il caso della coppia che condivide
 *   l'indirizzo di posta. Il soggetto entra **senza** quel recapito, con un avviso che dice a
 *   chi è rimasto. Perderebbe più informazione rifiutare l'intera riga;
 * - **indirizzo** mancante → errore di riga, perché lo schema lo richiede e una stringa vuota
 *   sarebbe un dato falso che nessuno correggerà.
 */
final class LivelloSoggetti implements LivelloImport
{
    public const CHIAVE = 'soggetti';

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Persone';
    }

    public function dipendeDa(): array
    {
        return [];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $soggetti = $ctx->canonico(self::CHIAVE);

        if (! is_array($soggetti) || $soggetti === []) {
            return [new PrerequisitoMancante(
                'soggetti.dati_assenti',
                'Nessuno dei file caricati contiene le persone.',
                'Serve l\'export «Elenco unità» di Danea, che è l\'unico a portare insieme nomi, '
                .'codici fiscali e ruoli. In alternativa compila il modello «Anagrafiche».',
            )];
        }

        return [];
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var array<string, CanonicalSoggetto> $soggetti */
        $soggetti = $ctx->canonico(self::CHIAVE);

        $rilievi = [];
        /** @var list<array{0: string, 1: string, 2: CanonicalSoggetto, 3: Anagrafica|null}> $daScrivere
         *  [chiave, azione ('crea'|'unisci'|'salta'), dati canonici, esistente o null]
         */
        $daScrivere = [];

        // Primo giro: solo lettura e decisioni. Nessuna riga tocca il database — trovato dalla
        // revisione avversariale che il vecchio codice scriveva `Anagrafica::create()` **dentro**
        // questo stesso ciclo, prima di sapere se una riga successiva avrebbe bloccato il
        // livello. La transazione di `ImportRunner` avvolge l'intero `commit()`, ma **commit su
        // ritorno normale**: un `EsitoCommit::inAttesaDiDecisione` non è un'eccezione, quindi le
        // persone già scritte restavano in archivio anche con il livello segnato «non riuscito» —
        // esattamente il contrario di quanto la classe promette («se salta qui, il livello non è
        // entrato affatto», `ImportRunner`). Al tentativo successivo quelle persone risultavano
        // già presenti, e il livello le segnalava come duplicati che nessuno aveva mai scelto.
        foreach ($soggetti as $chiave => $dati) {
            $esistente = (new RicercaEsistenti)->soggetto($dati);

            if ($esistente !== null) {
                $decisione = $ctx->decisione(self::CHIAVE.':'.$chiave);

                if ($decisione === null) {
                    $rilievi[] = Rilievo::daDecidere(
                        'soggetto.duplicato',
                        sprintf('«%s» esiste già in archivio%s.', $dati->nome, $dati->haCodiceFiscale() ? ' con lo stesso codice fiscale' : ' con lo stesso nome'),
                        'Scegli «unisci» per aggiornare quello esistente con i dati del file, '
                        .'oppure «salta» per lasciarlo com\'è e collegarci comunque le unità.',
                        // Senza questa chiave il rilievo è irrisolvibile: l'anteprima non sa a
                        // quale decisione appartiene, e il pulsante che lo chiuderebbe non
                        // esiste da nessuna parte.
                        chiaveDecisione: self::CHIAVE.':'.$chiave,
                    );

                    continue;
                }

                $daScrivere[] = [$chiave, $decisione === 'unisci' ? 'unisci' : 'salta', $dati, $esistente];

                continue;
            }

            if (trim((string) $dati->indirizzo) === '') {
                $rilievi[] = Rilievo::errore(
                    'soggetto.indirizzo_assente',
                    sprintf('«%s» non ha un indirizzo, e Kondomanager lo richiede.', $dati->nome),
                    'È l\'indirizzo a cui partono le comunicazioni. Completalo nel file e ricarica, '
                    .'oppure crea la persona a mano.',
                );

                continue;
            }

            $daScrivere[] = [$chiave, 'crea', $dati, null];
        }

        if ($rilievi !== []) {
            return EsitoCommit::inAttesaDiDecisione(...$rilievi);
        }

        // Secondo giro: si scrive solo ora che si sa che l'intero livello passa. L'ordine è
        // quello del file, quindi il controllo email/PEC duplicati dentro campiValorizzati()
        // — che interroga il database — continua a vedere le persone appena create in questo
        // stesso giro, esattamente come prima: non è un comportamento nuovo, solo posticipato.
        $creati = 0;
        $uniti = 0;
        $saltati = 0;
        $avvisi = [];
        $risolti = [];

        // Il condominio serve per il pivot `anagrafica_condominio` — vedi `collegaAlCondominio()`.
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        foreach ($daScrivere as [$chiave, $azione, $dati, $esistente]) {
            if ($azione === 'crea') {
                $anagrafica = Anagrafica::create($this->campiValorizzati($dati, null, $avvisi));
                $this->collegaAlCondominio($anagrafica, $condominio);
                $risolti[$chiave] = $anagrafica;
                $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $anagrafica);
                $creati++;

                continue;
            }

            if ($azione === 'unisci') {
                $esistente->fill($this->campiValorizzati($dati, $esistente, $avvisi))->save();
                $uniti++;
            } else {
                $saltati++;
            }

            // Vale per **entrambi** i rami, e «salta» è quello che si dimentica: la decisione
            // dell'utente riguarda i *dati* della persona («lascialo com'è»), non la sua
            // appartenenza allo stabile — le unità gli vengono collegate lo stesso, come dice
            // il testo del rilievo. Senza questa riga, chi sceglie «salta» ottiene un
            // proprietario che non può pagare.
            $this->collegaAlCondominio($esistente, $condominio);

            $risolti[$chiave] = $esistente;
            $ctx->registra(
                self::CHIAVE,
                $azione === 'unisci' ? ImportBatchItem::AZIONE_UNITO : ImportBatchItem::AZIONE_SALTATO,
                $esistente,
            );
        }

        $ctx->risolviMolti(self::CHIAVE, $risolti);

        return new EsitoCommit(creati: $creati, uniti: $uniti, saltati: $saltati, avvisi: $avvisi);
    }

    /**
     * I campi da scrivere, con i recapiti unici già disinnescati.
     *
     * @param  list<Rilievo>  $avvisi  raccolti per riferimento
     * @return array<string, mixed>
     */
    /**
     * Registra che questa persona è **di questo stabile**.
     *
     * La persona è globale — `anagrafiche` non ha `condominio_id`, ed è la premessa di tutto
     * questo livello — ma la sua **appartenenza a un condominio** non lo è, e la tiene il pivot
     * `anagrafica_condominio`.
     *
     * ## Perché è un blocco di rilascio, e non una rifinitura
     *
     * Fino alla beta.48 l'importatore questo pivot non lo scriveva. Conseguenza: un
     * amministratore importava il suo stabile e **non poteva registrare un solo incasso**,
     * perché `StoreIncassoRateRequest` chiedeva proprio quel pivot per validare il pagante.
     * Misurato l'11/08/2026 su «Le Terrazze», entrato con la beta.47: 16 anagrafiche con unità,
     * zero collegate.
     *
     * La validazione è stata allargata nella stessa beta — accetta anche chi possiede
     * un'unità qui — quindi da sola sbloccava già tutto. Questo metodo serve comunque, perché
     * il pivot è ciò che il resto del gestionale legge per sapere chi appartiene al
     * condominio, e perché il dato va scritto **dove nasce il fatto**: la persona diventa
     * condòmina di questo stabile qui, non altrove.
     *
     * `syncWithoutDetaching` e non `attach`: un soggetto già collegato non deve produrre una
     * riga doppia, e uno collegato ad **altri** condomìni non deve perderli — è la persona
     * unica che possiede unità in tre stabili, il caso che la premessa di questo livello
     * dichiara.
     */
    private function collegaAlCondominio(Anagrafica $anagrafica, ?Condominio $condominio): void
    {
        if (! $condominio) {
            return; // Il gate dei prerequisiti non ci fa arrivare qui, ma non si scrive al buio.
        }

        $anagrafica->condomini()->syncWithoutDetaching([$condominio->id]);
    }

    private function campiValorizzati(CanonicalSoggetto $dati, ?Anagrafica $esistente, array &$avvisi): array
    {
        $campi = [
            'nome' => $dati->nome,
            'codice_fiscale' => $dati->codiceFiscale,
            'indirizzo' => $dati->indirizzo,
            'telefono' => $dati->telefoni[0] ?? null,
            'cellulare' => $dati->telefoni[1] ?? null,
            'note' => $this->note($dati),
        ];

        foreach (['email' => $dati->email, 'pec' => $dati->pec] as $colonna => $valore) {
            if ($valore === null || $valore === '') {
                continue;
            }

            $occupante = Anagrafica::where($colonna, $valore)
                ->when($esistente !== null, fn ($q) => $q->whereKeyNot($esistente->getKey()))
                ->first();

            if ($occupante === null) {
                $campi[$colonna] = $valore;

                continue;
            }

            // Il caso della coppia: due condòmini con lo stesso indirizzo di posta. Lo schema
            // ne ammette uno solo. Rifiutare l'intera riga perderebbe molto di più che
            // rinunciare al recapito duplicato — ma va detto a chi guarda, e va detto **a chi
            // è rimasto**, altrimenti l'amministratore scriverà alla persona sbagliata
            // credendo di scrivere a entrambe.
            $avvisi[] = Rilievo::avviso(
                'soggetto.recapito_gia_usato',
                sprintf(
                    'La %s «%s» risulta già di «%s»: l\'ho lasciata a lui e importato «%s» senza.',
                    $colonna === 'pec' ? 'PEC' : 'email',
                    $valore,
                    $occupante->nome,
                    $dati->nome,
                ),
                'Kondomanager tiene un recapito per persona. Se sono due persone diverse che '
                .'condividono la casella, aggiungine una seconda dopo l\'importazione.',
            );
        }

        return array_filter($campi, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Le note del file più il recapito di spedizione, che nel nostro schema non ha un posto suo.
     *
     * Metterlo nelle note è un ripiego dichiarato, non una soluzione: è il domicilio per le
     * comunicazioni, diverso dalla residenza, e finché non ha una colonna sua **conservarlo
     * come testo è meglio che buttarlo**.
     */
    private function note(CanonicalSoggetto $dati): ?string
    {
        $pezzi = [];

        if ($dati->note !== null && trim($dati->note) !== '') {
            $pezzi[] = trim($dati->note);
        }

        if ($dati->recapitoSpedizioni !== null) {
            $pezzi[] = 'Recapito per le comunicazioni (importato): '
                .implode(' ', array_filter($dati->recapitoSpedizioni));
        }

        if (count($dati->telefoni) > 2) {
            $pezzi[] = 'Altri recapiti telefonici: '.implode(', ', array_slice($dati->telefoni, 2));
        }

        return $pezzi === [] ? null : implode("\n", $pezzi);
    }

}
