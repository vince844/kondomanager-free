<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La scelta del condominio fra quelli dello studio, con la ricerca, sul modello del selettore della dashboard social (`BuildingsDropdown.vue`): un
 * riquadro (`Popover`) con il campo «Cerca condominio…» e l'elenco (`Command`) che scorre dentro il riquadro, alto al massimo 300 pixel
 * (`CommandList`), e «Nessun condominio trovato.» quando la ricerca non trova niente. Il condominio in uso è segnato, in grassetto e con la spunta.
 *
 * Il pulsante che apre il riquadro lo mette chi usa il componente (lo spazio di serie), così ognuno tiene il suo aspetto; la scelta torna con
 * `scegli`, e dove portarla lo decide chi la riceve. Con `tutti` c'è una prima voce per «nessun condominio», che torna null (un filtro).
 *
 * È un menu di navigazione e di filtro, non un campo di un modulo: i menu dei moduli si fanno con `vSelect`.
 */
import { ref } from 'vue';
import { Building2, Check, Inbox } from 'lucide-vue-next';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

const props = defineProps<{
    condomini: Array<{ id: number | string; nome: string }>;
    /** Il condominio in uso; null quando non ce n'è uno (con `tutti`, la sua voce è segnata). */
    scelto: number | string | null;
    /** Il nome della voce «nessun condominio», per un filtro: senza, la voce non c'è. */
    tutti?: string;
}>();
const emit = defineEmits<{ (e: 'scegli', id: number | string | null): void }>();

const aperto = ref(false);
const inUso = (id: number | string | null) => (id === null ? props.scelto === null : props.scelto !== null && String(id) === String(props.scelto));

function scegli(id: number | string | null) {
    aperto.value = false;
    emit('scegli', id);
}
</script>

<template>
    <Popover v-model:open="aperto">
        <PopoverTrigger as-child>
            <slot :aperto="aperto" />
        </PopoverTrigger>
        <PopoverContent align="end" class="w-[300px] min-w-[var(--reka-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0" data-testid="scelta-condominio">
            <Command>
                <CommandInput placeholder="Cerca condominio…" />
                <CommandEmpty>Nessun condominio trovato.</CommandEmpty>
                <CommandList>
                    <CommandGroup>
                        <CommandItem v-if="tutti" value="__tutti" :aria-current="inUso(null) ? 'true' : undefined" :class="{ 'font-semibold': inUso(null) }"
                            @select="scegli(null)">
                            <Inbox class="opacity-60" />
                            <span class="truncate">{{ tutti }}</span>
                            <Check v-if="inUso(null)" class="ml-auto text-primary" data-testid="in-uso" />
                        </CommandItem>
                        <CommandItem v-for="c in condomini" :key="c.id" :value="`${c.id} ${c.nome}`" :aria-current="inUso(c.id) ? 'true' : undefined"
                            :class="{ 'font-semibold': inUso(c.id) }" @select="scegli(c.id)">
                            <Building2 class="opacity-60" />
                            <span class="truncate">{{ c.nome }}</span>
                            <Check v-if="inUso(c.id)" class="ml-auto text-primary" data-testid="in-uso" />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
