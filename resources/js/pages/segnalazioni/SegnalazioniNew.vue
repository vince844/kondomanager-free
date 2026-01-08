<script setup lang="ts">

import {Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Plus, List, Info } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';
import { priorityConstants, statoConstants, publishedConstants } from '@/lib/segnalazioni/constants';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { PriorityType, StatoType, PublishedType } from '@/types/segnalazioni';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps<{
  condomini: Building[];
  anagrafiche: Anagrafica[];
}>();  

const { generateRoute } = usePermission();

const form = useForm({
    subject: '',
    description: '',
    priority: '',
    stato: '',
    condominio_id: '',
    can_comment: false as boolean,
    is_featured: false as boolean,
    is_published: true,
    anagrafiche: [],
});

const submit = () => {
    form.post(route(generateRoute('segnalazioni.store')), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head :title="trans('segnalazioni.header.new_ticket_head')" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading 
            :title="trans('segnalazioni.header.new_ticket_title')" 
            :description="trans('segnalazioni.header.new_ticket_description')" 
        />

        <form class="space-y-2" @submit.prevent="submit">

            <!-- Action buttons -->
            <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
                <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
                    <Plus class="w-4 h-4" v-if="!form.processing" />
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{trans('segnalazioni.actions.save_ticket')}}
                </Button>

                <Link
                    as="button"
                    :href="route(generateRoute('segnalazioni.index'))"
                    class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
                >
                    <List class="w-4 h-4" />
                    <span>{{ trans('segnalazioni.actions.list_tickets') }}</span>
                </Link>
            </div>

            <!-- Two-column layout (3:1 ratio) -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

                <!-- Main Card (3/4 width) -->
                <div class="col-span-1 lg:col-span-3 mt-3">
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
                        
                        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <Label for="subject">{{ trans('segnalazioni.label.object') }}</Label>
                                <Input 
                                    id="subject" 
                                    class="mt-1 block w-full"
                                    v-model="form.subject" 
                                    v-on:focus="form.clearErrors('subject')"
                                    :placeholder="trans('segnalazioni.placeholder.object')" 
                                />
                                
                                <InputError :message="form.errors.subject" />
                    
                            </div>
                        </div> 

                        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="description">{{ trans('segnalazioni.label.description') }}</Label>
                                <Textarea 
                                    id="description" 
                                    class="mt-1 block w-full min-h-[320px]"
                                    v-model="form.description" 
                                    v-on:focus="form.clearErrors('description')"
                                    :placeholder="trans('segnalazioni.placeholder.description')" 
                                />
                                
                                <InputError :message="form.errors.description" />
                    
                            </div>     
                        </div> 

                    </div>
                </div>

                <!-- Side Card (1/4 width) -->
                <div class="col-span-1 mt-3">
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

                        <div class="grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">

                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="visibility">{{ trans('segnalazioni.label.visibility') }}</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                                {{ trans('segnalazioni.label.visibility') }}
                                            </h4>
                                            <p class="text-sm">
                                                {{ trans('segnalazioni.tooltip.visibility') }}
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
                                    :options="publishedConstants" 
                                    label="label" 
                                    v-model="form.is_published"
                                    :placeholder="trans('segnalazioni.placeholder.visibility')"
                                    @update:modelValue="form.clearErrors('is_published')" 
                                    :reduce="(option: PublishedType) => option.value"
                                >
                                    <template #option="{ label, icon }">
                                        <div class="flex items-center gap-2">
                                            <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                            <span>{{ trans(label) }}</span> 
                                        </div>
                                    </template>

                                    <template #selected-option="{ label, icon }">
                                        <div class="flex items-center gap-2">
                                            <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                            <span>{{ trans(label) }}</span>
                                        </div>
                                    </template>
                                </v-select>

                                <InputError :message="form.errors.is_published" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">

                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="priority">{{ trans('segnalazioni.label.priority') }}</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                               {{ trans('segnalazioni.label.priority') }}
                                            </h4>
                                            <p class="text-sm">
                                                {{ trans('segnalazioni.tooltip.priority') }}
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
                                    :options="priorityConstants" 
                                    label="label" 
                                    v-model="form.priority"
                                    :placeholder="trans('segnalazioni.placeholder.priority')"
                                    @update:modelValue="form.clearErrors('priority')" 
                                    :reduce="(priority: PriorityType) => priority.value"
                                >
                                <!-- Dropdown list items -->
                                <template #option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span>
                                    </div>
                                </template>

                                <!-- Selected option display -->
                                <template #selected-option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                                </v-select>

                                <InputError :message="form.errors.priority" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">

                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="stato">{{ trans('segnalazioni.label.status') }}</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                               {{ trans('segnalazioni.label.status') }}
                                            </h4>
                                            <p class="text-sm">
                                                {{ trans('segnalazioni.tooltip.status') }}
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
                                    :options="statoConstants" 
                                    label="label" 
                                    v-model="form.stato"
                                    :placeholder="trans('segnalazioni.placeholder.status')"
                                    @update:modelValue="form.clearErrors('stato')" 
                                    :reduce="(stato: StatoType) => stato.value"
                                >
                                <!-- Dropdown list items -->
                                <template #option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span>
                                    </div>
                                </template>

                                <!-- Selected option display -->
                                <template #selected-option="{ label, icon }">
                                    <div class="flex items-center gap-2">
                                    <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                    <span>{{ trans(label) }}</span>
                                    </div>
                                </template>
                                </v-select>

                                <InputError :message="form.errors.stato" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="condomini">{{ trans('segnalazioni.label.building') }}</Label>

                                <v-select 
                                    :options="condomini" 
                                    label="nome" 
                                    v-model="form.condominio_id"
                                    :placeholder="trans('segnalazioni.placeholder.building')"
                                    @update:modelValue="form.clearErrors('condominio_id')" 
                                    :reduce="(condominio: Building) => condominio.id"
                                />

                                <InputError :message="form.errors.condominio_id" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="anagrafiche">{{ trans('segnalazioni.label.resident') }}</Label>

                                <v-select 
                                    multiple
                                    :options="anagrafiche" 
                                    label="nome" 
                                    v-model="form.anagrafiche"
                                    :placeholder="trans('segnalazioni.placeholder.resident')"
                                    @update:modelValue="form.clearErrors('anagrafiche')" 
                                    :reduce="(anagrafica: Anagrafica) => anagrafica.id"
                                />

                                <InputError :message="form.errors.anagrafiche" />
                    
                            </div>
                        </div>

                        <Separator class="my-4" />

                        <div class="grid grid-cols-1 sm:grid-cols-6">
                            <div class="flex items-center space-x-2 sm:col-span-6">
                                <Checkbox 
                                    class="size-4" 
                                    :checked="form.can_comment"
                                    v-model="form.can_comment" 
                                    id="can_comment" 
                                    @update:checked="(val: boolean) => form.can_comment = val" 
                                    />
                                <label
                                    for="comments"
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                    >
                                    {{ trans('segnalazioni.label.comments') }}
                                </label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80">
                                    <div class="flex justify-between space-x-4">
                                        <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">
                                                {{ trans('segnalazioni.label.comments') }}
                                            </h4>
                                            <p class="text-sm">
                                                {{ trans('segnalazioni.tooltip.comments') }}
                                            </p>
                                        </div>
                                    </div>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>
                        </div>

                        <div class="pt-4 grid grid-cols-1 sm:grid-cols-6">
                            <div class="flex items-center space-x-2 sm:col-span-6">
                                <Checkbox 
                                    class="size-4" 
                                    :checked="form.is_featured"
                                    v-model="form.is_featured" 
                                    id="is_featured" 
                                    @update:checked="(val: boolean) => form.is_featured = val" 
                                    />
                                <label
                                    for="featured"
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                    >
                                      {{ trans('segnalazioni.label.featured') }}
                                </label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                    <button type="button" class="cursor-pointer">
                                        <Info class="w-4 h-4 text-muted-foreground" />
                                    </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80 z-50">
                                    <div class="flex justify-between space-x-4">
                                        <div class="space-y-1">
                                        <h4 class="text-sm font-semibold">{{ trans('segnalazioni.label.featured') }}</h4>
                                        <p class="text-sm">
                                            {{ trans('segnalazioni.tooltip.featured') }}
                                        </p>
                                        </div>
                                    </div>
                                    </HoverCardContent>
                                </HoverCard>

                            </div>
                        </div>
                        
                    </div>
                </div>
            
            </div>

        </form>

      </div>
      
    </AppLayout> 
  
</template>

<style src="vue-select/dist/vue-select.css"></style>