<script setup>
import {useToast} from "vue-toastification";
import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Button from "../../components/forms/Button.vue";

const toast = useToast();
const fmSettings = ref({
  frequency: '',
});

onMounted(() => {
  fetchFMSettings();
});

function fetchFMSettings() {
  SettingsService.fetchFMSettings().then(response => {
    fmSettings.value = response;
  }).catch(error => {
    console.error(error);
  });
}

function saveFMSettings() {
  SettingsService.saveFMSettings(fmSettings.value).then(() => {
    toast.success('Nastavení FM rádia bylo úspěšně uloženo');
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení FM rádia');
  });
}

const cantSave = computed(() => {
  let retVal = false;
  if (fmSettings.value.frequency === '') {
    retVal = true;
  }
  return retVal;
});
</script>

<template>
  <PageContent label="Nastavení FM rádia">
    <Box label="Nastavení spojení">
      <Input v-model="fmSettings.frequency" label="Frekvence rádia:" badge="MHz" placeholder="Např.: 103.3"/>
      <div class="flex justify-end">
        <Button @click="saveFMSettings" icon="mdi-content-save" label="Uložit" size="sm" :disabled="cantSave"/>
      </div>
    </Box>
  </PageContent>
</template>

<style scoped>
</style>