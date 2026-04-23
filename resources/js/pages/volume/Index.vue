<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'

const props = defineProps({
    initialResults: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
})

const keywordsRaw = ref('')
const gl = ref('us')
const hl = ref('en')
const mode = ref('batch')
const loading = ref(false)
const error = ref(null)
const results = ref(props.initialResults || [])
const expandedRow = ref(null)
const sortKey = ref('score')
const sortDir = ref('desc')

const sortedResults = computed(() => {
    return [...results.value].sort((a, b) => {
        let av = a[sortKey.value]
        let bv = b[sortKey.value]
        if (typeof av === 'string') av = av.toLowerCase()
        if (typeof bv === 'string') bv = bv.toLowerCase()
        if (av < bv) return sortDir.value === 'asc' ? -1 : 1
        if (av > bv) return sortDir.value === 'asc' ? 1 : -1
        return 0
    })
})

const tierSummary = computed(() => {
    const tiers = [
        { tier: 'very_high', label: 'Very High', count: 0 },
        { tier: 'high', label: 'High', count: 0 },
        { tier: 'medium', label: 'Medium', count: 0 },
        { tier: 'low', label: 'Low', count: 0 },
        { tier: 'very_low', label: 'Very Low', count: 0 },
    ]
    results.value.forEach(r => {
        const t = tiers.find(t => t.tier === r.tier)
        if (t) t.count++
    })
    return tiers.filter(t => t.count > 0)
})

async function submit() {
    const keywords = keywordsRaw.value
        .split('\n')
        .map(k => k.trim())
        .filter(Boolean)

    if (!keywords.length) return

    loading.value = true
    error.value = null
    expandedRow.value = null

    try {
        const endpoint = keywords.length === 1
            ? '/api/seo/keyword-volume/single'
            : '/api/seo/keyword-volume/batch'

        const payload = keywords.length === 1
            ? { keyword: keywords[0], gl: gl.value, hl: hl.value }
            : { keywords, gl: gl.value, hl: hl.value }

        const { data } = await axios.post(endpoint, payload)
        results.value = Array.isArray(data.results) ? data.results : [data]
    } catch (e) {
        error.value = e?.response?.data?.message || 'Scan failed. Check your API key and try again.'
    } finally {
        loading.value = false
    }
}

function clearResults() {
    results.value = []
    expandedRow.value = null
    keywordsRaw.value = ''
}

function toggleRow(i) {
    expandedRow.value = expandedRow.value === i ? null : i
}

function sortBy(key) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDir.value = 'desc'
    }
}

function sortIcon(key) {
    if (sortKey.value !== key) return '↕'
    return sortDir.value === 'asc' ? '↑' : '↓'
}

function tierCountClass(tier) {
    return {
        'very_high': 'text-[#e8890a]',
        'high': 'text-[#d4a017]',
        'medium': 'text-[#4aab6d]',
        'low': 'text-[#4a8fc7]',
        'very_low': 'text-[#666680]',
    }[tier] || 'text-[#666680]'
}

function tierBadgeClass(tier) {
    return {
        'very_high': 'text-[#e8890a] bg-[#e8890a]/10 border-[#e8890a]/25',
        'high':      'text-[#d4a017] bg-[#d4a017]/10 border-[#d4a017]/25',
        'medium':    'text-[#4aab6d] bg-[#4aab6d]/10 border-[#4aab6d]/25',
        'low':       'text-[#4a8fc7] bg-[#4a8fc7]/10 border-[#4a8fc7]/25',
        'very_low':  'text-[#666680] bg-[#666680]/10 border-[#666680]/20',
    }[tier] || 'text-[#666680] border-[#1e1e26]'
}

function scoreBarClass(score) {
    if (score >= 0.75) return 'bg-[#e8890a]'
    if (score >= 0.55) return 'bg-[#d4a017]'
    if (score >= 0.35) return 'bg-[#4aab6d]'
    if (score >= 0.15) return 'bg-[#4a8fc7]'
    return 'bg-[#333345]'
}

function signalDotClass(normalised) {
    if (normalised >= 0.75) return 'bg-[#e8890a]'
    if (normalised >= 0.50) return 'bg-[#4aab6d]'
    if (normalised >= 0.25) return 'bg-[#4a8fc7]'
    return 'bg-[#2a2a36] border border-[#333345]'
}

function formatSignalName(key) {
    return key.replace(/_/g, ' ').toUpperCase()
}

