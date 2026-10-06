<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Invito extends Model
{
    use HasFactory, Notifiable;

    /**
     * Quanto vale un invito: tre giorni, quanto il link firmato della mail e quanto dice il suo
     * testo. Fino alla 1.11.0-beta.44 l'invito scadeva dopo un'ora mentre la mail prometteva tre
     * giorni, e chi lo apriva il pomeriggio trovava una pagina 403 vuota.
     */
    public const GIORNI_DI_VALIDITA = 3;

    // Specify the table name since it's not the default 'invites'
    protected $table = 'inviti';

    // Define fillable attributes to guard against mass assignment
    protected $fillable = [
        'email',
        'building_codes',
        'expires_at',
        'accepted_at',
    ];

    // Casts for proper data types
    protected $casts = [
        'building_codes' => 'array', 
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Check if the invite is expired.
     */
    public function isExpired()
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the invite has been accepted.
     */
    public function isAccepted()
    {
        return !is_null($this->accepted_at);
    }

    /**
     * Get the associated user for this invite (if exists).
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    /**
     * Marks the invite as accepted.
     */
    public function accept()
    {
        $this->accepted_at = now();
        $this->save();
    }
    
}
