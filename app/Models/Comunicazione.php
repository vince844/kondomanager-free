<?php

namespace App\Models;

use App\Helpers\SlugHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comunicazione extends Model
{
    use HasFactory;
    
    protected $table = 'comunicazioni';

    protected $fillable = [
        'subject',
        'description',
        'created_by',
        'priority',
        'is_featured',
        'is_private',
        'is_published',
        'is_approved',
        'can_comment',
        'slug',
        'reference',
    ];

    // Add this method to the model
    protected static function boot()
    {
        parent::boot();

        // Listen to the 'creating' event to generate the slug before saving
        static::creating(function ($comunicazione) {
            // Generate the slug using the SlugHelper
            $comunicazione->slug = SlugHelper::generateSlug($comunicazione->subject);
        });
    }

    /**
     * The condomini that belong to the comunicazione.
     */
    public function condomini()
    {
        /* return $this->belongsToMany(Condominio::class, 'comunicazione_condominio')->withTimestamps(); */
        return $this->belongsToMany(Condominio::class);
    }

    /**
     * The anagrafiche that belong to the comunicazione.
     */
    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_comunicazione')->withTimestamps();
    }

    // Other relationships (for example, creator, assignee, etc.)
    public function createdBy()
    {
       return $this->belongsTo(User::class, 'created_by');
    }
}
