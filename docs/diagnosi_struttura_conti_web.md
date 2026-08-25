<!-- verifica-documentazione -->
> **Stato:** Documento di progetto per la 1.10 — verificato il 21/08/2026 su **1.10.0-beta.63**.
> Descrive l'architettura per esporre la diagnosi di `php artisan kondomanager:verifica-struttura-conti`
> nell'interfaccia web (widget nel piano dei conti e contatore in Inbox Dashboard) per gli utenti
> su hosting condiviso o ambienti senza accesso alla riga di comando.
> Riferimenti collegati: [`roadmap.md`](roadmap.md) (Coda 64), [`flusso_di_lavoro_rilascio.md`](flusso_di_lavoro_rilascio.md).
<!-- /verifica-documentazione -->

# Diagnosi Struttura Conti da Web — Sola Lettura e Cura Guidata

## 1. Il problema: diagnosi solo da console su un software self-hosted

KondoManager è un gestionale web self-hosted distribuito sia a chi amministra macchine dedicate sia
a professionisti che usano **hosting condivisi** (cPanel, Plesk, hosting senza accesso SSH) o
installazioni dove l'amministratore interagisce esclusivamente tramite browser.

La versione **1.10.0-beta.51** ha introdotto il comando:
```bash
php artisan kondomanager:verifica-struttura-conti [--condominio=ID]
```

Il comando svolge una funzione diagnostica vitale:
1. **Identifica le voci oltre il secondo livello** (voci fuori struttura create nelle versioni < 1.9.1
   quando il menu padre permetteva di selezionare sottoconti come padri).
2. **Identifica i rami di piano rate congelati a € 0,00** pur avendo voci con preventivo sotto
   (il caso in cui le rate emesse hanno chiesto meno del dovuto ai condòmini senza che nessun controllo
   contabile tradizionale segnalasse lo sbilancio).

**Il limite:** Chi è su hosting condiviso senza console non può lanciare questo comando. La guida
in-app ([`OperazioniContiGuide.vue:49`](../resources/js/components/guides/OperazioniContiGuide.vue)) nomina il comando Artisan, ma per questi utenti
rappresenta un vicolo cieco.

---

## 2. Obiettivi e Principi Guida

1. **Stessa identica logica tra CLI e Web (Zero Divergenza):**
   La logica di diagnosi deve risiedere in un unico Service di backend (`DiagnosiStrutturaContiService`).
   Il comando Artisan e i Controller HTTP interrogano la stessa identica classe.
2. **Nessuna riparazione automatica cieca (L'ultima decisione spetta all'amministratore):**
   Come stabilito nella beta.51, rigenerare un piano rate o cancellare/spostare voci tocca le rate
   e le scritture contabili. Il sistema deve dire **se, dove e cosa fare**, offrendo collegamenti
   diretti per eseguire l'azione correttiva a video.
3. **Visibilità contestuale (Il segnale dove si agisce):**
   - **Nel Piano dei Conti ([`ContiNew.vue`](../resources/js/pages/gestionale/pianiDeiConti/conti/ContiNew.vue)):** Widget/Alert intelligente che elenca le anomalie del condominio selezionato con link di selezione rapida della voce nell'albero.
   - **In Dashboard ([`Dashboard.vue`](../resources/js/pages/gestionale/dashboard/Dashboard.vue)):** Task in Inbox Operativa con contatore delle anomalie per catturare l'attenzione prima dell'emissione di nuovi piani rate.

---

## 3. Architettura Tecnica

### 3.1 Service Layer Condiviso (`App\Services\Gestionale\DiagnosiStrutturaContiService`)

Estrae la logica pura da `VerificaStrutturaContiCommand.php` in un service riusabile e testabile:

```php
namespace App\Services\Gestionale;

use App\Models\Gestionale\Conto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DiagnosiStrutturaContiService
{
    /**
     * Voci con profondità >= 3 (hanno sia un padre che un nonno).
     *
     * @return Collection<int, object{id: int, nome: string, codice: string|null, importo: int, padre: string, nonno: string}>
     */
    public function vociOltreIlSecondoLivello(int $condominioId): Collection
    {
        return DB::table('conti as c')
            ->join('conti as p', 'p.id', '=', 'c.parent_id')
            ->join('conti as n', 'n.id', '=', 'p.parent_id')
            ->join('piani_conti as pc', 'pc.id', '=', 'c.piano_conto_id')
            ->where('pc.condominio_id', $condominioId)
            ->orderBy('n.nome')->orderBy('p.nome')->orderBy('c.nome')
            ->select([
                'c.id', 'c.nome', 'c.codice', 'c.importo',
                'p.nome as padre', 'n.nome as nonno',
            ])
            ->get();
    }

    /**
     * Rami di piano rate con pivot a 0 che contengono foglie con preventivo > 0.
     * Esclude i casi legittimi (sposta spesa tracciato in budget_movements o capitoli senza budget).
     *
     * @return list<array{piano_id: int, piano: string, stato: string, conto_id: int, voce: string, note: string|null, mancante: int}>
     */
    public function ramiFinanziatiAZero(int $condominioId): array
    {
        $righe = DB::table('piano_rate_capitoli as prc')
            ->join('piani_rate as pr', 'pr.id', '=', 'prc.piano_rate_id')
            ->join('conti as c', 'c.id', '=', 'prc.conto_id')
            ->where('pr.condominio_id', $condominioId)
            ->where('prc.importo', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('budget_movements as bm')
                ->whereColumn('bm.source_conto_id', 'prc.conto_id')
                ->whereColumn('bm.piano_rate_id', 'prc.piano_rate_id'))
            ->orderBy('pr.id')
            ->select([
                'pr.id as piano_id',
                'prc.conto_id',
                'prc.note',
                'pr.nome as piano',
                'pr.stato',
                'c.nome as voce',
            ])
            ->get();

        $trovati = [];

        foreach ($righe as $riga) {
            $conto = Conto::find($riga->conto_id);
            if (! $conto) {
                continue;
            }

            $mancante = $this->sommaDelleFoglie($conto);
            if ($mancante <= 0) {
                continue;
            }

            $trovati[] = [
                'piano_id'  => $riga->piano_id,
                'piano'     => $riga->piano,
                'stato'     => $riga->stato,
                'conto_id'  => $riga->conto_id,
                'voce'      => $riga->voce,
                'note'      => $riga->note,
                'mancante'  => $mancante,
            ];
        }

        return $trovati;
    }

    /**
     * Calcolo leggero per contatori e indicatori Inbox.
     */
    public function contaSegnali(int $condominioId): int
    {
        return $this->vociOltreIlSecondoLivello($condominioId)->count()
            + count($this->ramiFinanziatiAZero($condominioId));
    }

    /**
     * Somma ricorsiva del preventivo delle foglie.
     */
    public function sommaDelleFoglie(Conto $conto): int
    {
        $figli = $conto->sottoconti;

        if ($figli->isEmpty()) {
            return (int) $conto->importo;
        }

        $totale = 0;
        foreach ($figli as $figlio) {
            $totale += $this->sommaDelleFoglie($figlio);
        }

        return $totale;
    }
}
```

