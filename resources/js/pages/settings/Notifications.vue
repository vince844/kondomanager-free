<script setup lang="ts">

import { computed } from 'vue';
import { Head, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import type { Flash } from '@/types/flash';
import { LoaderCircle } from 'lucide-vue-next';

const { generateRoute } = usePermission();

// Extract `$page` props with proper typing
const page = usePage<{ flash: { message?: Flash } }>();
// Computed property to safely access flash messages
const flashMessage = computed(() => page.props.flash.message);

const props = defineProps<{
  preferences: Array<{
    type: string;
    label: string;
    description?: string;
    enabled: boolean;
  }>;
}>();

// Initialize form using useForm
const form = useForm({
  preferences: props.preferences.map(pref => ({
    type: pref.type,
    enabled: pref.enabled
  }))
});

function updateEnabled(type: string, value: boolean) {
  const target = form.preferences.find(pref => pref.type === type);
  if (target) target.enabled = value;
}

function submit() {
  form.put(route(generateRoute('settings.notifications.update')), {
    preserveScroll: true
  });
}

</script>

<template>
    <AppLayout>
      <Head title="Impostazioni notifiche" />

      <SettingsLayout>

        <div v-if="flashMessage"  class="py-2"> 
          <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <div class="flex flex-col space-y-6 max-w-3xl mx-auto">
          <HeadingSmall title="Impostazioni notifiche" description="Seleziona le notifiche che vuoi ricevere" />
  
          <form @submit.prevent="submit">
    
            <Card class="border-none shadow-none pl-0">
              <CardContent class="space-y-4 pl-0">
                <div
                  v-for="pref in props.preferences"
                  :key="pref.type"
                  class="flex items-center justify-between gap-4"
                >
                  <div>
                    <Label class="block text-sm font-medium leading-none">{{ pref.label }}</Label>
                    <p class="text-sm text-muted-foreground">{{ pref.description }}</p>
                  </div>
  
                  <Switch
                    :model-value="form.preferences.find(p => p.type === pref.type)?.enabled"
                    @update:modelValue="val => updateEnabled(pref.type, val)"
                  />
                </div>
              </CardContent>
  
              <CardFooter class="pl-0 flex items-center gap-4">

                <Button :disabled="form.processing" >
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  Salva preferenze
                </Button>

              </CardFooter>
            </Card>
          </form>
        </div>
      </SettingsLayout>
    </AppLayout>
  </template>
