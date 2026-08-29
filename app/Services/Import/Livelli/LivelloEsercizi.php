<?php

namespace App\Services\Import\Livelli;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatchItem;
use App\Services\CondominioService;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;

/**
 * Livello 2 — l'esercizio di gestione.
 *
 * Dipende dal condominio, ed è il primo posto in cui il **gate di integrità** (§10.6) ha
 * qualcosa da fare. La verifica non guarda il file: guarda se il condominio **esiste davvero in
 * archivio**. È la versione in miniatura del difetto che il concorrente mostra nel proprio
 * video — fatture importate mentre il piano dei conti non aveva i conti che quelle fatture
 * richiedono — e la ragione per cui questo metodo interroga il database e non il contesto di
 * lettura.
 */
final class LivelloEsercizi implements LivelloImport
{
    public const CHIAVE = 'esercizi';

    /**
     * La chiave con cui questo livello dichiara **quale gestione** ha usato.
     *
     * Non è un livello dell'importazione — è un'informazione che serve a quello dei saldi, che
     * altrimenti sceglie da sé la gestione più vecchia agganciata all'esercizio.
     */
    public const GESTIONE = 'esercizi:gestione';

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Esercizi';
    }

    public function dipendeDa(): array
    {
        return [LivelloCondominio::CHIAVE];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        // Non basta che il livello precedente dica di essere andato bene: si controlla che la
        // riga esista **adesso**, a database. È la differenza fra «il file lo dichiara» e «il
        // sistema ce l'ha», ed è tutto il senso del gate.
        if (! $condominio instanceof Condominio || ! Condominio::whereKey($condominio->getKey())->exists()) {
            $mancanti[] = new PrerequisitoMancante(
                'esercizi.condominio_mancante',
                'L\'esercizio non può entrare: il condominio a cui appartiene non è ancora in archivio.',
                'Importa prima il livello «Condominio». Se l\'hai saltato, ricomincia da lì: '
                .'un esercizio senza condominio non è collegabile a niente.',
            );
        }

        if (! $ctx->canonico(self::CHIAVE) instanceof CanonicalEsercizio) {
            $mancanti[] = new PrerequisitoMancante(
                'esercizi.dati_assenti',
                'Nessuno dei file caricati dichiara il periodo dell\'esercizio.',
                'Serve una stampa con la testata completa, che riporti la riga «Periodo: … - …». '
                .'In alternativa crea l\'esercizio a mano: le date non si possono dedurre dall\'anno.',
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var CanonicalEsercizio $dati */
        $dati = $ctx->canonico(self::CHIAVE);
        /** @var Condominio $condominio */
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        if ($dati->eStraordinario()) {
            return $this->commitStraordinario($ctx, $dati, $condominio);
        }

        $esistente = Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_inizio', $dati->dataInizio->toDateString())
            ->whereDate('data_fine', $dati->dataFine->toDateString())
            ->first();

        if ($esistente !== null) {
            return $this->risolviDuplicato($ctx, $dati, $esistente);
        }

        // `esercizi` ha un indice unico su `(condominio_id, nome)` — verificato sia su MySQL
        // sia sullo schema dei test. Un esercizio con la stessa etichetta ma **date diverse**
        // non è un duplicato: è un conflitto, e non è risolvibile da noi.
        //
        // «Salta» sarebbe la scelta peggiore possibile: userebbe un esercizio che copre un
        // periodo diverso da quello del file, e i saldi finirebbero nell'anno sbagliato senza
        // che niente lo segnali. Quindi si blocca e si chiede all'amministratore di decidere
        // sul **file**, dove l'informazione giusta ce l'ha lui.
        $omonimo = Esercizio::where('condominio_id', $condominio->id)
            ->where('nome', $dati->etichetta)
            ->first();

        if ($omonimo !== null) {
            return EsitoCommit::bloccato(Rilievo::errore(
                'esercizi.nome_gia_usato',
                sprintf(
                    'Esiste già un esercizio «%s» su questo condominio, ma copre un periodo diverso: '
                    .'dal %s al %s, mentre il file dice dal %s al %s.',
                    $dati->etichetta,
                    $omonimo->data_inizio->format('d/m/Y'),
                    $omonimo->data_fine->format('d/m/Y'),
                    $dati->dataInizio->format('d/m/Y'),
                    $dati->dataFine->format('d/m/Y'),
                ),
                'Due esercizi dello stesso condominio non possono avere lo stesso nome. Controlla '
                .'quale dei due periodi è quello giusto: se è quello del file, correggi l\'esercizio '
                .'già in archivio; se è quello in archivio, l\'importazione di questo non serve.',
            ));
        }

        // Calcolate **prima** della creazione: dopo, l'esercizio appena scritto combacerebbe
        // con sé stesso e ogni import segnalerebbe una sovrapposizione inesistente.
        $avvisi = $this->sovrapposizioni($condominio, $dati);

        $esercizio = Esercizio::create([
            'condominio_id' => $condominio->id,
            'nome' => $dati->etichetta,
            'descrizione' => $dati->tipo ? ucfirst($dati->tipo) : null,
            'data_inizio' => $dati->dataInizio->toDateString(),
            'data_fine' => $dati->dataFine->toDateString(),
            // L'esercizio importato nasce **aperto**: è quello su cui l'amministratore
            // lavorerà. Chiuderlo qui vorrebbe dire decidere al posto suo che la migrazione
            // riguarda solo il passato.
            'stato' => 'aperto',
        ]);

        // Come sopra, e per lo stesso motivo: l'applicazione crea l'esercizio **insieme** alla
        // gestione ordinaria e glielo aggancia. Un esercizio senza gestione non ha dove
        // appoggiare i saldi, e i saldi sono il livello subito dopo.
        $gestione = app(CondominioService::class)->createDefaultGestione($condominio, $esercizio);

        $ctx->risolvi(self::CHIAVE, $esercizio);
        // Quale gestione: `LivelloSaldi` prendeva la più vecchia agganciata all'esercizio
        // (`orderBy('id')`), che con due gestioni è quasi sempre quella sbagliata.
        $ctx->risolvi(self::GESTIONE, $gestione);
        $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $esercizio);

        return new EsitoCommit(creati: 1, avvisi: $avvisi);
    }

    /**
     * Un esercizio **straordinario** non è un anno contabile nuovo: è una gestione.
     *
     * ⚠️ **Verificato sullo schema Firebird di Domustudio, non dedotto.** Là l'esercizio è una
     * riga di `TCONDOMINI` con `DATAAPERTURA`/`DATACHIUSURA` proprie e una colonna booleana
     * `STRAORDINARIO`; il legame con l'ordinario è `CONDGENID`, cioè **lo stesso condominio**.
     * Da noi lo stesso concetto è una gestione `straordinaria`, che ha date proprie e un legame
     * molti-a-molti con gli esercizi — un fondo lavori pluriennale ci sta dentro per costruzione.
     *
     * **Perché non si crea un secondo esercizio**, che è quello che succedeva prima: sarebbe un
     * secondo esercizio **aperto** sullo stesso condominio, e l'invariante «al più uno aperto» è
     * quella su cui poggia `HasEsercizio::getEsercizioCorrente()`, che fa `->first()`. Misurato
     * prima della correzione: due esercizi accavallati e **una sola** gestione ordinaria
     * agganciata a entrambi, con la parola «Straordinario» finita in una descrizione testuale.
     *
     * Se un esercizio che copre quel periodo non c'è — capita importando prima lo straordinario —
     * si ricade sul percorso normale: senza un esercizio la gestione non ha dove appendersi.
     */
    private function commitStraordinario(ImportContext $ctx, CanonicalEsercizio $dati, Condominio $condominio): EsitoCommit
    {
        $ospite = Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_inizio', '<=', $dati->dataFine->toDateString())
            ->whereDate('data_fine', '>=', $dati->dataInizio->toDateString())
            // A parità di sovrapposizione vince quello aperto: è quello su cui si sta lavorando.
            ->orderByRaw("CASE WHEN stato = 'aperto' THEN 0 ELSE 1 END")
            ->orderBy('data_inizio', 'desc')
            ->first();

        if ($ospite === null) {
            return EsitoCommit::bloccato(Rilievo::errore(
                'esercizi.straordinario_senza_ordinario',
                sprintf(
                    'Questo è un esercizio straordinario (%s – %s), ma su questo condominio non '
                    .'c\'è nessun esercizio che copra quel periodo.',
                    $dati->dataInizio->format('d/m/Y'),
                    $dati->dataFine->format('d/m/Y'),
                ),
                'Uno straordinario è una gestione dentro un esercizio, non un esercizio a sé: '
                .'importa prima l\'esercizio ordinario dello stesso periodo — oppure aprilo a mano '
                .'dal condominio — e ricarica questo file.',
            ));
        }

        $gestione = app(CondominioService::class)->createDefaultGestione(
            $condominio,
            $ospite,
            'straordinaria',
            // Le date sono quelle **dello straordinario**, non dell'esercizio che lo ospita: è
            // la ragione per cui `gestioni` ha date proprie.
            $dati->dataInizio->toDateString(),
            $dati->dataFine->toDateString(),
        );

        $ctx->risolvi(self::CHIAVE, $ospite);
        $ctx->risolvi(self::GESTIONE, $gestione);
        $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_SALTATO, $ospite);

        return new EsitoCommit(saltati: 1, avvisi: [Rilievo::avviso(
            'esercizio.straordinario_in_gestione_dedicata',
            sprintf(
                'Il file è di un esercizio straordinario: l\'ho messo nella gestione straordinaria '
                .'di «%s», dentro l\'esercizio %s.',
                $condominio->nome,
                $ospite->nome,
            ),
            'Da noi ordinario e straordinario sono due gestioni dentro lo stesso esercizio, mentre '
            .'nel tuo gestionale sono due esercizi distinti. Controlla che le spese siano finite '
            .'nella gestione giusta: la separazione è quella che il rendiconto deve mostrare.',
        )]);
    }

    private function risolviDuplicato(ImportContext $ctx, CanonicalEsercizio $dati, Esercizio $esistente): EsitoCommit
    {
        $decisione = $ctx->decisione(self::CHIAVE.':'.$dati->etichetta);

        if ($decisione === null) {
            return EsitoCommit::inAttesaDiDecisione(Rilievo::daDecidere(
                'esercizi.duplicato',
                sprintf(
                    'Esiste già un esercizio con lo stesso periodo (%s – %s): «%s».',
                    $dati->dataInizio->format('d/m/Y'),
                    $dati->dataFine->format('d/m/Y'),
                    $esistente->nome,
                ),
                'Scegli «salta» per usare quello che c\'è già — è quasi sempre la scelta giusta, '
                .'perché due esercizi sullo stesso periodo sdoppierebbero i saldi.',
                chiaveDecisione: self::CHIAVE.':'.$dati->etichetta,
            ));
        }

        // ⚠️ **La gestione va garantita anche qui, non solo quando l'esercizio è nuovo.**
        //
        // `LivelloSaldi` si ferma con `saldi.gestione_mancante` se l'esercizio non ha una
        // gestione a cui appoggiare il pregresso, e fino al 29/08/2026 questo ramo — quello di
        // «l'esercizio esiste già», che è **sempre** quello della destinazione scelta a mano —
        // non ne creava nessuna. Un esercizio nato dal middleware `EnsureCondominioHasEsercizio`
        // non ne ha, quindi l'importazione arrivava fino ai saldi e lì moriva.
        //
        // **Dal file non può arrivare**, e non è una lacuna del nostro lettore: Danea non ha le
        // gestioni come le abbiamo noi. Verificato sui riparti veri — c'è **una sola** colonna
        // «Totale gestione», e la testata dice «Esercizio *ordinario*»: la distinzione fra
        // ordinario e straordinario, lì, sta sull'esercizio, non su gestioni parallele dentro
        // l'esercizio.
        //
        // Quindi si fa quello che fa il prodotto quando crea un condominio da sé
        // (`CondominioService:41`): la gestione ordinaria predefinita. Non è inventare un dato,
        // è portare l'archivio nello stato che l'applicazione produce sempre — come
        // `ensureDefaultConti` per il piano dei conti, un livello più su. La chiamata è
        // idempotente: riusa la gestione ordinaria se c'è e si limita ad agganciarla.
        $gestione = app(CondominioService::class)->createDefaultGestione($esistente->condominio, $esistente);

        $ctx->risolvi(self::CHIAVE, $esistente);
        $ctx->risolvi(self::GESTIONE, $gestione);
        $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_SALTATO, $esistente);

        return new EsitoCommit(saltati: 1);
    }

    /**
     * Un esercizio che si accavalla a uno esistente senza coincidere.
     *
     * Non blocca — il dominio può avere ragioni che non conosciamo — ma va **detto**: due
     * periodi sovrapposti sullo stesso condominio sono quasi sempre il segno di un export
     * fatto con il filtro di date sbagliato, e ci si accorge solo quando i riparti non tornano.
     *
     * @return list<Rilievo>
     */
    private function sovrapposizioni(Condominio $condominio, CanonicalEsercizio $dati): array
    {
        $sovrapposto = Esercizio::where('condominio_id', $condominio->id)
            ->whereDate('data_inizio', '<=', $dati->dataFine->toDateString())
            ->whereDate('data_fine', '>=', $dati->dataInizio->toDateString())
            ->first();

        if ($sovrapposto === null) {
            return [];
        }

        return [Rilievo::avviso(
            'esercizi.sovrapposto',
            sprintf(
                'Il periodo %s – %s si accavalla con l\'esercizio «%s» già presente.',
                $dati->dataInizio->format('d/m/Y'),
                $dati->dataFine->format('d/m/Y'),
                $sovrapposto->nome,
            ),
            'Lo importo lo stesso. Controlla che la stampa non sia stata esportata con un filtro '
            .'di date diverso da quello dell\'esercizio.',
        )];
    }
}
