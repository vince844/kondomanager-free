<?php

namespace App\Http\Controllers\Condomini;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use Illuminate\Http\Request;

class FetchCondominiController extends Controller
{
    /**
     * Handle the incoming request.
     *
     * Users allowed to view condomini (managers/administrators) receive the full
     * list. Any other authenticated user only receives the condomini they are
     * associated with, so the endpoint no longer discloses every building's name
     * to any logged-in account.
     */
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->hasPermissionTo(Permission::VIEW_CONDOMINI->value)) {
            $condomini = Condominio::select('id', 'nome')->orderBy('nome')->get();
        } else {
            $condomini = $user->anagrafica
                ? $user->anagrafica->condomini()
                    ->select('condomini.id', 'condomini.nome')
                    ->orderBy('nome')
                    ->get()
                : collect();
        }

        return response()->json($condomini);
    }
}
