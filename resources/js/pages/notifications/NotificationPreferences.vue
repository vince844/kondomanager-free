<script setup>

import { ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';

const props = defineProps({
  preferences: Array
});

const form = ref({});

props.preferences.forEach(pref => {
  form.value[pref.type] = pref.enabled;
});

function submit() {
  const payload = Object.entries(form.value).map(([type, enabled]) => ({ type, enabled }));
  router.put(route('user.preferences.notifications.update'), { preferences: payload });
}
</script>

<template>
  <form @submit.prevent="submit" class="space-y-6">
    <h2 class="text-xl font-semibold">Email Notification Preferences</h2>
    <div v-for="pref in preferences" :key="pref.type">
      <label class="flex items-center gap-2">
        <input type="checkbox" v-model="form[pref.type]" />
        <div>
          <div class="font-medium">{{ pref.label }}</div>
          <div class="text-sm text-gray-600">{{ pref.description }}</div>
        </div>
      </label>
    </div>
    <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-500">Save</button>
  </form>
</template>
