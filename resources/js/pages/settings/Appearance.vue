<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Ajustes de apariencia',
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Ajutes de apariencia" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Ajutes de apariencia" description="Configura la apariencia de la aplicación" />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