---

### 3.2 Refactoring del Comando CLI (`VerificaStrutturaContiCommand.php`)

Il comando CLI inietta `DiagnosiStrutturaContiService` e delega l'estrazione dati al service:
- Mantiene l'output formattato per terminale (`$this->table`, colori, istruzioni operative).
- Non contiene più logica SQL diretta.

---

### 3.3 Integrazione Web & Frontend

#### A. Controller Piano dei Conti (`PianoContiController.php` / `ContoController.php`)
Nei controller che servono la vista del piano dei conti ([`ContiNew.vue`](../resources/js/pages/gestionale/pianiDeiConti/conti/ContiNew.vue)):
- Inietta `DiagnosiStrutturaContiService`.
- Passa la prop `diagnosiStruttura`:
  ```php
  'diagnosiStruttura' => [
      'fuori_struttura' => $diagnosiService->vociOltreIlSecondoLivello($condominio->id),
      'rami_a_zero'      => $diagnosiService->ramiFinanziatiAZero($condominio->id),
      'totale_segnali'   => $diagnosiService->contaSegnali($condominio->id),
  ]
  ```

#### B. Componente Alert/Widget nel Piano dei Conti
In `ContiNew.vue` (o come componente dedicato `WidgetDiagnosiStruttura.vue`):
- Compare **solo se `diagnosiStruttura.totale_segnali > 0`**.
- Sezione **Voci fuori struttura (Ambra)**:
  - Elenco voci con padre e nonno.
  - Pulsante/link *"Seleziona nell'albero"* che valorizza `selectedId` per focalizzare subito la voce ed eseguire lo spostamento o eliminazione.
- Sezione **Voci congelate a zero (Rosso)**:
  - Tabella con Piano, Stato, Voce, Importo Mancante (`€ X.XXX,XX`).
  - Istruzioni operative contestualizzate allo stato del piano (Bozza: eliminare e ricreare dopo aver appiattito; Emesso/Chiuso: recuperare a conguaglio).

#### C. Dashboard Inbox Operativa (`DashboardController.php` & `Dashboard.vue`)
Nel caricamento della dashboard:
- Se `$diagnosiService->contaSegnali($condominio->id) > 0`:
  - Genera un task in `inboxTasks`:
    - `type`: `'diagnosi_struttura_conti'`
    - `title`: `'Struttura piano dei conti da verificare'`
    - `description`: `'Rilevate N anomalie (voci al 3° livello o rami a € 0,00 nel piano rate).'`
    - `action_url`: rotta verso il piano dei conti del condominio.

---

## 4. Matrice dei Test e Piano di Verifica

1. **Test Unitari / Feature sul Service (`tests/Feature/Gestionale/DiagnosiStrutturaContiServiceTest.php`):**
   - Riconoscimento voci 3° livello (con verifica di padre e nonno corretti).
   - Riconoscimento rami congelati a 0 con preventivo sotto.
   - Non-segnalazione dello zero legittimo (capitolo vuoto o spesa spostata via `budget_movements`).
   - Piano dei conti sano (2 livelli) → 0 segnali.
   - Calcolo `contaSegnali()` coerente.

2. **Test HTTP Controller (`tests/Feature/Gestionale/DiagnosiStrutturaContiHttpTest.php`):**
   - Pagina Piano dei Conti riceve la prop `diagnosiStruttura`.
   - Presenza dei segnali nella risposta Inertia quando esistono anomalie.
   - Assenza di segnali su installazioni sane.
   - Task presente nell'Inbox Dashboard quando ci sono anomalie.

3. **Test di Regressione CLI (`tests/Feature/Gestionale/VerificaStrutturaContiCommandTest.php`):**
   - Il comando `php artisan kondomanager:verifica-struttura-conti` continua a produrre lo stesso output esatto.

---

## 5. Estensioni Future (Altri Comandi Diagnostici)

Lo stesso pattern (Service + Widget contestuale + Task Inbox) sarà riutilizzato per:
- `php artisan kondomanager:verifica-titolarita` → Widget nella sezione Immobili/Anagrafiche.
- `php artisan kondomanager:verifica-saldi-solidali` → Widget nella sezione Saldi Iniziali.
