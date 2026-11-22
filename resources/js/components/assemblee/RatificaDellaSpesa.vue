<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La ratifica della spesa oltre il preventivo (R12, decisione 561; scena 1 della bozza del memo, scena 1c della bozza delle scene della tappa 2): si sceglie
 * la delibera con «Scegli la delibera» (in testa quella del punto nato da questa fattura), e «Ratificata il» viene dalla delibera, non dal clic. «Ratifica»
 * resta spento finché la delibera non c'è; a sinistra «Da portare in assemblea», se l'assemblea non ne ha ancora parlato. La ratifica chiude i compiti della
 * ratifica di questa fattura con la frase della delibera (`fatture.ratifica`, `RatificaDelloSforo::ratifica`). Vale per la fattura in sforo, per quella già
 * sbloccata per l'urgenza (la testata dice «pagata per l'urgenza» quando è pagata) e, con `di-prima`, per una ratifica fatta prima della 1.12 con la nota, che
 * qui si lega solo alla riunione.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ShieldAlert } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import ScegliLaDelibera from '@/components/assemblee/ScegliLaDelibera.vue';
import PulsanteDaPortareInAssemblea from '@/components/assemblee/PulsanteDaPortareInAssemblea.vue';
import { usePermission } from '@/composables/permissions';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { euro, giornoItaliano } from '@/lib/assemblee/assemblee';
import { parolaDellUrgenza, type FatturaDellaRatifica } from '@/lib/assemblee/ratifica';
import type { DeliberaDelMenu } from '@/types/assemblee';

const props = defineProps<{
    open: boolean;
    condominioId: number;
    condominioNome: string;
    fattura: FatturaDellaRatifica;
    /** Una ratifica fatta prima della 1.12 con la nota: è già ratificata, e qui si lega solo alla delibera della riunione («Collega la delibera»). */
    diPrima?: boolean;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'fatto'): void }>();

const { generateRoute } = usePermission();
const delibera = ref<DeliberaDelMenu | null>(null);
const nota = ref('');
const errore = ref<string | null>(null);
const inCorso = ref(false);
const oggi = giornoDiRoma();

watch(() => props.open, (a) => {
    if (a) {
        delibera.value = null;
        nota.value = '';
        errore.value = null;
    }
});

const inSforo = computed(() => props.fattura.stato_approvazione === 'sforo_motivato');
const testata = computed(() => [
    `Fattura n. ${props.fattura.numero_documento}${props.fattura.data_documento ? ` del ${giornoItaliano(props.fattura.data_documento)}` : ''}`,
    props.fattura.fornitore_nome, props.fattura.totale_documento !== null ? euro(props.fattura.totale_documento) : null,
    inSforo.value ? null : props.diPrima ? 'ratificata prima, con la nota' : parolaDellUrgenza(props.fattura.stato_pagamento),
].filter(Boolean).join(' · '));
const oltre = computed(() => props.fattura.sforo_oltre
    ? `La spesa supera il preventivo${props.fattura.voce_sforo ? ` della voce «${props.fattura.voce_sforo}»` : ''} di ${euro(props.fattura.sforo_oltre)}.`
    : 'La spesa supera il preventivo.');
const stessoGiorno = computed(() => delibera.value?.deliberata_il === oggi);
/** Una delibera di un giorno prima della fattura si può scegliere, ma la finestra lo dice: è più probabile che sia quella sbagliata. */
const primaDellaFattura = computed(() => !!delibera.value?.deliberata_il && !!props.fattura.data_documento && delibera.value.deliberata_il < props.fattura.data_documento);

