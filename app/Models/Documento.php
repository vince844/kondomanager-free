<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Documento extends Model
{
    protected $table = 'documenti';

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'is_published',
        'is_approved',
        'path',
        'mime_type',
        'file_size', 
        'category_id',
    ];

    public function categoria()
    {
        return $this->belongsTo(CategoriaDocumento::class, 'category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
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
     */
    public function nomeDiScaricamento(): string
    {
        $nome = (string) $this->name;
        $estensione = pathinfo((string) $this->path, PATHINFO_EXTENSION);

        if ($estensione === '' || str_ends_with(mb_strtolower($nome), '.'.mb_strtolower($estensione))) {
            return $nome;
        }

        return $nome.'.'.$estensione;
    }
}
