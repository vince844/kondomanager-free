<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import InputError from '@/components/InputError.vue';
import { Files, Info, X } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { trans, transChoice } from 'laravel-vue-i18n';

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

function onSelezionaFile(event: Event) {
    const target = event.target as HTMLInputElement;
    if (!target.files || target.files.length === 0) {
        return;
    }

    const scelti = Array.from(target.files);
    const uniti = [...props.modelValue, ...scelti].slice(0, props.rimasti);
    emit('update:modelValue', uniti);
    target.value = '';
}

function rimuoviFile(indice: number) {
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
                :disabled="postiDisponibili <= 0"
                @change="onSelezionaFile"
            />
            <button
                type="button"
                data-zona-allegati
                :aria-describedby="idSuggerimento"
                :disabled="postiDisponibili <= 0"
                @click="inputRef?.click()"
                class="w-full flex items-center gap-3 px-4 py-3 rounded-lg border border-dashed border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-950 text-sm text-slate-400 dark:text-slate-500 hover:border-slate-300 dark:hover:border-slate-600 hover:text-slate-600 dark:hover:text-slate-300 transition-all disabled:opacity-50 disabled:pointer-events-none"
            >
                <Files class="w-4 h-4 shrink-0" />
                <span>{{ trans('allegati.upload.label') }}</span>
                <TooltipProvider>
                    <Tooltip :delay-duration="200">
                        <TooltipTrigger as-child>
                            <span data-info-allegati class="ml-auto inline-flex text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                                <Info class="w-4 h-4" />
                            </span>
                        </TooltipTrigger>
                        <TooltipContent side="top" :side-offset="5" class="max-w-xs">
                            <p class="text-xs tabular-nums">{{ suggerimento }}</p>
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </button>
            <span :id="idSuggerimento" class="sr-only">{{ suggerimento }}</span>
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
            </div>

            <div>
                <input
                    ref="inputRef"
                    type="file"
                    multiple
                    :accept="acceptAttr"
                    class="hidden"
                    :disabled="postiDisponibili <= 0"
                    @change="onSelezionaFile"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="postiDisponibili <= 0"
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

