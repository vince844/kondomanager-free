<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le impostazioni di un condominio: una riga per condominio, una colonna per scelta (la scheda «Impostazioni» dell'area «Condominio»). Senza la riga valgono i
 * valori di serie, che {@see delCondominio()} rende senza scrivere niente.
 *
 * @property int $condominio_id
 * @property string $recupero_salita
 * @property bool $recupero_suggerisce_azione
 * @property ?int $aggiornate_da
 */
class ImpostazioniCondominio extends Model
{
    protected $table = 'impostazioni_condominio';

    /** Dopo una lettera, quando scadono rate nuove: la salita va avanti (di serie). */
    public const SALITA_AVANTI = 'avanti';

    /** Dopo una lettera, quando scadono rate nuove: per le rate nuove si ricomincia. */
    public const SALITA_RICOMINCIA = 'ricomincia';

    public const SALITE = [self::SALITA_AVANTI, self::SALITA_RICOMINCIA];

    /** I valori di serie, gli stessi delle colonne. */
    public const DI_SERIE = ['recupero_salita' => self::SALITA_AVANTI, 'recupero_suggerisce_azione' => true];

    protected $fillable = ['condominio_id', 'recupero_salita', 'recupero_suggerisce_azione', 'aggiornate_da'];

    protected $casts = [
        'condominio_id' => 'integer',
        'recupero_suggerisce_azione' => 'boolean',
        'aggiornate_da' => 'integer',
    ];

    protected $attributes = self::DI_SERIE;

    /** Le impostazioni del condominio: la sua riga, o i valori di serie senza scrivere niente. */
    public static function delCondominio(int $condominioId): self
    {
        return self::where('condominio_id', $condominioId)->first() ?? new self(['condominio_id' => $condominioId] + self::DI_SERIE);
    }

    /** Le scelte, per la pagina. */
    public function perLaPagina(): array
    {
        return ['recupero_salita' => (string) $this->recupero_salita, 'recupero_suggerisce_azione' => (bool) $this->recupero_suggerisce_azione];
    }

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }
}
