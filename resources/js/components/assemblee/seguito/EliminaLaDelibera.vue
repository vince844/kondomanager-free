<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Elimina» su una delibera con le cose da fare (la riunione registrata a mano o tenuta su carta), nello standard delle finestre: elenca le cose che se ne
 * vanno con la delibera, quelle fatte con il giorno, la persona e il documento («Firma con Serramenti Marino · fatta il 16/12/2026 da Andrea Costa ·
 * contratto firmato.pdf»); chiede **il motivo, sempre obbligatorio** («esito sbagliato, la registro di nuovo»); e dice dove resta tutto: nel diario
 * dell'assemblea e nel registro delle modifiche, con la delibera, le cose con chi le ha fatte e quando, e il motivo; il PDF fra i documenti dell'assemblea.
 *
 * Una cosa ancora da fare tiene la delibera: si segna «Non serve più» nel seguito, poi si elimina (il riquadro arancio lo dice, e «Elimina» resta spento).
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { usePermission } from '@/composables/permissions';
import { giornoItaliano } from '@/lib/giorno';
import { AIUTO, ARANCIO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from '@/components/assemblee/verbale/finestra';
import type { CosaDaFare } from '@/types/assemblee';

const props = withDefaults(defineProps<{
    open: boolean;
    condominioId: number;
    delibera: { id: number; testo: string; punto: number | null } | null;
    cose: CosaDaFare[];
    /** L'ultima delibera di una riunione a mano senza il verbale: se ne va anche la riunione. */
    conLaRiunione?: boolean;
}>(), { conLaRiunione: false });
const emit = defineEmits<{ 'update:open': [v: boolean] }>();

const { generateRoute } = usePermission();
const motivo = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { motivo.value = ''; errori.value = {}; } });

const ancoraDaFare = computed(() => props.cose.filter((c) => c.stato === 'da_fare'));
/** «Firma con Serramenti Marino · fatta il 16/12/2026 da Andrea Costa · contratto firmato.pdf». */
function rigaDellaCosa(c: CosaDaFare): string {
    const stato = c.stato === 'fatta' ? `fatta il ${giornoItaliano(c.fatta_il)}${c.fatta_da ? ` da ${c.fatta_da}` : ''}`
        : c.stato === 'non_serve' ? `non serve più dal ${giornoItaliano(c.fatta_il)}` : 'ancora da fare';

    return `${c.testo} · ${stato}${c.documento ? ` · ${c.documento.nome}` : ''}`;
}
const riepilogo = computed(() => `Se ne vanno la delibera e ${props.cose.length === 1 ? '1 cosa da fare' : `${props.cose.length} cose da fare`}${props.conLaRiunione
    ? ', e la riunione' : ''}.`);
const puoEliminare = computed(() => !inCorso.value && ancoraDaFare.value.length === 0 && motivo.value.trim() !== '');

function elimina() {
    if (!puoEliminare.value || !props.delibera) return;
    inCorso.value = true;
    router.delete(route(generateRoute('gestionale.delibere.destroy'), { condominio: props.condominioId, delibera: props.delibera.id }), {
        data: { motivo: motivo.value.trim(), con_la_riunione: props.conLaRiunione },
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="elimina-la-delibera">
            <DialogHeader :class="TESTA">
                <DialogTitle>Elimina la delibera{{ delibera?.punto ? ` del punto ${delibera.punto}` : '' }}</DialogTitle>
                <DialogDescription v-if="delibera" class="break-words">{{ delibera.testo }}</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Le cose da fare che se ne vanno con la delibera</p>
                    <ul class="space-y-1 text-sm text-slate-800 dark:text-slate-200">
                        <li v-for="c in cose" :key="c.id" class="break-words" data-testid="cosa-che-se-ne-va">{{ rigaDellaCosa(c) }}</li>
                    </ul>
                    <div v-if="ancoraDaFare.length" :class="ARANCIO" data-testid="ancora-da-fare">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span>{{ ancoraDaFare.length === 1 ? 'Una cosa è ancora da fare' : `${ancoraDaFare.length} cose sono ancora da fare` }}: segnala «Non serve più» nel
                            seguito delle delibere, poi eliminala.</span>
                    </div>
                </section>
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Il motivo</p>
                    <Textarea v-model="motivo" rows="2" class="min-h-[56px] resize-none text-sm" aria-label="Il motivo" placeholder="Per esempio: esito sbagliato, la registro di nuovo."
                        data-testid="motivo-elimina" />
                    <InputError :message="errori.motivo ?? errori.delibera" />
                </section>
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Dove resta</p>
                    <p :class="AIUTO" data-testid="dove-resta">Resta tutto nel diario dell'assemblea e nel registro delle modifiche: la delibera, le cose da fare con chi le ha fatte
                        e quando, e il motivo. Il PDF resta fra i documenti dell'assemblea.</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>{{ riepilogo }}</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button variant="destructive" :disabled="!puoEliminare" data-testid="conferma-elimina" @click="elimina">Elimina la delibera</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
