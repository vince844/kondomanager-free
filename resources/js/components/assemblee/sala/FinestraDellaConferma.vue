<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Un altro esito»: la conferma del voto con un esito scelto dal presidente. Un esito diverso da quello proposto vuole il motivo, che va nel registro e nel
 * verbale; senza, solo «Approvata con modifiche» quando la proposta è «Approvata» e il voto in conflitto non è determinante (la regola del server,
 * `VotazioniDellaSala::serveIlMotivo()`). Il testo della delibera è facoltativo. Senza un esito proposto (un'altra maggioranza, o nessuna) decide il
 * presidente. L'avviso del numero legale resta accanto alla conferma: non blocca. Un punto di nomina chiede il nome del nuovo amministratore (facoltativo):
 * la riga proposta del registro di nomina e revoca lo porta; vuoto, la riga ha il nome di oggi e si corregge lì.
 */
import { computed, ref, watch } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { EsitoDelibera } from '@/types/assemblee';
import { ESITI_DA_CONFERMARE, parolaDellEsito } from './sala';

type DaConfermare = Exclude<EsitoDelibera, 'senza_voto'>;

const props = withDefaults(defineProps<{ open: boolean; numero: number; proposto: string | null; avviso: string | null; conflittoDeterminante?: boolean;
    nomina?: boolean }>(), { conflittoDeterminante: false, nomina: false });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'conferma', esito: DaConfermare, motivo: string, testo: string, amministratore: string): void }>();

const esito = ref<DaConfermare | null>(null);
const valoreDi = (e: { valore: DaConfermare }) => e.valore;
const motivo = ref('');
const testo = ref('');
const amministratore = ref('');
watch(() => props.open, (aperta) => {
    if (!aperta) return;
    esito.value = (ESITI_DA_CONFERMARE.find((e) => e.valore === props.proposto)?.valore ?? null) as DaConfermare | null;
    motivo.value = '';
    testo.value = '';
    amministratore.value = '';
}, { immediate: true });
/** Il nome si chiede quando la nomina passa: approvata, o approvata con modifiche. */
const chiediIlNome = computed(() => props.nomina && (esito.value === 'approvata' || esito.value === 'approvata_con_modifiche'));
const serveIlMotivo = computed(() => esito.value !== null && props.proposto !== null && esito.value !== props.proposto
    && !(esito.value === 'approvata_con_modifiche' && props.proposto === 'approvata' && !props.conflittoDeterminante));
const pronta = computed(() => esito.value !== null && (!serveIlMotivo.value || motivo.value.trim() !== ''));

function conferma() {
    if (!pronta.value || esito.value === null) return;
    emit('conferma', esito.value, motivo.value.trim(), testo.value.trim(), chiediIlNome.value ? amministratore.value.trim() : '');
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" data-testid="finestra-conferma" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>L'esito del punto {{ numero }}</DialogTitle>
                <DialogDescription>{{ proposto ? `La proposta del programma: ${parolaDellEsito(proposto).toLowerCase()}.` : 'Nessuna proposta: decide il presidente.' }}</DialogDescription>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <p v-if="avviso" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                    {{ avviso }}
                </p>
                <div class="space-y-1.5">
                    <Label class="text-xs">L'esito</Label>
                    <v-select v-model="esito" :options="[...ESITI_DA_CONFERMARE]" label="etichetta" :reduce="valoreDi" :clearable="false"
                        :searchable="false" data-testid="esito-conferma" />
                </div>
                <div v-if="serveIlMotivo" class="space-y-1.5">
                    <Label for="motivo-conferma" class="text-xs">{{ conflittoDeterminante ? 'Perché questo esito, con il voto in conflitto determinante'
                        : 'Perché un esito diverso dalla proposta' }}</Label>
                    <Textarea id="motivo-conferma" v-model="motivo" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="motivo-conferma" />
                </div>
                <div v-if="chiediIlNome" class="space-y-1.5">
                    <Label for="nuovo-amministratore" class="text-xs">Il nuovo amministratore</Label>
                    <Input id="nuovo-amministratore" v-model="amministratore" maxlength="255" class="h-9 text-sm" data-testid="nuovo-amministratore"
                        placeholder="Per esempio: Studio Marchetti" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">Registrato il verbale, il registro di nomina e revoca propone la riga con questo nome. Vuoto, la riga ha il nome di oggi: lo correggi lì.</p>
                </div>
                <div class="space-y-1.5">
                    <Label for="testo-delibera" class="text-xs">Il testo della delibera (facoltativo)</Label>
                    <Textarea id="testo-delibera" v-model="testo" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="testo-delibera"
                        placeholder="Per esempio: approvato il preventivo dell'impresa Bianchi." />
                </div>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="!pronta" data-testid="conferma-esito" @click="conferma">Conferma: {{ parolaDellEsito(esito).toLowerCase() || '…' }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
