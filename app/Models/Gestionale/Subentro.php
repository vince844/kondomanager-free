<?php

namespace App\Models\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un passaggio di titolarità registrato: l'evento datato con un autore (tabella `subentri`, B2).
 *
 * Progetto `docs/subentro_e_competenza_temporale.md` §4.4 e decisioni 13–14; interfaccia nel §6 di
 * `pertinenze_vendita_locazione.md` («Registra passaggio»). Chi esce, chi entra, con che ruolo, da che
 * data, con quale titolo; le due righe della pivot che chiude e apre; la coppia di conguaglio in `saldi`
 * che gli appartiene (D9: somma zero, mai le rate emesse). `nota_cancello` è la nota del cancello (1).
 *
 * Lo scrive `RegistraSubentroAction` (S5). Le colonne di S5 (`documento_id`, `data_fine_locazione`,
 * `regime_contratto`, `nota_conguaglio`, `subentro_padre_id`) arrivano con l'ottava migrazione di B2.
 */
class Subentro extends Model
{
    protected $table = 'subentri';

    public const TIPI_PASSAGGIO = ['vendita', 'inizio_locazione', 'fine_locazione', 'usufrutto'];

    protected $fillable = [
        'condominio_id', 'immobile_id', 'subentro_padre_id', 'anagrafica_uscente_id', 'anagrafica_entrante_id',
        'riga_uscente_id', 'riga_entrante_id', 'tipologia', 'tipo_passaggio', 'decorrenza',
        'data_fine_locazione', 'regime_contratto', 'estremi_titolo', 'copia_autentica_il', 'documento_id',
        'nota', 'nota_cancello', 'nota_conguaglio', 'conguaglio_annullato_il', 'nota_annullamento_conguaglio', 'utente_id',
    ];

    protected $casts = [
        'decorrenza' => 'date:Y-m-d',
        'copia_autentica_il' => 'date:Y-m-d',
        'data_fine_locazione' => 'date:Y-m-d',
        'conguaglio_annullato_il' => 'datetime',
    ];

    public function condominio(): BelongsTo { return $this->belongsTo(Condominio::class); }
    public function immobile(): BelongsTo { return $this->belongsTo(Immobile::class); }
    public function uscente(): BelongsTo { return $this->belongsTo(Anagrafica::class, 'anagrafica_uscente_id'); }
    public function entrante(): BelongsTo { return $this->belongsTo(Anagrafica::class, 'anagrafica_entrante_id'); }
    public function utente(): BelongsTo { return $this->belongsTo(User::class, 'utente_id'); }

    /** La coppia di conguaglio (D9): due righe, stesso `subentro_id`, somma zero. */
    public function saldi(): HasMany { return $this->hasMany(Saldo::class, 'subentro_id'); }

    /** Il PDF del titolo allegato al passaggio (S5); documento **dell'unità**, solo dell'amministratore. */
    public function documento(): BelongsTo { return $this->belongsTo(\App\Models\Documento::class, 'documento_id'); }

    /** Per la riga di una pertinenza: il passaggio dell'unità principale. */
    public function padre(): BelongsTo { return $this->belongsTo(self::class, 'subentro_padre_id'); }

    /** Le righe delle pertinenze che questo passaggio ha trascinato con sé. */
    public function pertinenze(): HasMany { return $this->hasMany(self::class, 'subentro_padre_id'); }

    /** La coppia di conguaglio è stata tolta dopo la registrazione (S6), con data e ragione. */
    public function conguaglioAnnullato(): bool { return $this->conguaglio_annullato_il !== null; }

    /** L'amministratore ha rinunciato alla coppia di conguaglio **alla registrazione**, con la sua ragione. */
    public function conguaglioRinunciato(): bool { return $this->nota_conguaglio !== null && trim((string) $this->nota_conguaglio) !== ''; }
}
