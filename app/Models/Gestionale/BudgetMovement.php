<?php

namespace App\Models\Gestionale;

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetMovement extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function pianoRate()
    {
        return $this->belongsTo(PianoRate::class);
    }

    public function sourceConto()
    {
        return $this->belongsTo(Conto::class, 'source_conto_id');
    }

    public function destinationConto()
    {
        return $this->belongsTo(Conto::class, 'destination_conto_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Il movimento che questo storna, se questo è uno storno. */
    public function movimentoOriginale()
    {
        return $this->belongsTo(BudgetMovement::class, 'reverses_movement_id');
    }

    /** Il movimento che ha stornato questo, se qualcuno l'ha fatto. */
    public function storno()
    {
        return $this->hasOne(BudgetMovement::class, 'reverses_movement_id');
    }
}
