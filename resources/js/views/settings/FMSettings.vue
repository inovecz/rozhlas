<script setup>
import {useToast} from "vue-toastification";
import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";

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
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Nastavení FM rádia</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nastavení spojení
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <label for="fm-frequency" class="label">
            <span class="label-text">Frekvence rádia [MHz]:</span>
          </label>
          <div class="form-control w-full">
            <input id="fm-frequency" v-model="fmSettings.frequency" type="text" placeholder="Např.: 103.3" class="input input-bordered w-full"/>
          </div>
          <div class="col-span-1 md:col-span-2 mt-4 flex justify-end">
            <button class="btn btn-primary" @click="saveFMSettings" :disabled="cantSave">Uložit</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>