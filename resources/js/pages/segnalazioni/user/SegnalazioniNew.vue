<script setup lang="ts">

import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle, Plus, List, Info } from 'lucide-vue-next';
import vSelect from "vue-select";
import { priorityConstants, statoConstants } from '@/lib/segnalazioni/constants';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { usePermission } from "@/composables/permissions";
import type { Building } from '@/types/buildings';
import type { PriorityType, StatoType } from '@/types/segnalazioni';

const props = defineProps<{
  condomini: Building[];
}>();  

const { generateRoute } = usePermission();

const form = useForm({
    subject: '',
    description: '',
    priority: '',
    stato: '',
    condominio_id: '',
    is_private: false as boolean,
});

const submit = () => {
    form.post(route("user.segnalazioni.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head :title="trans('segnalazioni.header.new_ticket_head')" />
  
    <AppLayout>
  
      <div class="px-4 py-6">
        
        <Heading 
            :title="trans('segnalazioni.header.new_ticket_title')" 
            :description="trans('segnalazioni.header.new_ticket_description')" 
        />

            <form class="space-y-2" @submit.prevent="submit">

                <!-- Container for buttons (wraps buttons for alignment) -->
                <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">

                    <Button :disabled="form.processing" class="lg:flex h-8 w-full lg:w-auto">
                        <Plus class="w-4 h-4" v-if="!form.processing" />
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                         {{trans('segnalazioni.actions.save_ticket')}}
                    </Button>

                    <Button type="button" class="lg:flex h-8 w-full lg:w-auto">
                        <List class="w-4 h-4" />
                        <Link :href="route(generateRoute('segnalazioni.index'))" class="block lg:inline">
                        {{ trans('segnalazioni.actions.list_tickets') }}
                        </Link>
                    </Button>

                </div>

                <!-- Two-column layout (3:1 ratio) -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

                    <!-- Main Card (3/4 width) -->
                    <div class="col-span-1 lg:col-span-3 mt-3">
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-6 space-y-4 border">
                            
                            <!--  subject field -->
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
                                    <Label for="condominio">{{ trans('segnalazioni.label.building') }}</Label>

                                    <v-select 
                                        :options="condomini" 
                                        label="label" 
                                        v-model="form.condominio_id"
                                        :placeholder="trans('segnalazioni.placeholder.building')"
                                        @update:modelValue="form.clearErrors('condominio_id')" 
                                        :reduce="(condominio: Building) => condominio.value"
                                    />

                                    <InputError :message="form.errors.condominio_id" />
                        
                                </div>
                            </div>

                            <Separator class="my-4" />

                            <div class="grid grid-cols-1 sm:grid-cols-6">
                                <div class="flex items-center space-x-2 sm:col-span-6">
                                    <Checkbox 
                                        class="size-4" 
                                        :checked="form.is_private"
                                        v-model="form.is_private" 
                                        id="is_private" 
                                        @update:checked="(val: boolean) => form.is_private = val" 
                                        />
                                    <label
                                        for="is_private"
                                        class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-7 flex items-center"
                                        >
                                        {{trans('segnalazioni.label.private')}}

                                        <HoverCard>
                                            <HoverCardTrigger as-child>
                                                <Info class="w-4 h-4 text-muted-foreground cursor-pointer ml-2" />
                                            </HoverCardTrigger>
                                            <HoverCardContent class="w-80">
                                            <div class="flex justify-between space-x-4">
                                                <div class="space-y-1">
                                                    <h4 class="text-sm font-semibold">
                                                        {{trans('segnalazioni.label.private')}}
                                                    </h4>
                                                    <p class="text-sm">
                                                        {{trans('segnalazioni.tooltip.private')}}
                                                    </p>
                                                </div>
                                            </div>
                                            </HoverCardContent>
                                        </HoverCard>

                                    </label>
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