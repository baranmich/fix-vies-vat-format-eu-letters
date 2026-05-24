import { ref, computed, type ComputedRef, type Ref } from 'vue'
import { codebooksApi } from '@/api/codebooks'

/**
 * Year dropdown source for list/report pages.
 *
 * Per issue #33: dříve hardcoded `[currentYear-0..-4]`, takže importovaná
 * historická data (5-10+ let) byla v UI neviditelná, i když datová vrstva
 * (counters, query params) starší roky podporuje.
 *
 * Backend `/api/codebooks/years` vrací distinct roky z `invoices` +
 * `purchase_invoices` pro aktuální supplier. Tady doplníme:
 *   - aktuální + minulý rok (aby dropdown nikdy nebyl prázdný)
 *   - hodnotu `currentValue` (pokud user přijde z URL ?year=2018 a 2018
 *     v datech není, přesto se ukáže, ať vidí, na čem filtruje)
 *
 * @param source 'invoices' | 'purchase_invoices' | 'combined' — který set
 * @param currentValue ref na aktuálně vybraný rok (přidá se do dropdownu i kdyby v datech nebyl)
 */
export function useYearOptions(
  source: 'invoices' | 'purchase_invoices' | 'combined' = 'combined',
  currentValue?: Ref<number | '' | null>,
): ComputedRef<number[]> {
  const fromApi = ref<number[]>([])
  codebooksApi.years().then((r) => {
    const set = r[source]
    fromApi.value = set.length ? set : r.combined
  }).catch(() => {
    // Tichý fallback — composable musí být robustní (auth race, offline).
    // Aktuální + minulý rok pokryje 90 % use cases dokud nedoběhne /me.
  })
  return computed<number[]>(() => {
    const merged = new Set<number>(fromApi.value)
    const cur = new Date().getFullYear()
    merged.add(cur)
    merged.add(cur - 1)
    if (currentValue && typeof currentValue.value === 'number') {
      merged.add(currentValue.value)
    }
    return [...merged].sort((a, b) => b - a)
  })
}
