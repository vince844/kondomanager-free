<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import { Camera, Files, Info, X } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { trans, transChoice } from 'laravel-vue-i18n';
import { preparaImmagine } from '@/lib/allegati/preparaImmagine';

interface Props {
    modelValue: File[];
    massimo?: number;
    rimasti?: number;
    limiteFile?: string;
    formati?: string[];
    error?: string;
    // «zona»: nel riquadro della scheda il caricamento chiude come «Scrivi un commento…» del riquadro accanto (decisione 464); i moduli
    // «Nuova segnalazione» e «Nuova comunicazione» restano con l'etichetta e il pulsante («campo», di serie).
    aspetto?: 'campo' | 'zona';
}

const props = withDefaults(defineProps<Props>(), {
    massimo: 5,
    rimasti: 5,
    limiteFile: '10 MB',
    formati: () => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    error: undefined,
    aspetto: 'campo',
});

const emit = defineEmits<{
    (e: 'update:modelValue', files: File[]): void;
}>();

const inputRef = ref<HTMLInputElement | null>(null);
const cameraRef = ref<HTMLInputElement | null>(null);
const inPreparazione = ref(false);

function rilevaPointerCoarse(): boolean {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return false;
    }
    try {
        return Boolean(window.matchMedia('(pointer: coarse)')?.matches);
    } catch {
        return false;
    }
}

const supportaFotocamera = rilevaPointerCoarse();
const formatiImmagine = ['jpg', 'jpeg', 'png', 'webp'];

const haImmagini = computed(() =>
    props.formati.some((ext) => formatiImmagine.includes(ext.toLowerCase()))
);

const mostraScattaFoto = computed(() =>
    supportaFotocamera && haImmagini.value
);

const acceptAttr = computed(() =>
    props.formati.map((ext) => `.${ext.toLowerCase()}`).join(',')
);

const formatiEtichetta = computed(() =>
    props.formati.map((ext) => ext.toUpperCase()).join(', ')
);

// I formati e i posti della zona del riquadro: nel suggerimento della «i» e, per chi non vede, nella descrizione del pulsante (decisione 464).
const idSuggerimento = `allegati-suggerimento-${Math.random().toString(36).slice(2, 9)}`;

const postiDisponibili = computed(() =>
    Math.max(0, props.rimasti - props.modelValue.length)
);

const suggerimento = computed(() =>
    transChoice('allegati.upload.hint_zona', postiDisponibili.value, {
        formati: formatiEtichetta.value,
        limite: props.limiteFile,
        rimasti: String(postiDisponibili.value),
        massimo: String(props.massimo),
    })
);

