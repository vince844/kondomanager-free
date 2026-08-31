<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le categorie di fornitore iniziali, spostate dal seeder a qui.
 *
 * ## Perché una migrazione e non un seeder — è la ragione dell'intera beta
 *
 * Dalla 1.11.0-beta.9 l'amministratore può **cancellare** una categoria. Finché le righe iniziali
 * le scriveva `CategoriaFornitoreSeeder` con `firstOrCreate`, ogni esecuzione di `db:seed`
 * **avrebbe fatto risorgere ciò che era stato cancellato di proposito** — un difetto peggiore di
 * quello che curava, e già scritto in `docs/roadmap.md` (Coda 103) ragionando su tutt'altro.
 *
 * **Una migrazione ha per costruzione la semantica «una volta sola»**: Laravel la registra nella
 * tabella `migrations` e non la riesegue mai più. È esattamente ciò che serve — *queste esistono
 * all'inizio, e niente le rimette mai*. Nessuna colonna `di_sistema`, nessuna lapide, nessuna logica
 * da mantenere; e il giorno che qualcuno aggiungesse `db:seed` al percorso di aggiornamento, qui non
 * c'è più niente che possa risorgere, perché **il seeder non esiste più**.
 *
 * Il precedente è già di casa: `seed_conti_mancanti`, `seed_preferenze_notifica_di_modifica`,
 * `populate_piano_rate_capitoli_pivot` scrivono dati esattamente così.
 *
 * ## ⚠️ «Altro» torna, e non è una svista
 *
 * Il seeder ne dichiarava **otto**, ma a database ce ne sono **nove**: «Altro» è stata creata allo
 * stesso secondo delle altre — era nel seeder — e il commit `34f33ccc` l'ha tolta dal file senza
 * toglierla dai database già creati. Risultato mai notato: **un'installazione nuova aveva otto
 * categorie e una vecchia nove.** Qui le due tornano a coincidere, e si tiene «Altro» per tre
 * ragioni: ce l'hanno tutte le installazioni esistenti con la sua descrizione, è utile di per sé, ed
 * è la categoria in cui atterra naturalmente chi non trova la propria — cioè proprio il caso che
 * questa beta risolve dando il pulsante per crearne una.
 *
 * ## Idempotenza
 *
 * Guardia sulla tabella, e poi **una guardia per nome**: le installazioni esistenti hanno già queste
 * righe dal seeder e non devono ricevere doppioni. Va nel dataset di
 * `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
 *
 * ⚠️ **Nessuna installazione subisce una risurrezione al passaggio**: fino a questa beta le
 * categorie **non erano cancellabili** (non esisteva l'interfaccia), quindi chi le ha, le ha tutte.
 */
return new class extends Migration
{
    /**
     * Le categorie iniziali: le **nove storiche** più **dieci aggiunte in questa beta**.
     *
     * ## Il criterio delle dieci nuove, dichiarato perché è un giudizio e non una misura
     *
     * ⚠️ **In repository non esiste un censimento di cosa spende un condominio**: il piano dei conti
     * è una struttura patrimoniale (attivo/passivo), non un elenco di mestieri, e il condominio
     * dimostrativo ha tre voci. Quindi queste dieci non sono misurate, sono scelte — e il criterio è
     * uno solo: **spesa ricorrente a cui corrisponde un fornitore che emette fattura al condominio**,
     * cioè le righe che compaiono in un rendiconto vero.
     *
     * Per questo dentro non ci sono né i rifiuti (li paga il singolo al Comune, non il condominio a
     * un fornitore) né l'edilizia generica (è «Muratore»), e c'è invece «Utenze», che nelle nove non
     * c'era pur essendo la fattura che arriva più spesso di tutte.
     *
     * ## ⚠️ Questo non è il motivo della beta
     *
     * Aggiungerne dieci **non risolve** il problema per cui la beta esiste: qualunque elenco fisso è
     * sbagliato per qualcuno, e ne mancheranno sempre. Servono a dare un punto di partenza più
     * onesto a chi installa oggi; la funzione vera è il pulsante che permette di farsene una propria.
     */
    private const CATEGORIE = [
        ['name' => 'Elettricista',            'description' => 'Professionisti specializzati in impianti elettrici, installazioni e manutenzioni elettriche civili e industriali.'],
        ['name' => 'Idraulico',               'description' => 'Fornitori che operano nel settore termo-idraulico, tubazioni, caldaie, impianti sanitari e manutenzioni.'],
        ['name' => 'Muratore',                'description' => 'Aziende o artigiani specializzati in lavori edili, ristrutturazioni, opere murarie e manutenzioni strutturali.'],
        ['name' => 'Giardiniere',             'description' => 'Servizi di manutenzione aree verdi, potature, cura giardini, irrigazione e gestione del verde pubblico o privato.'],
        ['name' => 'Servizi di pulizia',      'description' => 'Imprese specializzate nella pulizia di spazi privati, condominiali e commerciali, ordinaria e straordinaria.'],
        ['name' => 'Sicurezza e antincendio', 'description' => 'Fornitori specializzati in sistemi di sicurezza, antincendio, manutenzioni estintori e impianti di rilevazione.'],
        ['name' => 'Ascensorista',            'description' => "Tecnici e aziende dedicate all'installazione e manutenzione di ascensori, montacarichi e piattaforme elevatrici."],
        ['name' => 'Azienda multiservizi',    'description' => 'Fornitori che offrono servizi multipli: manutenzione, pulizie, assistenza tecnica e gestione strutture.'],
        // ── Aggiunte nella 1.11.0-beta.9 ────────────────────────────────────────────────────────
        ['name' => 'Termotecnico e caldaie',       'description' => 'Conduzione e manutenzione di centrali termiche e caldaie, terzo responsabile, prove di combustione.'],
        ['name' => 'Antennista e citofonia',       'description' => 'Impianti di ricezione televisiva e satellitare, citofoni e videocitofoni: installazione e riparazione.'],
        ['name' => 'Fabbro e serramenti',          'description' => 'Cancelli e portoni automatici, serrature, inferriate, porte e infissi delle parti comuni.'],
        ['name' => 'Imbianchino',                  'description' => 'Tinteggiature di scale, androni e facciate, ripristini e opere di finitura.'],
        ['name' => 'Autospurgo',                   'description' => 'Spurgo di fognature, pozzetti e vasche, videoispezioni e disotturazioni.'],
        ['name' => 'Disinfestazione',              'description' => 'Interventi e contratti periodici contro infestanti, roditori e zanzare nelle aree comuni.'],
        ['name' => 'Portierato e custodia',        'description' => 'Imprese che forniscono il servizio di portierato, guardiania e custodia dello stabile.'],
        ['name' => 'Assicurazioni',                'description' => 'Compagnie e intermediari della polizza globale fabbricati e delle coperture accessorie.'],
        ['name' => 'Utenze',                       'description' => 'Fornitori di energia elettrica, gas e acqua per le parti comuni.'],
        ['name' => 'Studi professionali',          'description' => "Avvocati, commercialisti, tecnici e consulenti che emettono parcella al condominio, spesso con ritenuta d'acconto."],
        ['name' => 'Altro',                   'description' => 'Fornitori non rientranti nelle categorie predefinite o con specializzazioni particolari.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('categorie_fornitore')) {
            return;
        }

        $adesso = now();

        foreach (self::CATEGORIE as $categoria) {
            // Una guardia per nome, non un `insert` in blocco: chi ha già queste righe dal seeder
            // non deve ricevere doppioni, e chi ne ha cancellata una **dopo** questa migrazione non
            // deve vedersela tornare — ma quel caso non può darsi, perché la migrazione gira una
            // volta sola.
            if (DB::table('categorie_fornitore')->where('name', $categoria['name'])->exists()) {
                continue;
            }

            DB::table('categorie_fornitore')->insert([
                'name'        => $categoria['name'],
                'description' => $categoria['description'],
                'created_at'  => $adesso,
                'updated_at'  => $adesso,
            ]);
        }
    }

    /**
     * ⚠️ **Il `down()` non cancella niente, ed è deliberato.**
     *
     * Dopo questa migrazione le categorie sono dati dell'amministratore, non nostri: ne avrà
     * rinominate, cancellate e aggiunte. Cancellare «le nove che abbiamo messo» significherebbe
     * portare via anche quelle che ha rinominato, e azzerare in silenzio la categoria dei fornitori
     * che le usano (`categoria_id` è `nullOnDelete`). Un `down()` che distrugge dati dell'utente è
     * peggio di un `down()` che non fa niente.
     */
    public function down(): void
    {
        // Volutamente vuoto: vedi sopra.
    }
};