function exportCsv() {
    const headers = ['Keyword', 'Tier', 'Volume Range', 'Score', 'Cached']
    const rows = sortedResults.value.map(r => [
        `"${r.keyword}"`,
        r.tier_label,
        r.volume_range,
        r.score,
        r.cached ? 'Yes' : 'No',
    ])
    const csv = [headers, ...rows].map(r => r.join(',')).join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `volscan-${Date.now()}.csv`
    a.click()
    URL.revokeObjectURL(url)
}
</script>

<template>
    <div class="min-h-screen bg-[#0d0d0f] text-[#c8c8d0] font-mono relative overflow-x-hidden">

        <main class="max-w-6xl mx-auto px-8 py-10 pb-16 relative z-10">

            <!-- Search Panel -->
            <section class="bg-[#111116] border border-[#1e1e26] rounded p-7 mb-8">
                <div class="flex items-center gap-2 mb-4">
                    <span class="inline-block w-4 h-px bg-[#e8890a]"></span>
                    <span class="text-[10px] tracking-[0.25em] text-[#444450]">QUERY INPUT</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-[1fr_220px] gap-6 items-start">
                    <!-- Textarea -->
                    <div class="relative">
                        <textarea
                            v-model="keywordsRaw"
                            class="w-full bg-[#0d0d0f] border border-[#1e1e26] rounded-sm text-[#c8c8d0] font-mono text-[13px] px-4 py-3.5 resize-y min-h-[130px] leading-relaxed transition-colors focus:outline-none focus:border-[#e8890a] placeholder-[#333340] disabled:opacity-50 disabled:cursor-not-allowed"
                            placeholder="Enter keywords, one per line…&#10;best running shoes&#10;nike air max&#10;trail running gear"
                            rows="5"
                            :disabled="loading"
                            @keydown.ctrl.enter="submit"
                            @keydown.meta.enter="submit"
                        ></textarea>
                        <div class="text-[10px] text-[#333340] tracking-wide mt-1 text-right">Ctrl+Enter to scan</div>
                    </div>

                    <!-- Options -->
                    <div class="flex flex-col gap-3.5">
                        <div class="flex flex-col gap-1">
                            <label class="text-[10px] tracking-[0.2em] text-[#444450]">COUNTRY</label>
                            <select
                                v-model="gl"
                                class="bg-[#0d0d0f] border border-[#1e1e26] rounded-sm text-[#c8c8d0] font-mono text-[12px] px-3 py-1.5 cursor-pointer transition-colors focus:outline-none focus:border-[#e8890a] disabled:opacity-50 disabled:cursor-not-allowed"
                                :disabled="loading"
                            >
                                <option value="us">🇺🇸 United States</option>
                                <option value="gb">🇬🇧 United Kingdom</option>
                                <option value="za">🇿🇦 South Africa</option>
                                <option value="au">🇦🇺 Australia</option>
                                <option value="ca">🇨🇦 Canada</option>
                                <option value="de">🇩🇪 Germany</option>
                                <option value="fr">🇫🇷 France</option>
                                <option value="in">🇮🇳 India</option>
                                <option value="br">🇧🇷 Brazil</option>
                                <option value="nl">🇳🇱 Netherlands</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-[10px] tracking-[0.2em] text-[#444450]">LANGUAGE</label>
                            <select
                                v-model="hl"
                                class="bg-[#0d0d0f] border border-[#1e1e26] rounded-sm text-[#c8c8d0] font-mono text-[12px] px-3 py-1.5 cursor-pointer transition-colors focus:outline-none focus:border-[#e8890a] disabled:opacity-50 disabled:cursor-not-allowed"
                                :disabled="loading"
                            >
                                <option value="en">English</option>
                                <option value="de">Deutsch</option>
                                <option value="fr">Français</option>
                                <option value="es">Español</option>
                                <option value="pt">Português</option>
                                <option value="nl">Nederlands</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label class="text-[10px] tracking-[0.2em] text-[#444450]">MODE</label>
                            <div class="flex border border-[#1e1e26] rounded-sm overflow-hidden">
                                <button
                                    class="flex-1 bg-[#0d0d0f] border-none font-mono text-[10px] tracking-widest py-1.5 cursor-pointer transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="mode === 'single' ? 'bg-[#1a1a20] text-[#e8890a]' : 'text-[#444450]'"
                                    @click="mode = 'single'"
                                    :disabled="loading"
                                >SINGLE</button>
                                <button
                                    class="flex-1 bg-[#0d0d0f] border-l border-[#1e1e26] font-mono text-[10px] tracking-widest py-1.5 cursor-pointer transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="mode === 'batch' ? 'bg-[#1a1a20] text-[#e8890a]' : 'text-[#444450]'"
                                    @click="mode = 'batch'"
                                    :disabled="loading"
                                >BATCH</button>
                            </div>
                        </div>

                        <button
                            class="mt-1 bg-[#e8890a] border-none rounded-sm text-[#0d0d0f] font-mono text-[12px] font-semibold tracking-[0.15em] px-4 py-2.5 cursor-pointer transition-all active:scale-[0.98] disabled:bg-[#2a2218] disabled:text-[#4a3a18] disabled:cursor-not-allowed hover:bg-[#f59d20]"
                            @click="submit"
                            :disabled="loading || !keywordsRaw.trim()"
                        >
                            <span v-if="!loading" class="flex items-center justify-center gap-1.5">
                                <span class="text-[10px]">▶</span> SCAN
                            </span>
                            <span v-else class="flex items-center justify-center gap-2">
                                <span class="inline-block w-2.5 h-2.5 border border-[#0d0d0f] border-t-transparent rounded-full animate-spin"></span>
                                SCANNING…
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Error -->
                <transition
                    enter-active-class="transition-opacity duration-200"
                    enter-from-class="opacity-0"
                    leave-active-class="transition-opacity duration-200"
                    leave-to-class="opacity-0"
                >
                    <div v-if="error" class="mt-4 bg-[#1a0d0d] border border-[#4a1a1a] rounded-sm px-4 py-2.5 text-[12px] text-[#e05050] flex items-center gap-2">
                        <span>⚠</span> {{ error }}
                    </div>
                </transition>
            </section>

            <!-- Results -->
            <transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-4"
            >
                <section v-if="results.length" class="bg-[#111116] border border-[#1e1e26] rounded overflow-hidden">

                    <!-- Results Header -->
                    <div class="flex items-center justify-between px-7 pt-5 pb-2">
                        <div class="flex items-center gap-2">
                            <span class="inline-block w-4 h-px bg-[#e8890a]"></span>
                            <span class="text-[10px] tracking-[0.25em] text-[#444450]">SCAN RESULTS</span>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="text-[10px] tracking-wide text-[#444450]">{{ results.length }} keyword{{ results.length !== 1 ? 's' : '' }}</span>
                            <button
                                class="bg-transparent border border-[#1e1e26] rounded-sm text-[#444450] font-mono text-[10px] tracking-[0.15em] px-2.5 py-1 cursor-pointer transition-colors hover:border-[#e05050] hover:text-[#e05050]"
                                @click="clearResults"
                            >CLEAR</button>
                        </div>
                    </div>

                    <!-- Tier Summary -->
                    <div class="flex gap-3 flex-wrap px-7 py-3">
                        <div
                            v-for="tier in tierSummary"
                            :key="tier.tier"
                            class="bg-[#0d0d0f] border border-[#1e1e26] rounded-sm px-4 py-2 flex items-center gap-2.5"
                        >
                            <span class="text-lg font-semibold leading-none" :class="tierCountClass(tier.tier)">{{ tier.count }}</span>
                            <span class="text-[10px] tracking-wide text-[#444450]">{{ tier.label }}</span>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-[13px]">
                            <thead>
                                <tr class="border-t border-b border-[#1e1e26] bg-[#0d0d0f]">
                                    <th class="px-4 py-2.5 text-[10px] tracking-[0.18em] text-[#444450] text-left font-medium cursor-pointer whitespace-nowrap select-none hover:text-[#e8890a] transition-colors" @click="sortBy('keyword')">
                                        KEYWORD <span class="opacity-60 text-[10px]">{{ sortIcon('keyword') }}</span>
                                    </th>
                                    <th class="px-4 py-2.5 text-[10px] tracking-[0.18em] text-[#444450] text-left font-medium cursor-pointer whitespace-nowrap select-none hover:text-[#e8890a] transition-colors" @click="sortBy('tier_label')">
                                        TIER <span class="opacity-60 text-[10px]">{{ sortIcon('tier_label') }}</span>
                                    </th>
                                    <th class="px-4 py-2.5 text-[10px] tracking-[0.18em] text-[#444450] text-left font-medium cursor-pointer whitespace-nowrap select-none hover:text-[#e8890a] transition-colors" @click="sortBy('volume_range')">
                                        VOLUME RANGE <span class="opacity-60 text-[10px]">{{ sortIcon('volume_range') }}</span>
                                    </th>
                                    <th class="px-4 py-2.5 text-[10px] tracking-[0.18em] text-[#444450] text-left font-medium cursor-pointer whitespace-nowrap select-none hover:text-[#e8890a] transition-colors" @click="sortBy('score')">
                                        SCORE <span class="opacity-60 text-[10px]">{{ sortIcon('score') }}</span>
                                    </th>
                                    <th class="px-4 py-2.5 text-[10px] tracking-[0.18em] text-[#444450] text-left font-medium whitespace-nowrap">SIGNALS</th>
                                    <th class="px-4 py-2.5 text-[10px] tracking-[0.18em] text-[#444450] text-left font-medium whitespace-nowrap">CACHE</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="(row, i) in sortedResults" :key="row.keyword">
                                    <!-- Main row -->
                                    <tr
                                        class="border-b border-[#161620] cursor-pointer transition-colors hover:bg-[#161620]"
                                        :class="{ 'bg-[#161620]': expandedRow === i }"
                                        @click="toggleRow(i)"
                                    >
                                        <td class="px-4 py-3 text-[#e0e0e8]">{{ row.keyword }}</td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="text-[10px] tracking-wide px-2 py-0.5 rounded-sm font-medium border"
                                                :class="tierBadgeClass(row.tier)"
                                            >{{ row.tier_label }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-[#c8c8d0]">{{ row.volume_range }}</td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-2.5">
                                                <div class="flex-1 h-1 bg-[#1e1e26] rounded-full overflow-hidden min-w-[60px]">
                                                    <div
                                                        class="h-full rounded-full transition-all duration-500"
                                                        :class="scoreBarClass(row.score)"
                                                        :style="{ width: (row.score * 100) + '%' }"
                                                    ></div>
                                                </div>
                                                <span class="text-[11px] text-[#666680] min-w-[28px] text-right">{{ (row.score * 100).toFixed(1) }}</span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex gap-1 items-center">
                                                <span
                                                    v-for="(sig, key) in row.signals"
                                                    :key="key"
                                                    class="w-2 h-2 rounded-full cursor-help"
                                                    :class="signalDotClass(sig.normalised)"
                                                    :title="`${key}: ${sig.raw} (${(sig.normalised * 100).toFixed(0)}%)`"
                                                ></span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="text-[10px] tracking-wide px-2 py-0.5 rounded-sm border"
                                                :class="row.cached
                                                    ? 'text-[#4aab6d] border-[#4aab6d]/25 bg-[#4aab6d]/08'
                                                    : 'text-[#444450] border-[#1e1e26]'"
                                            >{{ row.cached ? 'HIT' : 'LIVE' }}</span>
                                        </td>
                                    </tr>

                                    <!-- Signal breakdown -->
                                    <tr v-if="expandedRow === i">
                                        <td colspan="6" class="p-0">
                                            <div class="bg-[#0d0d0f] border-t border-b border-[#1e1e26] px-7 py-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                                <div
                                                    v-for="(sig, key) in row.signals"
                                                    :key="key"
                                                    class="grid items-center gap-2.5"
                                                    style="grid-template-columns: 110px 1fr auto"
                                                >
                                                    <span class="text-[10px] tracking-wide text-[#444450] whitespace-nowrap">{{ formatSignalName(key) }}</span>
                                                    <div class="h-0.5 bg-[#1e1e26] rounded-full overflow-hidden">
                                                        <div
                                                            class="h-full rounded-full transition-all duration-300"
                                                            :class="signalDotClass(sig.normalised)"
                                                            :style="{ width: (sig.normalised * 100) + '%' }"
                                                        ></div>
                                                    </div>
                                                    <div class="flex gap-2 text-[10px] text-[#444450] whitespace-nowrap">
                                                        <span>{{ sig.raw }}</span>
                                                        <span class="text-[#666680]">{{ (sig.normalised * 100).toFixed(0) }}%</span>
                                                        <span>w:{{ sig.weight }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <!-- Export -->
                    <div class="px-7 py-3.5 border-t border-[#1e1e26] flex justify-end">
                        <button
                            class="bg-transparent border border-[#1e1e26] rounded-sm text-[#444450] font-mono text-[10px] tracking-wide px-3.5 py-1.5 cursor-pointer transition-colors hover:border-[#e8890a] hover:text-[#e8890a]"
                            @click="exportCsv"
                        >↓ EXPORT CSV</button>
                    </div>
                </section>
            </transition>

            <!-- Empty state -->
            <transition
                enter-active-class="transition-opacity duration-200"
                enter-from-class="opacity-0"
            >
                <div v-if="!results.length && !loading" class="text-center py-20 text-[#2a2a36]">
                    <span class="block text-5xl mb-4">⟁</span>
                    <span class="text-[11px] tracking-[0.15em]">Enter keywords above to begin scanning</span>
                </div>
            </transition>

        </main>
    </div>
</template>