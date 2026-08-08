<?php

namespace App\Services\Import\Livelli;

use App\Models\Condominio;
use App\Services\CondominioService;
use App\Models\ImportBatchItem;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\RicercaEsistenti;
use App\Services\Import\Rilievo;

/**
 * Livello 1 — il condominio.
 *
 * È il primo di tutti e non dipende da niente, ma è quello con il vincolo di schema più stretto:
 * **`condomini.codice_fiscale` è UNIQUE a database**. Da lì discende tutto il comportamento di
 * questa classe, e una conseguenza che va detta all'interfaccia prima che all'utente.
 */
final class LivelloCondominio implements LivelloImport
{
    public const CHIAVE = 'condominio';

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Condominio';
    }

    public function dipendeDa(): array
    {
        return [];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        if (! $ctx->canonico(self::CHIAVE) instanceof CanonicalCondominio) {
            return [new PrerequisitoMancante(
                'condominio.dati_assenti',
                'Nessuno dei file caricati contiene la testata con il nome del condominio.',
                'Carica almeno una stampa con l\'intestazione — il riparto consuntivo, i movimenti '
                .'o le rate versate — oppure compila il modello «Condominio» di Kondomanager.',
            )];
        }

        return [];
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var CanonicalCondominio $dati */
        $dati = $ctx->canonico(self::CHIAVE);

        $esistente = (new RicercaEsistenti)->condominio($dati);

        // `condomini.indirizzo` è NOT NULL senza default: lo schema considera l'indirizzo un
        // dato obbligatorio, e a ragione — è quello a cui si spediscono le convocazioni.
        //
        // Le due scorciatoie sono entrambe peggiori del rifiuto. Passare `null` fa fallire
        // l'insert con un errore di database che l'amministratore non può interpretare e noi
        // non possiamo spiegare a posteriori; scrivere stringa vuota mette in archivio un
        // condominio senza indirizzo che **nessuno correggerà mai**, perché niente lo segnala.
        if ($esistente === null && trim((string) $dati->indirizzo) === '') {
            return EsitoCommit::bloccato(Rilievo::errore(
                'condominio.indirizzo_assente',
                sprintf('La testata del file non contiene l\'indirizzo di «%s».', $dati->nome),
                'Kondomanager richiede l\'indirizzo del condominio: è quello a cui partono le '
                .'convocazioni. Aggiungilo con il modello «Condominio», oppure crea il condominio '
                .'a mano e ricarica il file: lo ritroverò dal codice fiscale.',
            ));
        }

        if ($esistente === null) {
            $condominio = Condominio::create([
                'nome' => $dati->nome,
                'codice_fiscale' => $dati->codiceFiscale,
                'indirizzo' => $dati->indirizzo,
                'cap' => $dati->cap,
                'comune' => $dati->comune,
                'provincia' => $dati->provincia,
            ]);

            // Il condominio creato dall'applicazione non è mai nudo: `CondominioService`
            // gli costruisce anche il piano dei conti standard, Fondo Passate Gestioni
            // compreso. Un condominio importato senza sarebbe in uno stato che il prodotto
            // non produce mai — e ogni cosa a valle (fatture, saldi, riparti) darebbe per
            // scontati conti che non ci sono. È lo stesso difetto che rimproveriamo al
            // concorrente, visto dal lato di chi scrive invece che di chi valida.
            //
            // Si riusa il servizio dell'applicazione, non se ne scrive un secondo:
            // `ensureDefaultConti` esce subito se i conti esistono già.
            app(CondominioService::class)->ensureDefaultConti($condominio);

            $ctx->risolvi(self::CHIAVE, $condominio);
            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $condominio);
            $ctx->batch->update(['condominio_id' => $condominio->id]);

            return new EsitoCommit(creati: 1);
        }

        return $this->risolviDuplicato($ctx, $dati, $esistente);
    }

    /**
     * Il duplicato non è un errore: è una decisione che non possiamo prendere noi (§10.3).
     */
    private function risolviDuplicato(ImportContext $ctx, CanonicalCondominio $dati, Condominio $esistente): EsitoCommit
    {
        $decisione = $ctx->decisione(self::CHIAVE.':'.$dati->chiave());

        if ($decisione === null) {
            return EsitoCommit::inAttesaDiDecisione(Rilievo::daDecidere(
                'condominio.duplicato',
                sprintf(
                    'In archivio esiste già «%s»%s.',
                    $esistente->nome,
                    $esistente->codice_fiscale ? ' con lo stesso codice fiscale' : ' con lo stesso nome',
                ),
                'Scegli cosa fare: «unisci» aggiorna i dati di quello esistente con quelli del file, '
                .'«salta» lo lascia com\'è e usa quello per il resto dell\'importazione.',
                // Senza questa chiave il rilievo è irrisolvibile: l'anteprima non sa a quale
                // decisione appartiene, e il pulsante che lo chiuderebbe non esiste da nessuna
                // parte. È il vicolo cieco che si apriva alla seconda importazione.
                chiaveDecisione: self::CHIAVE.':'.$dati->chiave(),
            ));
        }

        // `crea_nuovo` esiste per gli altri livelli e **qui non è offribile**: lo schema ha un
        // indice unico sul codice fiscale, quindi un secondo condominio con lo stesso codice non
        // è scrivibile. Meglio dirlo che lasciar fallire l'insert con un errore di database —
        // che l'amministratore non può interpretare e noi non possiamo spiegare a posteriori.
        if ($decisione === 'crea_nuovo' && $dati->codiceFiscale !== null) {
            return EsitoCommit::inAttesaDiDecisione(Rilievo::errore(
                'condominio.cf_gia_usato',
                sprintf('Il codice fiscale %s è già usato da «%s».', $dati->codiceFiscale, $esistente->nome),
                'Due condomìni non possono avere lo stesso codice fiscale. Unisci a quello esistente, '
                .'oppure correggi il codice fiscale nel file prima di ricaricarlo.',
            ));
        }

        if ($decisione === 'unisci') {
            $esistente->fill(array_filter([
                'nome' => $dati->nome,
                'indirizzo' => $dati->indirizzo,
                'cap' => $dati->cap,
                'comune' => $dati->comune,
                'provincia' => $dati->provincia,
            ], fn ($v) => $v !== null && $v !== ''))->save();
        }

        $ctx->risolvi(self::CHIAVE, $esistente);
        $ctx->batch->update(['condominio_id' => $esistente->id]);

        $azione = $decisione === 'unisci'
            ? ImportBatchItem::AZIONE_UNITO
            : ImportBatchItem::AZIONE_SALTATO;

        $ctx->registra(self::CHIAVE, $azione, $esistente);

        return $decisione === 'unisci'
            ? new EsitoCommit(uniti: 1)
            : new EsitoCommit(saltati: 1);
    }

}
