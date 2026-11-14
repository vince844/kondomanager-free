<script setup lang="ts">
/**
 * Un campo per scegliere un file solo, in italiano: il pulsante «Scegli il file» (o «Cambia il file»), accanto il nome del file scelto o «Nessun file
 * scelto», e la × per toglierlo. Il campo del browser resta nascosto, perché il suo pulsante parla la lingua del browser («Choose file»). Quando il
 * file torna vuoto da fuori (dopo un salvataggio, per esempio), il campo si svuota anche lui, così lo stesso file si può scegliere di nuovo.
 */
import { ref, watch } from 'vue';
import { Paperclip, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = withDefaults(defineProps<{ modelValue: File | null; accept?: string; testid?: string; disabled?: boolean }>(), {
    accept: undefined,
    testid: 'campo-file',
    disabled: false,
});
const emit = defineEmits<{ 'update:modelValue': [file: File | null] }>();

const campo = ref<HTMLInputElement | null>(null);
const scelto = (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).files?.[0] ?? null);
const togli = () => emit('update:modelValue', null);
watch(() => props.modelValue, (file) => {
    if (file === null && campo.value) {
        campo.value.value = '';
    }
});
</script>

<template>
    <div class="flex items-center gap-2 min-h-8 min-w-0">
        <input ref="campo" type="file" :accept="accept" class="hidden" :data-testid="testid" :disabled="disabled" @change="scelto" />
        <Button type="button" variant="outline" size="sm" class="h-8 text-xs gap-1.5 shrink-0" :disabled="disabled" data-testid="scegli-file" @click="campo?.click()">
            <Paperclip class="w-3.5 h-3.5" /> {{ modelValue ? 'Cambia il file' : 'Scegli il file' }}
        </Button>
        <span class="truncate text-xs" :class="modelValue ? 'text-slate-700 dark:text-slate-200' : 'text-slate-400'" :title="modelValue?.name" data-testid="nome-file">
            {{ modelValue ? modelValue.name : 'Nessun file scelto' }}
        </span>
        <Button v-if="modelValue" type="button" variant="ghost" size="sm" class="h-7 w-7 p-0 shrink-0" aria-label="Togli il file" data-testid="togli-file" :disabled="disabled" @click="togli">
            <X class="w-3.5 h-3.5" />
        </Button>
    </div>
</template>
