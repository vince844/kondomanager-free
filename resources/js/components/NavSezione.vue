<script setup lang="ts">
/**
 * La barra di navigazione di una sezione: orizzontale da `md` in su, tendina sotto.
 *
 * ## Perché è un componente e non sei copie
 *
 * Lo stesso pezzo di markup viveva in **sei** layout — utenti, impostazioni personali, struttura,
 * immobile, fornitore, movimenti — e aveva già cominciato a divergere: tre usavano
 * `inline-flex space-x-2` senza `flex-wrap`, quindi su un telefono la barra **sforava** invece di
 * andare a capo; uno era una colonna a sinistra; solo quello dei movimenti aveva imparato il
 * `flex-wrap`, e l'aveva imparato il giorno in cui la sesta voce ha rotto la pagina.
 *
 * È la seconda lezione della beta.54 applicata al frontend: la correzione va nel punto condiviso,
 * o il layout successivo nasce già sbagliato.
 *
 * ## La tendina sotto `md`
 *
 * Stesso schema del pulsante «Opzioni» dell'intestazione — un comando `md:hidden` che apre un
 * contenitore `hidden md:flex` — così le due si leggono come la stessa cosa invece che come due
 * invenzioni diverse. Chiusa occupa una riga e dice comunque **dove sei**, perché porta il nome
 * della sezione corrente.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ChevronDown } from 'lucide-vue-next';
import type { Component } from 'vue';

export interface VoceSezione {
    icon?: Component;
    title: string;
    href: string;
    badge?: string;
    /** Una voce senza destinazione: si mostra, non si clicca. Nei movimenti è `href: '#'`. */
    disabled?: boolean;
    /**
     * Si accende **solo** sul percorso esatto. Serve alla voce di base di una scheda — «Fornitore»
     * sta a `/fornitori/5` e le sue sorelle a `/fornitori/5/documenti`: con il confronto per
     * prefisso resterebbe accesa ovunque, e la barra direbbe che sei in due posti insieme.
     */
    exact?: boolean;
}

const props = defineProps<{
    items: VoceSezione[];
    /**
     * Tutte le voci si accendono solo sul percorso esatto. Le impostazioni personali facevano già
     * così — `currentPath === item.href` — e sono cinque percorsi fratelli senza figli: marcarle
     * una per una sarebbe stato ripetere cinque volte la stessa cosa.
     */
    esatte?: boolean;
}>();

const aperta = ref(false);

const currentPath = window.location.pathname;

/**
 * Il confronto è **per segmento**, non per prefisso di stringa: `/fatture` non deve accendersi su
 * `/fatture-passive-altro`. Per le voci di base c'è `exact`.
 */
const attiva = (voce: VoceSezione) => {
    if (voce.disabled || voce.href === '#') {
        return false;
    }

    return (voce.exact || props.esatte)
        ? currentPath === voce.href
        : currentPath === voce.href || currentPath.startsWith(voce.href + '/');
};

/** La voce in cui ci si trova: dà il nome al pulsante della tendina. */
const sezioneCorrente = computed(() => props.items.find(attiva) ?? props.items[0]);
</script>

<template>
    <div>
        <button
            type="button"
            @click="aperta = !aperta"
            class="md:hidden inline-flex h-9 w-full items-center justify-between gap-2 px-3 mb-2 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
        >
            <span class="flex items-center gap-2 truncate">
                <component v-if="sezioneCorrente?.icon" :is="sezioneCorrente.icon" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ sezioneCorrente?.title }}</span>
            </span>
            <ChevronDown class="h-4 w-4 shrink-0 transition-transform" :class="{ 'rotate-180': aperta }" />
        </button>

        <nav
            :class="[
                'flex-col md:flex-row md:flex-wrap items-stretch md:items-center gap-2 w-full md:w-fit max-w-full shadow ring-1 ring-black/5 md:rounded-lg p-2',
                aperta ? 'flex' : 'hidden md:flex',
            ]"
        >
            <Button
                v-for="voce in items"
                :key="voce.title"
                variant="ghost"
                :class="['justify-start', { 'bg-muted': attiva(voce) }]"
                :disabled="voce.disabled || voce.href === '#'"
                as-child
            >
                <Link :href="voce.href">
                    <component v-if="voce.icon" :is="voce.icon" class="mr-1 h-4 w-4" />
                    {{ voce.title }}
                    <Badge
                        v-if="voce.badge"
                        class="ml-2 text-[10px] px-1.5 py-0 bg-blue-100 text-blue-700 rounded-md border-blue-200 hover:bg-blue-100"
                    >
                        {{ voce.badge }}
                    </Badge>
                </Link>
            </Button>
        </nav>
    </div>
</template>
