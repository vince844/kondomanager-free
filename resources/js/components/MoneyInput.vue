<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import { Money3Component, format, unformat } from 'v-money3';
import { computed, ref, watch, nextTick } from 'vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    defaultValue?: string | number;
    modelValue?: string | number;
    class?: HTMLAttributes['class'];
    moneyOptions?: any;
    placeholder?: string;
    id?: string;
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void;
    (e: 'focus', payload: Event): void;
    (e: 'blur', payload: Event): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    defaultValue: props.defaultValue,
});

// Stato per tracciare se stiamo provando a inserire un negativo partendo da zero
const isInputtingNegative = ref(false);

// Opzioni dinamiche: se stiamo inserendo il meno, permettiamo il campo "blank" temporaneamente.
// Questo impedisce alla libreria di forzare subito "0,00" quando vede solo un "-"
const computedMoneyOptions = computed(() => {
    return {
        ...props.moneyOptions,
        // Sovrascriviamo allowBlank solo se stiamo digitando il negativo iniziale
        allowBlank: props.moneyOptions?.allowBlank || isInputtingNegative.value
    };
});

const handleFocus = (event: Event) => {
    emits('focus', event);
};

const handleBlur = (event: Event) => {
    isInputtingNegative.value = false; // Reset dello stato al blur
    
    // Se l'utente esce lasciando solo "-", resettiamo a zero per pulizia
    if (modelValue.value === '-') {
        modelValue.value = format('0', props.moneyOptions);
    }
    emits('blur', event);
};

const handleKeyDown = (e: KeyboardEvent) => {
    // Intercettiamo il tasto meno (sia tastiera standard che tastierino numerico)
    if (e.key === '-' || e.key === 'Subtract') {
        const currentRaw = unformat(String(modelValue.value), props.moneyOptions);
        const currentNumber = Number(currentRaw);
        
        // CASO 1: Il valore è Zero (o vuoto)
        if (currentNumber === 0) {
            e.preventDefault();
            // Attiviamo la modalità "allowBlank" temporanea
            isInputtingNegative.value = true;
            
            // Forziamo il valore a essere solo il segno meno
            nextTick(() => {
                modelValue.value = '-';
            });
        } 
        // CASO 2: Il valore è diverso da Zero (es. 100 o -50)
        else {
            e.preventDefault();
            // Invertiamo matematicamente il segno (Toggle)
            const newVal = currentNumber * -1;
            const formattedNewVal = format(String(newVal), props.moneyOptions);
            modelValue.value = formattedNewVal;
        }
    }
};

// Osserviamo il valore: appena l'utente digita un numero dopo il meno (es. "-5"), 
// disabilitiamo la modalità speciale per tornare alle regole normali (allowBlank: false).
watch(modelValue, (newVal) => {
    if (newVal && newVal !== '-' && isInputtingNegative.value) {
        isInputtingNegative.value = false;
    }
});
</script>

<template>
    <Money3Component
        :id="id"
        v-model="modelValue"
        v-bind="computedMoneyOptions"
        :placeholder="placeholder"
        :class="
            cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                props.class 
            )
        "
        @focus="handleFocus"
        @blur="handleBlur"
        @keydown="handleKeyDown"
    />
</template>