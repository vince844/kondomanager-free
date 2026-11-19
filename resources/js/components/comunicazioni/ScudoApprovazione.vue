<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Lo scudo dell'approvazione nell'elenco delle comunicazioni (prima nella cella del titolo, `columns.ts`).
 *
 * P8 della lente P (Fase 1-bis della beta.12, Coda 589): un clic cambia l'approvazione, e due casi hanno conseguenze che l'elenco non diceva.
 * - **Una programmata** (approvata, non ancora uscita, con il giorno e l'ora): togliendo l'approvazione si toglie anche la data (la regola del model).
 * - **Una non approvata**: approvandola dallo scudo esce subito (`is_published` segue l'approvazione) e partono le email ai destinatari che le ricevono.
 * In questi due casi lo scudo chiede conferma e dice che cosa succede («decide l'amministratore, e dice le conseguenze»); negli altri (una pubblicata,
 * una bozza senza data) il clic fa come prima.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ShieldCheck } from 'lucide-vue-next';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { usePermission } from '@/composables/permissions';
import { giornoEOraProgrammati } from '@/lib/pubblicazione';
import type { Comunicazione } from '@/types/comunicazioni';

const props = defineProps<{
    comunicazione: Comunicazione;
    /** La riga della tabella aggiornata dopo il comando, senza ricaricare l'elenco. */
    aggiorna: (campi: Partial<Comunicazione>) => void;
}>();

const { generateRoute } = usePermission();

const conferma = ref(false);
const quando = computed(() => giornoEOraProgrammati(props.comunicazione.data_programmata));
const programmata = computed(() => !!props.comunicazione.is_approved && !props.comunicazione.is_published && quando.value !== null);
const daPubblicare = computed(() => !props.comunicazione.is_approved);
// Le email dell'avviso partono verso i destinatari: i palazzi o le persone della comunicazione. Senza nessuno, la frase non le promette.
const conDestinatari = computed(() =>
    (props.comunicazione.condomini?.options?.length ?? 0) > 0 || (props.comunicazione.anagrafiche?.length ?? 0) > 0
);

const titolo = computed(() => trans(programmata.value ? 'comunicazioni.shield.unschedule_title' : 'comunicazioni.shield.publish_title'));
const testo = computed(() => {
    if (programmata.value && quando.value) {
        return trans('comunicazioni.shield.unschedule_description', { date: quando.value.date, time: quando.value.time });
    }

    return [trans('comunicazioni.shield.publish_description'), conDestinatari.value ? trans('comunicazioni.shield.publish_emails') : null]
        .filter(Boolean).join(' ');
});
const testoDelPulsante = computed(() => trans(programmata.value ? 'comunicazioni.shield.unschedule_confirm' : 'comunicazioni.shield.publish_confirm'));

const tooltip = computed(() =>
    props.comunicazione.is_approved ? trans('comunicazioni.table.approved_tooltip') : trans('comunicazioni.table.unapproved_tooltip')
);

function cambia(): void {
    conferma.value = false;
    router.put(route(generateRoute('comunicazioni.toggle-approval'), { id: props.comunicazione.id }), {}, {
        preserveScroll: true,
        only: ['stats', 'comunicazioni', 'flash'],
        onSuccess: () => {
            const approvata = !props.comunicazione.is_approved;
            props.aggiorna({
                is_approved: approvata,
                is_published: approvata,
                // Il server toglie la data quando la comunicazione non è più approvata e non ancora uscita (`Comunicazione::saving`).
                ...(approvata ? {} : { data_programmata: null }),
            });
        },
    });
}

function clic(): void {
    if (programmata.value || daPubblicare.value) {
        conferma.value = true;

        return;
    }
    cambia();
}
</script>

<template>
    <div data-scudo-approvazione class="cursor-pointer" :title="tooltip" @click="clic">
        <ShieldCheck :class="comunicazione.is_approved ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500'" />
    </div>
    <ConfirmDialog
        v-model="conferma"
        :title="titolo"
        :description="testo"
        :confirm-text="testoDelPulsante"
        :variant="programmata ? 'warning' : 'default'"
        @confirm="cambia"
    />
</template>
