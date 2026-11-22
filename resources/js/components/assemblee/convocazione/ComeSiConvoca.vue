<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * I due campi della convocazione nei moduli dell'anagrafica: «Come si convoca», il mezzo dell'art. 66 disp. att. (PEC, Raccomandata, A mano, Fax; vuoto,
 * il programma lo propone dai dati: la PEC se c'è, altrimenti la raccomandata all'indirizzo), e «PEC comunicata dalla persona il», il giorno in cui la persona ha
 * dato la sua PEC (una PEC non comunicata si propone con un avviso). L'email semplice non è un mezzo di convocazione, e la «i» lo dice con l'esempio.
 */
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Info } from 'lucide-vue-next';
import CampoGiorno from '@/components/CampoGiorno.vue';
import InputError from '@/components/InputError.vue';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Label } from '@/components/ui/label';
import type { MezzoConvocazione } from '@/types/assemblee';

defineProps<{ mezzo: MezzoConvocazione | null | undefined; pecComunicataIl: string | null | undefined; erroreMezzo?: string; errorePec?: string }>();
const emit = defineEmits<{ (e: 'update:mezzo', v: MezzoConvocazione | null): void; (e: 'update:pecComunicataIl', v: string | null): void }>();

const MEZZI: { valore: MezzoConvocazione; etichetta: string }[] = [
    { valore: 'pec', etichetta: 'PEC' },
    { valore: 'raccomandata', etichetta: 'Raccomandata' },
    { valore: 'a_mano', etichetta: 'A mano' },
    { valore: 'fax', etichetta: 'Fax' },
];
</script>

<template>
    <div class="sm:col-span-3">
        <div class="flex min-h-[24px] items-center gap-2">
            <Label for="mezzo_convocazione">Come si convoca</Label>
            <HoverCard>
                <HoverCardTrigger as-child>
                    <button type="button" class="flex cursor-pointer items-center outline-none" aria-label="Come si convoca: che cosa vale">
                        <Info class="h-3.5 w-3.5 text-slate-400 transition-colors hover:text-indigo-500" />
                    </button>
                </HoverCardTrigger>
                <HoverCardContent class="z-50 w-80">
                    <div class="space-y-2 text-sm text-slate-500 dark:text-slate-400">
                        <p>L'email semplice non vale come convocazione (art. 66 c. 3; Cass. 16399/2025).</p>
                        <p>Esempio: Mario Rossi ti ha dato per scritto la sua PEC il 02/03/2026: scegli PEC e scrivi quella data.</p>
                    </div>
                </HoverCardContent>
            </HoverCard>
        </div>
        <v-select
            input-id="mezzo_convocazione"
            class="premium-select mt-1 w-full bg-white dark:bg-slate-950"
            :options="MEZZI"
            label="etichetta"
            :model-value="mezzo ?? null"
            :reduce="(m: { valore: MezzoConvocazione }) => m.valore"
            placeholder="Lo propone il programma dai dati"
            @update:model-value="(v: MezzoConvocazione | null) => emit('update:mezzo', v ?? null)"
        />
        <InputError :message="erroreMezzo" />
    </div>
    <div class="sm:col-span-3">
        <div class="flex min-h-[24px] items-center"><Label for="pec_comunicata_il">PEC comunicata dalla persona il</Label></div>
        <CampoGiorno
            id="pec_comunicata_il"
            class="mt-1 h-9 text-sm"
            svuotabile
            :model-value="pecComunicataIl ?? ''"
            @update:model-value="(v: string) => emit('update:pecComunicataIl', v || null)"
        />
        <InputError :message="errorePec" />
    </div>
</template>
