<?php

namespace App\Services\Import\Livelli;

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatchItem;
use App\Models\Palazzina;
use App\Models\TipologiaImmobile;
use App\Services\Import\Canonical\CanonicalImmobile;
use App\Services\Import\EsitoCommit;
use App\Services\Import\ImportContext;
use App\Services\Import\LivelloImport;
use App\Services\Import\PrerequisitoMancante;
use App\Services\Import\Rilievo;

/**
 * Livello 5 — le unità immobiliari.
 *
 * ## Due vocabolari che non coincidono
 *
 * Danea scrive `Appartamento`; il nostro elenco di tipologie ha `Abitazione`, `Box`, `Garage`,
 * `Cantina` e altre ventisei voci, ma **non** «Appartamento». Le corrispondenze ovvie si fanno,
 * le altre no: una tipologia inventata è peggio di una tipologia mancante, perché sparisce
 * dalla vista e resta sbagliata.
 *
 * ## Le palazzine si importano, non si buttano
 *
 * `palazzine` chiede solo `condominio_id` e `name`: costa quasi niente crearle, e conserva
 * un'informazione che altrimenti finirebbe in una nota di testo.
 */
final class LivelloUnita implements LivelloImport
{
    public const CHIAVE = 'unita';

    /**
     * Le equivalenze fra il vocabolario di Danea e il nostro. Solo quelle di cui si è certi:
     * il resto resta senza tipologia, con un avviso.
     */
    private const TIPOLOGIE = [
        'appartamento' => 'Abitazione',
        'abitazione' => 'Abitazione',
        'box' => 'Box',
        'garage' => 'Garage',
        'cantina' => 'Cantina',
        'negozio' => 'Negozio',
        'ufficio' => 'Ufficio',
        'magazzino' => 'Magazzino',
        'posto auto' => 'Posto auto',
        'soffitta' => 'Soffitta',
        'sottotetto' => 'Sottotetto',
        'taverna' => 'Taverna',
    ];

    public function chiave(): string
    {
        return self::CHIAVE;
    }

    public function etichetta(): string
    {
        return 'Unità immobiliari';
    }

    public function dipendeDa(): array
    {
        return [LivelloCondominio::CHIAVE];
    }

    public function verificaPrerequisiti(ImportContext $ctx): array
    {
        $mancanti = [];

        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        if (! $condominio instanceof Condominio || ! Condominio::whereKey($condominio->getKey())->exists()) {
            $mancanti[] = new PrerequisitoMancante(
                'unita.condominio_mancante',
                'Le unità non possono entrare: il condominio a cui appartengono non è in archivio.',
                'Importa prima il livello «Condominio».',
            );
        }

        $unita = $ctx->canonico(self::CHIAVE);

        if (! is_array($unita) || $unita === []) {
            $mancanti[] = new PrerequisitoMancante(
                'unita.dati_assenti',
                'Nessuno dei file caricati contiene le unità immobiliari.',
                'Serve l\'export «Elenco unità» di Danea, oppure il modello «Unità immobiliari».',
                // Un file che non c'è non è un'incoerenza: è una scelta di chi importa.
                bloccante: false,
            );
        }

        return $mancanti;
    }

    public function commit(ImportContext $ctx): EsitoCommit
    {
        /** @var array<string, CanonicalImmobile> $unita */
        $unita = $ctx->canonico(self::CHIAVE);
        /** @var Condominio $condominio */
        $condominio = $ctx->risolto(LivelloCondominio::CHIAVE);

        $creati = 0;
        $saltati = 0;
        $avvisi = [];
        $risolti = [];
        $tipiNonMappati = [];

        foreach ($unita as $chiave => $dati) {
            $palazzina = $this->palazzina($condominio, $dati->palazzina);

            $esistente = Immobile::where('condominio_id', $condominio->id)
                ->where('interno', mb_strtoupper($dati->interno ?? ''))
                ->when($palazzina !== null, fn ($q) => $q->where('palazzina_id', $palazzina->id))
                ->first();

            if ($esistente !== null) {
                $risolti[$chiave] = $esistente;
                $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_SALTATO, $esistente);
                $saltati++;

                continue;
            }

            $tipologia = $this->tipologia($dati->tipo);

            if ($dati->tipo !== null && $tipologia === null) {
                $tipiNonMappati[mb_strtolower($dati->tipo)] = $dati->tipo;
            }

            $immobile = Immobile::create([
                'condominio_id' => $condominio->id,
                'palazzina_id' => $palazzina?->id,
                'tipologia_id' => $tipologia?->id,
                'nome' => $dati->denominazione(),
                // `nome` e `descrizione` sono **NOT NULL** nella migrazione, e la schermata di
                // creazione li valida entrambi come `required`. Il database di sviluppo di
                // questa macchina li ha nullable — è stato alterato fuori dalle migrazioni — e
                // per questo un import che li ometteva passava qui e sarebbe fallito su ogni
                // installazione nuova. L'ha trovato lo schema dei test, che è costruito dalle
                // migrazioni e quindi dice la verità.
                'descrizione' => $dati->descrizione(),
                // La schermata di creazione fa `strtoupper` sull'interno. Se l'import non lo
                // facesse, la stessa unità creata a mano e importata risulterebbe diversa, e
                // il riconoscimento dei duplicati fallirebbe proprio fra le due strade che
                // devono incontrarsi.
                'interno' => mb_strtoupper($dati->interno ?? ''),
                'piano' => $dati->piano,
                'subalterno_catasto' => $dati->datiCatastali,
                'note' => $dati->note,
                'attivo' => true,
            ]);

            $risolti[$chiave] = $immobile;
            $ctx->registra(self::CHIAVE, ImportBatchItem::AZIONE_CREATO, $immobile);
            $creati++;
        }

        // Un avviso per tipologia sconosciuta, non uno per unità: sedici righe identiche
        // sarebbero rumore, e il rumore fa saltare anche gli avvisi che contano.
        foreach ($tipiNonMappati as $tipo) {
            $avvisi[] = Rilievo::avviso(
                'unita.tipologia_non_mappata',
                sprintf('La tipologia «%s» non esiste nell\'elenco di Kondomanager.', $tipo),
                'Le unità entrano senza tipologia: puoi assegnarla dopo, oppure aggiungere la voce '
                .'all\'elenco delle tipologie. Inventarne una equivalente sarebbe peggio, perché '
                .'sparirebbe dalla vista restando sbagliata.',
            );
        }

        $ctx->risolviMolti(self::CHIAVE, $risolti);

        return new EsitoCommit(creati: $creati, saltati: $saltati, avvisi: $avvisi);
    }

    private function palazzina(Condominio $condominio, string $nome): ?Palazzina
    {
        if (trim($nome) === '') {
            return null;
        }

        return Palazzina::firstOrCreate(
            ['condominio_id' => $condominio->id, 'name' => $nome],
        );
    }

    private function tipologia(?string $tipo): ?TipologiaImmobile
    {
        if ($tipo === null || trim($tipo) === '') {
            return null;
        }

        $nome = self::TIPOLOGIE[mb_strtolower(trim($tipo))] ?? null;

        if ($nome === null) {
            return null;
        }

        return TipologiaImmobile::whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])->first();
    }
}
