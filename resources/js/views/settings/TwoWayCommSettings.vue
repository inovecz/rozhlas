<script setup>
import {useToast} from "vue-toastification";
import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";

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
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Nastavení obousměrné komunikace</h1>
    <div class="content flex flex-col space-y-4">

      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Typ komunikace
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <label for="2w-type" class="label">
            <span class="label-text">Typ obousměru:</span>
          </label>
          <div class="form-control w-full">
            <select id="2w-type" v-model="twoWayCommSettings.type" class="select select-bordered w-full">
              <option value="NONE">Žádný</option>
              <option value="EIGHTSIXEIGHT">868 Mhz</option>
              <option value="EIGHTZERO">80 Mhz</option>
              <option value="DIGITAL">Digitální</option>
            </select>
          </div>

          <div class="form-control col-span-1 md:col-span-2">
            <label class="label cursor-pointer">
              <span class="label-text">Nevyžádané zprávy</span>
              <input v-model="twoWayCommSettings.spam" type="checkbox" class="checkbox"/>
            </label>
          </div>
        </div>
      </div>

      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nastavení automatické aktualizace stavu hnízd
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <div class="form-control col-span-1 md:col-span-2">
            <label class="label cursor-pointer">
              <span class="label-text">Automatické vyčítání stavu hnízd</span>
              <input v-model="twoWayCommSettings.nestStatusAutoUpdate" type="checkbox" class="checkbox"/>
            </label>
          </div>

          <label for="2w-nest-first-read" class="label">
            <span class="label-text">Čas prvního vyčítání: {{ typeof twoWayCommSettings.nestFirstReadTime }}</span>
          </label>
          <div class="form-control w-full">
            <input id="2w-nest-first-read" type="time" :class="'input input-bordered w-full'+(errorBag?.nestFirstReadTime ? 'input-error' : '' )"
                   v-model="twoWayCommSettings.nestFirstReadTime"
                   placeholder="Zvolte čas prvního vyčítání"/>
            <div class="label">
              <span v-if="errorBag?.nestFirstReadTime" class="label-text-alt text-red-500"><span class="mdi mdi-alert-circle-outline mr-1"></span>{{ errorBag.nestFirstReadTime }}</span>
            </div>
          </div>

          <label for="2w-nest-next-interval" class="label">
            <span class="label-text">Interval dalších vyčítání během dne [min]:</span>
          </label>
          <div class="form-control w-full">
            <input id="2w-nest-next-interval" type="number" :class="'input input-bordered w-full'+(errorBag?.nestNextReadInterval ? 'input-error' : '' )"
                   v-model="twoWayCommSettings.nestNextReadInterval"
                   placeholder="Zvolte prodlevu mezi následujícími vyčítáními"/>
            <div class="label">
              <span v-if="errorBag?.nestNextReadInterval" class="label-text-alt text-red-500"><span class="mdi mdi-alert-circle-outline mr-1"></span>{{ errorBag.nestNextReadInterval }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nastavení automatické aktualizace stavu senzorů
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
          <div class="form-control col-span-1 md:col-span-2">
            <label class="label cursor-pointer">
              <span class="label-text">Automatické vyčítání stavu senzorů</span>
              <input v-model="twoWayCommSettings.sensorStatusAutoUpdate" type="checkbox" class="checkbox"/>
            </label>
          </div>

          <label for="2w-sensor-first-read" class="label">
            <span class="label-text">Čas prvního vyčítání:</span>
          </label>
          <div class="form-control w-full">
            <input id="2w-sensor-first-read" type="time" :class="'input input-bordered w-full'+(errorBag?.sensorFirstReadTime ? 'input-error' : '' )"
                   v-model="twoWayCommSettings.sensorFirstReadTime"
                   placeholder="Zvolte čas prvního vyčítání"/>
            <div class="label">
              <span v-if="errorBag?.sensorFirstReadTime" class="label-text-alt text-red-500"><span class="mdi mdi-alert-circle-outline mr-1"></span>{{ errorBag.sensorFirstReadTime }}</span>
            </div>
          </div>

          <label for="2w-sensor-next-interval" class="label">
            <span class="label-text">Interval dalších vyčítání během dne [min]:</span>
          </label>
          <div class="form-control w-full">
            <input id="2w-sensor-next-interval" type="number" :class="'input input-bordered w-full'+(errorBag?.sensorNextReadInterval ? 'input-error' : '' )"
                   v-model="twoWayCommSettings.sensorNextReadInterval"
                   placeholder="Zvolte prodlevu mezi následujícími vyčítáními"/>
            <div class="label">
              <span v-if="errorBag?.sensorNextReadInterval" class="label-text-alt text-red-500"><span class="mdi mdi-alert-circle-outline mr-1"></span>{{ errorBag.sensorNextReadInterval }}</span>
            </div>
          </div>
        </div>
      </div>

      <div class="flex justify-end">
        <div class="text-xl text-primary mb-4 mt-3 px-1">
          <button class="btn btn-primary" @click="saveTwoWayCommSettings" :disabled="cantSave">Uložit</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>

</style>