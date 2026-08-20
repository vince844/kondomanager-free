<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotaTabella extends Model
{
    protected $table = 'quote_tabella';

    protected $fillable = [
        'tabella_id', 
        'immobile_id',
        'valore', 
        'coefficienti', 
        'escluso',
        'created_by', 
        'updated_by'
    ];

    protected $casts = [
        'coefficienti' => 'array',
        'escluso'      => 'boolean',
    ];

    public function tabella()
    {
        return $this->belongsTo(Tabella::class);
    }

    public function immobile()
    {
        return $this->belongsTo(Immobile::class);
    }

    /*
     * ⚠️ **Qui stava `getValoreFormattatoAttribute()`, tolto nella beta.61 chiudendo la coda ⑪.**
     *
     * Era l'unico posto del backend che applicava `numero_decimali`, e aveva **zero chiamanti** in
     * tutto il progetto. La scheda della coda lo diceva già: «un metodo dichiarato e mai applicato
     * *sembra* il posto in cui vive la regola. Chi domani dovrà formattare i millesimi lato server
     * lo troverà, lo modificherà, e non cambierà niente — dopo averci perso mezz'ora».
     *
     * Rendeva anche il NULL come «0,00 ‰», cioè mostrava «non partecipa» dove il dato dice «non
     * ancora compilato» — la distinzione che questa beta ha appena introdotto.
     *
     * Dove il valore si formatta davvero: la pagina delle quote (`QuoteList.vue`) e la stampa del
     * riparto (`RipartoTabelleService`), che fanno ciascuna il proprio `number_format` sul posto.
     * `numero_decimali` governa **come il valore si mostra**, mai cosa si conserva.
     */
}
