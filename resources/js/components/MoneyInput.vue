<script setup lang="ts">
import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    defaultValue?: string | number;
    modelValue?: string | number;
    class?: HTMLAttributes['class'];
    moneyOptions?: any;
    placeholder?: string;
    id?: string;
    type?: string;
    lazy?: boolean; // Aggiungi supporto esplicito per lazy
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void;
    (e: 'focus', payload: Event): void;
    (e: 'blur', payload: Event): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});

// Gestione speciale per il comportamento lazy
const handleInput = (event: Event) => {
    if (!props.lazy) {
        const target = event.target as HTMLInputElement;
        modelValue.value = target.value;
    }
};

const handleChange = (event: Event) => {
    if (props.lazy) {
        const target = event.target as HTMLInputElement;
        modelValue.value = target.value;
    }
};

const handleFocus = (event: Event) => {
    emits('focus', event);
};

const handleBlur = (event: Event) => {
    emits('blur', event);
};
</script>

<template>
    <input
        :id="id"
        :type="type || 'text'"
        :value="modelValue"
        v-money3="moneyOptions"
        :placeholder="placeholder"
        :class="
            cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                props.class,
            )
        "
        @input="handleInput"
        @change="handleChange"
        @focus="handleFocus"
        @blur="handleBlur"
    />
</template>