function ratifica() {
    if (!delibera.value) return;
    inCorso.value = true;
    errore.value = null;
    router.post(route(generateRoute('gestionale.fatture.ratifica'), { condominio: props.condominioId, fattura: props.fattura.id }),
        { delibera_id: delibera.value.id, nota: nota.value.trim() || null }, {
            preserveScroll: true,
            onSuccess: () => { emit('fatto'); emit('update:open', false); },
            onError: (e: Record<string, string>) => { errore.value = Object.values(e)[0] ?? 'La ratifica non è stata registrata. Riprova.'; },
            onFinish: () => { inCorso.value = false; },
        });
}
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[620px]" data-testid="finestra-ratifica" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ diPrima ? 'Collega la delibera della ratifica' : 'Ratifica della spesa oltre il preventivo' }}</DialogTitle>
                <DialogDescription>{{ testata }}</DialogDescription>
            </DialogHeader>
            <div class="space-y-3.5 text-sm">
                <div v-if="!delibera && !diPrima" class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                    data-testid="serve-una-decisione">
                    <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <p class="font-medium">Serve una decisione dell'assemblea.</p>
                        <p class="mt-0.5 text-xs">{{ oltre }} Si ratifica quando l'assemblea ne ha parlato.</p>
                    </div>
                </div>
                <ScegliLaDelibera v-model="delibera" :condominio-id="condominioId" :condominio-nome="condominioNome" ruolo="ratifica" oggetto-tipo="fattura_passiva"
                    :oggetto-id="fattura.id" per-questo="questa fattura"
                    :vuoto="`Nessuna assemblea di ${condominioNome} ha ancora parlato di questa fattura.`"
                    info="La delibera dell'assemblea che ha ratificato la spesa: il programma propone in testa quella del punto nato da questa fattura." />
                <template v-if="delibera">
                    <div data-testid="ratificata-il">
                        <span class="block text-xs font-medium text-slate-600 dark:text-slate-400">Ratificata il</span>
                        <p class="text-sm text-slate-900 dark:text-slate-100"><span class="font-semibold tabular-nums">{{ giornoItaliano(delibera.deliberata_il) }}</span> dalla delibera</p>
                        <p v-if="!stessoGiorno" class="text-xs text-slate-500 dark:text-slate-400">È il giorno della riunione: resta quello anche se registri la ratifica oggi, il {{ giornoItaliano(oggi).slice(0, 5) }}.</p>
                        <p v-if="primaDellaFattura" class="mt-1 text-xs text-amber-800 dark:text-amber-300" data-testid="delibera-prima-della-fattura">
                            La delibera è del {{ giornoItaliano(delibera.deliberata_il) }}, prima della fattura del {{ giornoItaliano(fattura.data_documento) }}: controlla che sia quella giusta.
                        </p>
                    </div>
                    <label class="block"><span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Una nota (facoltativa)</span>
                        <input v-model="nota" type="text" placeholder="Per esempio: approvata con la relazione dell'idraulico"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm placeholder:text-slate-400 dark:bg-slate-950" data-testid="nota-ratifica" />
                    </label>
                    <p class="text-xs text-slate-600 dark:text-slate-300">
                        Con «Ratifica» si chiudono i compiti della ratifica di questa fattura, con la risposta «Chiuso dalla delibera del {{ giornoItaliano(delibera.deliberata_il) }}{{ delibera.punto ? `, punto ${delibera.punto.numero}` : '' }}».
                    </p>
                </template>
                <p v-if="errore" class="text-sm text-rose-700 dark:text-rose-400" data-testid="errore-ratifica">{{ errore }}</p>
            </div>
            <div class="flex flex-col gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:items-center dark:border-slate-800">
                <div class="sm:mr-auto">
                    <PulsanteDaPortareInAssemblea v-if="!delibera && !diPrima" :condominio-id="condominioId" origine-tipo="fattura_passiva" :origine-id="fattura.id" />
                </div>
                <div class="flex flex-col-reverse gap-2 sm:flex-row">
                    <Button variant="outline" @click="$emit('update:open', false)">Annulla</Button>
                    <Button :disabled="!delibera || inCorso" data-testid="conferma-ratifica" @click="ratifica">{{ inCorso ? 'Un momento…' : diPrima ? 'Collega' : 'Ratifica' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
