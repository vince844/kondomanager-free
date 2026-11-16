<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 42" :class="className" v-bind="$attrs">
        <text
            x="50%"
            y="50%"
            dominant-baseline="middle"
            text-anchor="middle"
            font-size="28"
            font-family="Arial, sans-serif"
            fill="currentColor"
            font-weight="bold"
        >
            Km
        </text>
    </svg>
</template>
