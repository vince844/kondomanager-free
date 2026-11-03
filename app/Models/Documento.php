<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documento extends Model
{
    protected $table = 'documenti';

    protected $fillable = [
        'updated_by',
        'name',
        'description',
        'created_by',
        'is_published',
        'is_approved',
        'path',
        'mime_type',
        'file_size', 

    ];

    /**
     * Le categorie del documento — **più d'una**, dalla 1.11.0-beta.10.
     *
     * ⚠️ **La relazione `categoria()` al singolare è stata tolta di proposito**, non rinominata con
     * un alias di compatibilità. Lasciandola in piedi, ogni punto del codice non convertito avrebbe
     * continuato a funzionare leggendo **una** categoria di N: nessun errore, nessun log, e il
     * risultato sbagliato solo per i documenti che ne hanno più d'una — cioè il caso nuovo, quello
     * che nessuno pensa a provare. Togliendola, quei punti falliscono alla prima richiesta e si
     * trovano subito.
     *
     * Il legame è `documento_categoria`, e non `categoria_documento` che sarebbe la forma canonica
     * di Laravel: quel nome starebbe a una lettera da `categorie_documento`, la tabella delle
     * categorie.
     */
    public function categorie()
    {
        return $this->belongsToMany(
            CategoriaDocumento::class,
            'documento_categoria',
            'documento_id',
            'categoria_documento_id'
        )->withTimestamps();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Chi ha modificato l'oggetto per ultimo. `null` finché non lo modifica nessuno.
     *
     * Aggiunta nella beta.64 insieme all'avviso di modifica: senza, quell'avviso era costretto a
     * nominare il **creatore**, cioè a dire una cosa falsa su chi aveva fatto cosa.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }


    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_documento');
    }

    public function condomini()
    {
        return $this->belongsToMany(Condominio::class, 'condominio_documento');
    }

    public function documentable()
    {
        return $this->morphTo();
    }

    /**
     * Il nome con cui il file va consegnato al browser.
     *
     * Il file su disco nasce da `hashName()`, che **preserva** l'estensione originale
     * (`documenti/a1b2c3.pdf`); `name` è invece il titolo scritto dall'amministratore, che di
     * estensione non ne ha. Passare il titolo così com'era faceva arrivare al browser un file
     * senza estensione — su macOS non si nota, perché il tipo lo indovina dal contenuto, su
     * Windows sì. Il difetto è arrivato dal forum due volte: nel maggio 2026 dal lato
     * amministratore, nell'agosto 2026 dal lato condòmino.
     *
     * ⚠️ **Sta qui, e non nei controller, perché la prima volta è stato corretto in uno dei due.**
     * `Documenti\DocumentoController@download` e `Documenti\Utenti\DocumentoController@download`
     * sono copie quasi riga per riga: la correzione di maggio è finita su una sola, e la seconda
     * segnalazione è **la metà non corretta della prima**. Finché la regola vive in un posto solo,
     * quella divergenza non si può ricreare. La simmetria fra le due porte è fissata da
     * `tests/Feature/Documenti/DownloadConEstensioneTest.php`, che le prova **per rotta**.
     *
     * L'estensione si appende solo se non c'è già, confrontando in minuscolo: `Regolamento.pdf` e
     * `Regolamento.PDF` restano intatti. Se il titolo dichiara un'estensione **diversa** da quella
     * del file — `Contratto.doc` su un PDF — vince quella vera e il nome diventa
     * `Contratto.doc.pdf`: il tipo reale del file conta più di quello che il titolo promette.
     *
     * ## I caratteri che in un nome di file non ci possono stare (beta.64)
     *
     * Terza segnalazione dal forum sulla stessa funzione, e l'amministratore l'aveva già
     * diagnosticata: *«se nel nome documento utilizzo un carattere che non è ammesso nel nome di un
     * file (nel mio caso il "/") il download di quel documento fallisce»*.
     *
     * `HeaderUtils::makeDisposition()` di Symfony **solleva un'eccezione** se il nome contiene `/`
     * o `\`. Laravel ripulisce il solo *fallback* da accenti e da `%` (`fallbackName()`), le barre
     * no — e comunque il controllo di Symfony guarda tutti e due i nomi. I controller catturano
     * l'eccezione e rimandano indietro con un messaggio generico, quindi a video si vedeva «si è
     * verificato un errore» senza nessun indizio, mentre il log diceva esattamente cosa fosse.
     *
     * ⚠️ **Il titolo NON si ripulisce in ingresso, e non è un dettaglio.** `Verbale 12/2026` è un
     * titolo giusto: in Italia i verbali d'assemblea si numerano così. Ripulirlo al salvataggio
     * vorrebbe dire riscrivere un dato corretto dell'archivio — in elenco, nella ricerca, nelle
     * notifiche — per un vincolo che non è dell'archivio ma del file system di chi scarica. Il
     * titolo è il dato; il nome del file è un artefatto che se ne ricava, ed è l'artefatto a
     * doversi adattare.
     *
     * Si raddrizzano due famiglie con la stessa sostituzione, ma la gravità è diversa: `/` e `\`
     * facevano **fallire il download**; `: * ? " < > |` e i caratteri di controllo passavano dal
     * server e poi **non si salvavano su Windows**, cioè un download che parte e finisce nel nulla.
     * La segnalazione è arrivata nella forma della classe, quindi si chiude la classe.
     */
    public function nomeDiScaricamento(): string
    {
        $nome = $this->raddrizzaPerIlFileSystem((string) $this->name);
        $estensione = pathinfo((string) $this->path, PATHINFO_EXTENSION);

        if ($estensione === '' || str_ends_with(mb_strtolower($nome), '.'.mb_strtolower($estensione))) {
            return $nome;
        }

        return $nome.'.'.$estensione;
    }

    /**
     * Sostituisce con `-` i caratteri che un nome di file non accetta.
     *
     * L'insieme è l'unione di quelli vietati da Windows e di quelli che rompono l'intestazione
     * HTTP: `\ / : * ? " < > |` più i caratteri di controllo. Non si toccano gli accenti — un
     * `Verbale società` deve restare tale, e ci arriva perché Symfony manda il nome vero in
     * `filename*` e la versione ASCII solo come ripiego.
     *
     * I punti e gli spazi in coda si tolgono perché Windows li scarta da sé, e un `Verbale..pdf`
     * arriverebbe salvato come `Verbale.pdf` senza che nessuno l'abbia deciso.
     */
    private function raddrizzaPerIlFileSystem(string $nome): string
    {
        // ⚠️ **Due passaggi e non un'unica espressione regolare, e la ragione è un errore vero.**
        // Dentro una classe di caratteri `[\/]` vale «barra dritta», non «barra rovesciata»: la
        // prima stesura era una regex sola e il backslash le sfuggiva, restituendo di nuovo un
        // nome che faceva fallire il download. L'ha preso il test che lo prova a parte. Con un
        // elenco esplicito passato a `str_replace` quel malinteso non è più possibile.
        $vietati = ['\\', '/', ':', '*', '?', '"', '<', '>', '|'];

        $pulito = str_replace($vietati, '-', $nome);
        $pulito = preg_replace('#[\x00-\x1F\x7F]#u', '-', $pulito) ?? $pulito;
        $pulito = rtrim($pulito, ". \t");

        // Un titolo fatto di soli caratteri vietati si ridurrebbe a niente, e un nome vuoto fa
        // fallire il download tanto quanto una barra. Non è uno scenario che qualcuno cercherà:
        // è la ragione per cui questa riga esiste.
        return $pulito === '' ? 'documento' : $pulito;
    }
}
