<script setup lang="ts">
/**
 * Location block for the directions page: address, a "Get directions" link (always shown, works without a
 * key) and, when a Maps key and coordinates exist, a small map with one marker. If the key is missing or the
 * script fails, the map area is removed and the address and link remain.
 */
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import type { EventAddress } from '../../api/events';
import { directionsHref } from './directions';
import { getMapsKey } from '../../config';
import { loadGoogleMaps, type MapsLibrary } from './mapsLoader';

const props = defineProps<{
  /** Venue name, used as the marker title and the map's accessible name. */
  title: string;
  address: EventAddress;
}>();

type MapState = 'idle' | 'loading' | 'ready' | 'unavailable';

const hasCoordinates = computed(() => props.address.latitude !== null && props.address.longitude !== null);

const directions = computed(() => directionsHref(props.address));
const mapWanted = computed(() => hasCoordinates.value && getMapsKey() !== '');
const mapState = ref<MapState>(mapWanted.value ? 'idle' : 'unavailable');
const mapEl = ref<HTMLElement | null>(null);
let alive = true;

async function renderMap(library: MapsLibrary, element: HTMLElement): Promise<void> {
  const position = { lat: props.address.latitude ?? 0, lng: props.address.longitude ?? 0 };
  const map = new library.Map(element, { center: position, zoom: 14, mapTypeControl: false });
  const marker = new library.Marker({ map, position, title: props.title });
  const info = new library.InfoWindow({ content: infoContent() });
  marker.addListener('click', () => info.open({ map, anchor: marker as unknown as object }));
}

/** Plain text only (the venue name comes from the server, but it is still data, not markup). */
function infoContent(): HTMLElement {
  const box = document.createElement('div');
  const strong = document.createElement('strong');
  strong.textContent = props.title;
  const line = document.createElement('div');
  line.textContent = props.address.fullAddress;
  box.append(strong, line);
  return box;
}

onMounted(async () => {
  if (!mapWanted.value) {
    return;
  }
  mapState.value = 'loading';
  try {
    const library = await loadGoogleMaps(getMapsKey());
    if (!alive || mapEl.value === null) {
      return;
    }
    await renderMap(library, mapEl.value);
    mapState.value = 'ready';
  } catch {
    mapState.value = 'unavailable';
  }
});

onBeforeUnmount(() => {
  alive = false;
});
</script>

<template>
  <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_370px]">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <address class="not-italic leading-relaxed">
        <template v-if="address.address1">
          {{ address.address1 }}<br>
        </template>
        <template v-if="address.address2">
          {{ address.address2 }}<br>
        </template>
        <template v-if="address.cityState">
          {{ [address.cityState, address.zip].filter(Boolean).join(' ') }}
        </template>
        <template v-else-if="address.fullAddress">
          {{ address.fullAddress }}
        </template>
      </address>
      <a
        v-if="directions"
        :href="directions"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1 rounded-sm border border-default-border bg-default px-2.5 py-1 text-[0.8125rem] font-medium text-text no-underline hover:bg-default-hover hover:text-text"
      >
        Get Directions <span class="sr-only">to {{ title }} (opens Google Maps in a new tab)</span>
      </a>
    </div>
    <div
      v-if="mapWanted && mapState !== 'unavailable'"
      ref="mapEl"
      role="region"
      :aria-label="`Map of ${title}`"
      class="h-[370px] w-full overflow-hidden rounded-sm border border-border bg-subtle"
    >
      <p
        v-if="mapState !== 'ready'"
        class="p-4 text-sm text-muted"
        role="status"
      >
        Loading map
      </p>
    </div>
  </div>
</template>