function formattaDimensione(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1).replace('.', ',')} KB`;
    }
    return `${(bytes / (1024 * 1024)).toFixed(2).replace('.', ',')} MB`;
}

async function elaboraFile(
    fileList: FileList | null,
    opzioni: { scattata?: boolean } = {},
) {
    if (!fileList || fileList.length === 0) {
        return;
    }

    const scelti = Array.from(fileList);
    const posti = Math.max(0, props.rimasti - props.modelValue.length);
    const daTrattare = scelti.slice(0, posti);
    if (daTrattare.length === 0) {
        return;
    }

    inPreparazione.value = true;
    try {
        // Decisione 296 (F1): una alla volta, in fila. Una foto da 12 megapixel decodificata occupa circa 48 MB: preparate tutte
        // insieme, su un telefono con poca memoria la scheda del browser si può chiudere.
        const preparati: File[] = [];
        for (const f of daTrattare) {
            preparati.push(await preparaImmagine(f, opzioni));
        }
        const uniti = [...props.modelValue, ...preparati].slice(0, props.rimasti);
        emit('update:modelValue', uniti);
    } finally {
        inPreparazione.value = false;
    }
}

async function onSelezionaFile(event: Event) {
    const target = event.target as HTMLInputElement;
    const files = target.files;
    try {
        await elaboraFile(files, { scattata: false });
    } finally {
        try {
            target.value = '';
        } catch {
            // ignora
        }
    }
}

async function onScattaFoto(event: Event) {
    const target = event.target as HTMLInputElement;
    const files = target.files;
    try {
        await elaboraFile(files, { scattata: true });
    } finally {
        try {
            target.value = '';
        } catch {
            // ignora
        }
    }
}

function rimuoviFile(indice: number) {
    if (inPreparazione.value) {
        return;
    }
    const copia = [...props.modelValue];
    copia.splice(indice, 1);
    emit('update:modelValue', copia);
}
</script>

<template>
    <div class="space-y-3">
        <div v-if="aspetto === 'zona'">
            <input
                ref="inputRef"
                type="file"
                multiple
                :accept="acceptAttr"
                class="hidden"
                :disabled="postiDisponibili <= 0 || inPreparazione"
                @change="onSelezionaFile"
            />
            <input
                v-if="mostraScattaFoto"
                ref="cameraRef"
                type="file"
                accept="image/*"
                capture="environment"
                data-campo="fotocamera"
                class="hidden"
                :disabled="postiDisponibili <= 0 || inPreparazione"
                @change="onScattaFoto"
            />
            <!-- Decisione 484: dove lo schermo si tocca, accanto alla barra un tasto «Foto», alto quanto lei e con lo stesso tratteggio. La barra
                 resta un pulsante a sé: una riga con la barra larga e il tasto stretto. Al computer la barra resta sola, com'era.
                 1.12.0-beta.12 (H4): con il tasto «Foto» la barra e il tasto hanno margini e spazi più stretti, così a 375 px «Aggiungi allegati» si legge
                 intero (prima «Aggiungi all…»); al computer, senza il tasto, la barra resta com'era. -->
            <div class="flex items-stretch gap-2">
                <button
                    type="button"
                    data-zona-allegati
                    :aria-describedby="idSuggerimento"
                    :disabled="postiDisponibili <= 0 || inPreparazione"
                    @click="inputRef?.click()"
                    :class="mostraScattaFoto ? 'gap-2 px-3' : 'gap-3 px-4'"
                    class="min-h-10 min-w-0 flex-1 flex items-center py-3 rounded-lg border border-dashed border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm text-slate-400 dark:text-slate-500 hover:border-slate-300 dark:hover:border-slate-600 hover:text-slate-600 dark:hover:text-slate-300 transition-all disabled:opacity-50 disabled:pointer-events-none"
                >
                    <Files class="w-4 h-4 shrink-0" />
                    <span class="truncate">{{ trans('allegati.upload.label') }}</span>
                    <TooltipProvider>
                        <Tooltip :delay-duration="200">
                            <TooltipTrigger as-child>
                                <span data-info-allegati class="ml-auto inline-flex text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                                    <Info class="w-4 h-4" />
                                </span>
                            </TooltipTrigger>
                            <TooltipContent side="top" :side-offset="5" class="max-w-xs space-y-1">
                                <p class="text-xs tabular-nums">{{ suggerimento }}</p>
                                <p v-if="haImmagini" class="text-xs">{{ trans('allegati.upload.photo_hint') }}</p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </button>
                <button
                    v-if="mostraScattaFoto"
                    type="button"
                    data-azione="foto-zona"
                    :aria-label="trans('allegati.upload.take_photo')"
                    :disabled="postiDisponibili <= 0 || inPreparazione"
                    @click="cameraRef?.click()"
                    class="min-h-10 shrink-0 inline-flex items-center gap-2 px-3 rounded-lg border border-dashed border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm text-slate-500 dark:text-slate-400 hover:border-slate-300 dark:hover:border-slate-600 hover:text-slate-700 dark:hover:text-slate-200 transition-all disabled:opacity-50 disabled:pointer-events-none"
                >
                    <Camera class="w-4 h-4 shrink-0" />
                    <span>{{ trans('allegati.upload.photo_short') }}</span>
                </button>
            </div>
            <!-- P2 della Fase 1-bis della beta.12: al tocco il suggerimento della «i» non si apre, e la «i» sta dentro la barra (il tocco apre la scelta dei
                 file). Dove c'è il tasto «Foto» i formati, i posti rimasti e la frase della foto si scrivono qui sotto, come nella forma «campo». -->
            <p v-if="mostraScattaFoto" data-riga="suggerimento-al-tocco" class="mt-1.5 text-xs text-muted-foreground tabular-nums">
                {{ suggerimento }} {{ trans('allegati.upload.photo_hint') }}
            </p>
            <span :id="idSuggerimento" class="sr-only">{{ suggerimento }}</span>
            <!-- Decisione 296 (F3): la regione c'è sempre, così il lettore di schermo annuncia la frase quando compare. -->
            <p role="status" class="text-xs text-muted-foreground" :class="inPreparazione ? 'mt-1.5' : ''"><span v-if="inPreparazione" data-stato="preparazione">{{ trans('allegati.upload.preparing') }}</span></p>
        </div>

        <div v-else class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <label class="text-sm font-medium flex items-center gap-1.5">
                    <Files class="h-4 w-4 text-muted-foreground" />
                    {{ trans('allegati.upload.label') }}
                </label>
                <p class="text-xs text-muted-foreground mt-0.5 tabular-nums">
                    {{
                        transChoice('allegati.upload.hint', postiDisponibili, {
                            formati: formatiEtichetta,
                            limite: limiteFile,
                            rimasti: String(postiDisponibili),
                            massimo: String(massimo),
                        })
                    }}
                </p>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ trans('allegati.upload.photo_hint') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Decisione 296 (F3): la regione c'è sempre, così il lettore di schermo annuncia la frase quando compare. -->
                <span role="status" class="text-xs text-muted-foreground"><span v-if="inPreparazione" data-stato="preparazione">{{ trans('allegati.upload.preparing') }}</span></span>

                <template v-if="mostraScattaFoto">
                    <input
                        ref="cameraRef"
                        type="file"
                        accept="image/*"
                        capture="environment"
                        data-campo="fotocamera"
                        class="hidden"
                        :disabled="postiDisponibili <= 0 || inPreparazione"
                        @change="onScattaFoto"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-azione="scatta-foto"
                        class="pointer-coarse:h-10"
                        :disabled="postiDisponibili <= 0 || inPreparazione"
                        @click="cameraRef?.click()"
                    >
                        <Camera class="h-4 w-4 mr-1.5" />
                        {{ trans('allegati.upload.take_photo') }}
                    </Button>
                </template>

                <input
                    ref="inputRef"
                    type="file"
                    multiple
                    :accept="acceptAttr"
                    class="hidden"
                    :disabled="postiDisponibili <= 0 || inPreparazione"
                    @change="onSelezionaFile"
                />
                <!-- Decisione 296 (F2): dove il puntatore è un dito i due pulsanti sono alti 40 px. -->
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="pointer-coarse:h-10"
                    :disabled="postiDisponibili <= 0 || inPreparazione"
                    @click="inputRef?.click()"
                >
                    <Files class="h-4 w-4 mr-1.5" />
                    {{ trans('allegati.upload.label') }}
                </Button>
            </div>
        </div>

        <ul v-if="modelValue.length > 0" class="divide-y rounded-md border bg-muted/20">
            <li
                v-for="(file, index) in modelValue"
                :key="`${file.name}-${index}`"
                class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
            >
                <div class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ file.name }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ formattaDimensione(file.size) }}
                    </span>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-7 px-2 text-muted-foreground hover:text-destructive"
                    :disabled="inPreparazione"
                    @click="rimuoviFile(index)"
                >
                    <X class="h-4 w-4" />
                    <span class="sr-only">{{ trans('allegati.upload.remove_file') }}</span>
                </Button>
            </li>
        </ul>

        <InputError v-if="error" :message="error" />
    </div>
</template>
