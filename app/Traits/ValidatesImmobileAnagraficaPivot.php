<?php

namespace App\Traits;


trait ValidatesImmobileAnagraficaPivot
{
    protected function withPivotValidator($validator, $tipologiaField = 'tipologia', $quotaField = 'quota')
    {
        $validator->after(function ($validator) use ($tipologiaField, $quotaField) {
         
            $immobile = $this->route('immobile');
            $currentAnagraficaId = $this->route('anagrafica')->id ?? null;

            $newAnagraficaId = (int) $this->input('anagrafica_id');
            $newQuota = (float) $this->input($quotaField);
            $newTipologia = $this->input($tipologiaField);

            // 1️⃣ Prevent duplicate anagrafica
            $alreadyExists = $immobile->anagrafiche()
                ->where('anagrafica_id', $newAnagraficaId)
                ->when($currentAnagraficaId, fn($q) => $q->where('anagrafica_id', '!=', $currentAnagraficaId))
                ->exists();

            if ($alreadyExists) {
                $validator->errors()->add('anagrafica_id', 'Questa anagrafica è già collegata a questo immobile.');
            }

            // 2️⃣ Quota sum validation per tipologia
            $totalQuotaByTipologia = $immobile->anagrafiche()
                ->wherePivot('tipologia', $newTipologia)
                ->when($currentAnagraficaId, fn($q) => $q->where('anagrafica_id', '!=', $currentAnagraficaId))
                ->sum('quota');

            if ($totalQuotaByTipologia + $newQuota > 100) {
                $validator->errors()->add(
                    $quotaField,
                    "La somma delle quote per {$newTipologia} non può superare 100."
                );
            }
        });
    }
}
