<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'esito di una prova (scene 2 e 3 della bozza). Andata: quando è partita e, per la PEC, dove guardare le ricevute. Non andata: il motivo in parole
 * semplici, che cosa fare, il pulsante per correggere il campo giusto e «Riprova»; sotto, piccolo, il testo del server per chi assiste lo studio. Il testo
 * del server è di chi l'ha scritto: si mostra com'è, mai come HTML.
 */
import { AlertCircle, Check, KeyRound, RotateCw } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { CampoDaCorreggere, MotivoDellaProva } from './tipi';

defineProps<{
    casella: 'posta' | 'pec';
    andata: boolean;
    quando: string | null;
    destinatario: string | null;
    motivo: MotivoDellaProva | null;
    testo: string | null;
    suggerimento?: string | null;
    /** «Riprova» c'è quando si sa verso chi riprovare. */
    puoRiprovare?: boolean;
}>();

defineEmits<{ (e: 'correggi', campo: CampoDaCorreggere): void; (e: 'riprova'): void }>();
</script>

<template>
    <div v-if="andata" class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300"
        data-testid="prova-andata">
        <Check class="mt-0.5 h-[18px] w-[18px] shrink-0" />
        <p v-if="casella === 'pec'" class="[overflow-wrap:anywhere]">La prova è partita {{ quando }} e il gestore l'ha accettata. Nella casella PEC arrivano la ricevuta di
            accettazione e, poco dopo, quella di consegna: guardale lì.</p>
        <p v-else class="[overflow-wrap:anywhere]">La prova è partita {{ quando }} verso {{ destinatario }}.</p>
    </div>
    <div v-else class="flex items-start gap-2.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
        data-testid="prova-non-andata">
        <AlertCircle class="mt-0.5 h-[18px] w-[18px] shrink-0" />
        <div class="min-w-0">
            <p class="font-semibold" data-testid="motivo-titolo">{{ motivo?.titolo }}</p>
            <p v-if="motivo?.fai" class="mt-0.5" data-testid="motivo-fai">{{ motivo.fai }}</p>
            <p v-if="suggerimento" class="mt-1" data-suggerimento-prova>{{ suggerimento }}</p>
            <div v-if="motivo?.azione || puoRiprovare" class="mt-2 flex flex-wrap gap-2">
                <Button v-if="motivo?.azione" type="button" variant="outline" size="sm" class="h-7 gap-1.5 bg-white text-xs text-slate-700 dark:bg-slate-950 dark:text-slate-200"
                    data-testid="motivo-azione" @click="$emit('correggi', motivo.azione.campo)">
                    <KeyRound v-if="motivo.azione.campo === 'password'" class="h-3.5 w-3.5" /> {{ motivo.azione.etichetta }}
                </Button>
                <Button v-if="puoRiprovare" type="button" variant="outline" size="sm" class="h-7 gap-1.5 bg-white text-xs text-slate-700 dark:bg-slate-950 dark:text-slate-200"
                    data-testid="riprova" @click="$emit('riprova')">
                    <RotateCw class="h-3.5 w-3.5" /> Riprova
                </Button>
            </div>
            <p v-if="testo" class="mt-1.5 text-xs opacity-90 [overflow-wrap:anywhere]" data-testid="testo-del-server">
                Il testo del server, per chi ti assiste:
                <code class="rounded-md border border-rose-200 bg-white px-1.5 [font-family:inherit] dark:border-rose-900 dark:bg-slate-950">{{ testo }}</code>
            </p>
        </div>
    </div>
</template>
