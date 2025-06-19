<script setup>
import {useToast} from "vue-toastification";
import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";
import PageContent from "../../components/custom/PageContent.vue";
import Select from "../../components/forms/Select.vue";
import Checkbox from "../../components/forms/Checkbox.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Button from "../../components/forms/Button.vue";

const toast = useToast();
const twoWayCommSettings = ref({
  type: 'none',
  spam: false,
  nestStatusAutoUpdate: false,
  nestFirstReadTime: '00:00',
  nestNextReadInterval: 360,
  sensorStatusAutoUpdate: false,
  sensorFirstReadTime: '00:00',
  sensorNextReadInterval: 120,
});
const errorBag = ref({});

onMounted(() => {
  fetchTwoWayCommSettings();
});

function fetchTwoWayCommSettings() {
  SettingsService.fetchTwoWayCommSettings().then(response => {
    twoWayCommSettings.value = response;
  }).catch(error => {
    console.error(error);
  });
}

function saveTwoWayCommSettings() {
  SettingsService.saveTwoWayCommSettings(twoWayCommSettings.value).then(() => {
    toast.success('Nastavení obousměrné komunikace bylo úspěšně uloženo');
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení obousměrné komunikace');
  });
}

const cantSave = computed(() => {
  let retVal = false;
  errorBag.value = {};
  if (twoWayCommSettings.value.nestStatusAutoUpdate && (twoWayCommSettings.value.nestFirstReadTime === null || twoWayCommSettings.value.nestFirstReadTime === '')) {
    errorBag.value.nestFirstReadTime = 'Hodnota je povinná, pokud je automatické vyčítání zapnuto';
    retVal = true;
  }
  if (twoWayCommSettings.value.nestStatusAutoUpdate && (twoWayCommSettings.value.nestNextReadInterval === null || twoWayCommSettings.value.nestNextReadInterval === '')) {
    errorBag.value.nestNextReadInterval = 'Hodnota je povinná, pokud je automatické vyčítání zapnuto';
    retVal = true;
  }
  if (twoWayCommSettings.value.sensorStatusAutoUpdate && (twoWayCommSettings.value.sensorFirstReadTime === null || twoWayCommSettings.value.sensorFirstReadTime === '')) {
    errorBag.value.sensorFirstReadTime = 'Hodnota je povinná, pokud je automatické vyčítání zapnuto';
    retVal = true;
  }
  if (twoWayCommSettings.value.sensorStatusAutoUpdate && (twoWayCommSettings.value.sensorNextReadInterval === null || twoWayCommSettings.value.sensorNextReadInterval === '')) {
    errorBag.value.sensorNextReadInterval = 'Hodnota je povinná, pokud je automatické vyčítání zapnuto';
    retVal = true;
  }
  return retVal;
});
</script>

<template>
  <PageContent label="Nastavení obousměrné komunikace">
    <Box label="Typ komunikace">
      <Select v-model="twoWayCommSettings.type" label="Typ obousměru:" :options="[
        {value: 'NONE', label: 'Žádný'},
        {value: 'EIGHTSIXEIGHT', label: '868 Mhz'},
        {value: 'EIGHTZERO', label: '80 Mhz'},
        {value: 'DIGITAL', label: 'Digitální'},
      ]"/>
      <Checkbox v-model="twoWayCommSettings.spam" label="Nevyžádané zprávy"/>
    </Box>
    <Box label="Nastavení automatické aktualizace stavu hnízd">
      <Checkbox v-model="twoWayCommSettings.nestStatusAutoUpdate" label="Automatické vyčítání stavu hnízd"/>
      <Input v-model="twoWayCommSettings.nestFirstReadTime" :error="errorBag?.nestFirstReadTime" label="Čas prvního vyčítání:" type="time" placeholder="Zvolte čas prvního vyčítání"/>
      <Input v-model="twoWayCommSettings.nestNextReadInterval" :error="errorBag?.nestNextReadInterval" label="Interval dalších vyčítání během dne [min]:" type="number" placeholder="Zvolte prodlevu mezi následujícími vyčítáními"/>
    </Box>
    <Box label="Nastavení automatické aktualizace stavu senzorů">
      <Checkbox v-model="twoWayCommSettings.sensorStatusAutoUpdate" label="Automatické vyčítání stavu senzorů"/>
      <Input v-model="twoWayCommSettings.sensorFirstReadTime" :error="errorBag?.sensorFirstReadTime" label="Čas prvního vyčítání:" type="time" placeholder="Zvolte čas prvního vyčítání"/>
      <Input v-model="twoWayCommSettings.sensorNextReadInterval" :error="errorBag?.sensorNextReadInterval" label="Interval dalších vyčítání během dne [min]:" type="number" placeholder="Zvolte prodlevu mezi následujícími vyčítáními"/>
    </Box>

    <div class="flex justify-end">
      <Button @click="saveTwoWayCommSettings" icon="mdi-content-save" label="Uložit" size="sm" :disabled="cantSave"/>
    </div>
  </PageContent>
</template>

<style scoped>
</style>