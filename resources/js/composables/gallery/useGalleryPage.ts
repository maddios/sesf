import { computed, ref, shallowRef, type Ref } from 'vue';
import {
  fetchBreadcrumb,
  fetchCheckedIn,
  fetchGalleryInfo,
  fetchGalleryItems,
  fetchGalleryList,
  type CheckedInState,
  type Gallery,
  type GalleryBreadcrumb,
  type GalleryItem,
} from '../../api/gallery';
import { ApiError } from '../../api/client';

export type GalleryPageStatus = 'loading' | 'ready' | 'forbidden' | 'error';

/**
 * State of one gallery page (/gallery/e{eventId}/{galleryId?}): breadcrumb, sub-galleries, the gallery, the first
 * page of items (sub-gallery items included, as legacy), paging via loadMore(), and the full list for the viewer.
 */
export function useGalleryPage(eventId: Ref<number>, galleryId: Ref<number | undefined>) {
  const status = ref<GalleryPageStatus>('loading');
  const breadcrumb = shallowRef<GalleryBreadcrumb | null>(null);
  const subGalleries = shallowRef<Gallery[]>([]);
  const gallery = shallowRef<Gallery | null>(null);
  const items = shallowRef<GalleryItem[]>([]);
  const count = ref(0);
  const perPage = ref(0);
  const skip = ref(0);
  const loadingMore = ref(false);
  const member = shallowRef<CheckedInState>({ checkedIn: false, memberGalleryId: null });
  let run = 0;

  const hasMore = computed(() => perPage.value > 0 && skip.value + perPage.value < count.value);

  function itemsQuery(extra: { skip?: number; allItems?: boolean } = {}) {
    return { showId: eventId.value, galleryId: galleryId.value, withSubGalleries: true, ...extra };
  }

  async function load(): Promise<void> {
    run += 1;
    const current = run;
    status.value = 'loading';
    skip.value = 0;
    try {
      const [crumbs, list, info, page] = await Promise.all([
        fetchBreadcrumb(eventId.value, galleryId.value),
        fetchGalleryList(eventId.value, galleryId.value),
        galleryId.value === undefined ? Promise.resolve(null) : fetchGalleryInfo(galleryId.value),
        fetchGalleryItems(itemsQuery()),
      ]);
      if (current !== run) return;
      breadcrumb.value = crumbs;
      subGalleries.value = list;
      gallery.value = info;
      items.value = page.items;
      count.value = page.count;
      perPage.value = page.perPage;
      status.value = 'ready';
    } catch (failure) {
      if (current !== run) return;
      status.value = failure instanceof ApiError && failure.status === 403 ? 'forbidden' : 'error';
    }
  }

  async function loadMember(isMember: boolean): Promise<void> {
    member.value = isMember ? await fetchCheckedIn(eventId.value) : { checkedIn: false, memberGalleryId: null };
  }

  async function loadMore(): Promise<void> {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    const current = run;
    try {
      const next = skip.value + perPage.value;
      const page = await fetchGalleryItems(itemsQuery({ skip: next }));
      if (current !== run) return;
      skip.value = next;
      items.value = [...items.value, ...page.items];
    } finally {
      loadingMore.value = false;
    }
  }

  /** Every item in page order for the photo viewer; falls back to the loaded items. */
  async function allItems(): Promise<GalleryItem[]> {
    try {
      const page = await fetchGalleryItems(itemsQuery({ allItems: true }));
      return page.items.length > 0 ? page.items : items.value;
    } catch {
      return items.value;
    }
  }

  return { status, breadcrumb, subGalleries, gallery, items, count, perPage, hasMore, loadingMore, member, load, loadMember, loadMore, allItems };
}
