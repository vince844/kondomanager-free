<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Segnalazione extends Model
{
    use HasFactory;

    protected $table = 'segnalazioni';

    protected $fillable = [
        'subject',
        'description',
        'created_by',
        'assigned_to',
        'condominio_id',
        'priority',
        'stato',
        'is_resolved',
        'is_locked',
        'is_featured',
        'is_private',
        'is_published',
        'is_approved',
        'can_comment',
    ];

     // Define the many-to-many relationship with Anagrafica
     public function anagrafiche()
     {
        /* return $this->belongsToMany(Anagrafica::class, 'anagrafica_segnalazione', 'segnalazione_id', 'anagrafica_id'); */
        return $this->belongsToMany(Anagrafica::class);
     }
 
     // Other relationships (for example, creator, assignee, etc.)
     public function createdBy()
     {
        return $this->belongsTo(User::class, 'created_by');
     }
 
     public function assignedTo()
     {
        return $this->belongsTo(User::class, 'assigned_to');
     }
 
     public function condominio()
     {
        return $this->belongsTo(Condominio::class);
     }
     
}
