<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const breadcrumbItems: BreadcrumbItem[] = [
    {
        title:  t('settings.appearance'),
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head :title="$t('settings.appearance')" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall :title="$t('settings.appearance')" 
                :description="$t('settings.appearance_description')" />
                <AppearanceTabs />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
