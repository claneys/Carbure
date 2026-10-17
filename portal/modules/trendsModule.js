/**
 * Trends Module
 *
 * Monthly trends of the household (incomes, expenses, off-budget, planned budget
 * and savings) over the last months, drawn as inline SVG charts.
 *
 * @module trendsModule
 */

/** Chart geometry (SVG user units) */
const WIDTH = 960;
const MARGIN = { top: 12, right: 12, bottom: 36, left: 68 };
const FLOWS_HEIGHT = 300;
const SAVINGS_HEIGHT = 200;
const RATE_HEIGHT = 200;

/** Series of the flows chart, in the fixed categorical order (validated palette) */
const FLOW_SERIES = [
    { key: 'credit', color: '#2a78d6', label: 'trendsCredit' },
    { key: 'debit', color: '#eb6834', label: 'trendsDebit' },
    { key: 'offBudget', color: '#1baf7a', label: 'trendsOffBudget' }
];

/**
 * Totals and averages of a list of months
 * @param {Array} months - Months returned by the trends API
 * @returns {Object}
 */
function periodStats(months) {
    const sum = key => months.reduce((total, m) => total + Number(m[key] || 0), 0);
    const count = Math.max(1, months.length);
    const credit = sum('credit');
    const savings = sum('savings');
    return {
        credit,
        debit: sum('debit'),
        offBudget: sum('offBudget'),
        planned: sum('planned'),
        savings,
        averageSavings: savings / count,
        averageDebit: sum('debit') / count,
        averagePlanned: sum('planned') / count,
        savingsRate: credit > 0 ? Math.round(savings / credit * 100) : 0
    };
}

/**
 * Rounds a maximum up to a readable axis bound and returns the tick step
 * @param {number} max - Maximum value of the data
 * @param {number} ticks - Wanted number of intervals
 * @returns {{max: number, step: number}}
 */
function niceScale(max, ticks = 4) {
    if (max <= 0) {
        return { max: ticks, step: 1 };
    }
    const raw = max / ticks;
    const magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map(m => m * magnitude).find(s => s >= raw);
    return { max: step * ticks, step };
}

/**
 * SVG path of a bar with rounded data end (the end away from the baseline)
 * @param {number} x - Left
 * @param {number} y0 - Baseline
 * @param {number} y1 - Data end
 * @param {number} width - Bar width
 * @returns {string}
 */
function barPath(x, y0, y1, width) {
    const height = Math.abs(y1 - y0);
    if (height < 0.5) {
        return '';
    }
    const r = Math.min(3, width / 2, height);
    const up = y1 < y0;
    const end = up ? y1 + r : y1 - r;
    const corner = up ? -r : r;
    return `M${x},${y0} V${end} q0,${corner} ${r},${corner} H${x + width - r} q${r},0 ${r},${-corner} V${y0} Z`;
}

/**
 * Creates a trends module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with trends data, computed charts and methods
 */
export function createTrendsModule(getApiService) {
    return {
        data() {
            return {
                trends: [],
                trendsMonths: 12,
                // 'none', 'previous' (the months just before) or 'lastYear' (same months a year before)
                trendsCompare: 'none',
                compareTrends: [],
                loadingTrends: false,
                trendsHover: null,
                // Chart showing its tooltip: 'flows' or 'rate'
                trendsHoverChart: 'flows',
                showTrendsTable: false
            };
        },

        computed: {
            /**
             * Legend of the flows chart
             * @returns {Array}
             */
            trendsSeries() {
                return FLOW_SERIES.map(s => ({ ...s, label: this.t(s.label) }));
            },

            /**
             * Totals and averages over the period
             * @returns {Object}
             */
            trendsStats() {
                return periodStats(this.trends);
            },

            /**
             * Totals of the comparison period (null without comparison)
             * @returns {Object|null}
             */
            compareStats() {
                return this.trendsCompare !== 'none' && this.compareTrends.length ? periodStats(this.compareTrends) : null;
            },

            /**
             * Label of a period: "nov. 25 – oct. 26"
             * @returns {{current: string, compare: string}}
             */
            trendsPeriodLabels() {
                const label = list => list.length ? `${this.formatMonth(list[0].month)} – ${this.formatMonth(list[list.length - 1].month)}` : '';
                return { current: label(this.trends), compare: label(this.compareTrends) };
            },

            /**
             * Comparison of the period totals, one row per measure
             * @returns {Array<{key, label, current, compare, currentWidth, compareWidth}>}
             */
            trendsComparison() {
                if (!this.compareStats) {
                    return [];
                }
                const rows = [
                    { key: 'credit', label: this.t('trendsCredit') },
                    { key: 'debit', label: this.t('trendsDebit') },
                    { key: 'offBudget', label: this.t('trendsOffBudget') },
                    { key: 'planned', label: this.t('trendsPlanned') },
                    { key: 'savings', label: this.t('trendsSavings') }
                ].map(row => ({ ...row, current: this.trendsStats[row.key], compare: this.compareStats[row.key] }));
                const max = Math.max(1, ...rows.flatMap(r => [Math.abs(r.current), Math.abs(r.compare)]));
                return rows.map(r => ({
                    ...r,
                    currentWidth: Math.abs(r.current) / max * 100,
                    compareWidth: Math.abs(r.compare) / max * 100
                }));
            },

            /**
             * Geometry of the flows chart (bars + planned budget line)
             * @returns {Object}
             */
            flowsChart() {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = FLOWS_HEIGHT - MARGIN.top - MARGIN.bottom;
                const n = Math.max(1, this.trends.length);
                const max = Math.max(0, ...this.trends.flatMap(m =>
                    [m.credit, m.debit, Math.abs(m.offBudget), m.planned].map(Number)));
                const scale = niceScale(max);
                const y = value => MARGIN.top + plotHeight - (value / scale.max) * plotHeight;
                const group = plotWidth / n;
                const gap = 2;
                const barWidth = Math.min(28, Math.max(2, (group * 0.78 - gap * 2) / 3));

                const bars = [];
                this.trends.forEach((m, i) => {
                    const left = MARGIN.left + i * group + (group - (barWidth * 3 + gap * 2)) / 2;
                    FLOW_SERIES.forEach((s, j) => {
                        const value = Math.abs(Number(m[s.key]) || 0);
                        bars.push({
                            key: `${m.month}-${s.key}`,
                            color: s.color,
                            d: barPath(left + j * (barWidth + gap), y(0), y(value), barWidth)
                        });
                    });
                });

                const planned = this.trends
                    .map((m, i) => `${MARGIN.left + (i + 0.5) * group},${y(Number(m.planned) || 0)}`)
                    .join(' ');

                return {
                    width: WIDTH,
                    height: FLOWS_HEIGHT,
                    bars,
                    planned,
                    ticks: this.axisTicks(scale, y),
                    labels: this.monthLabels(group),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight)
                };
            },

            /**
             * Geometry of the savings chart (diverging bars around zero)
             * @returns {Object}
             */
            savingsChart() {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = SAVINGS_HEIGHT - MARGIN.top - MARGIN.bottom;
                const n = Math.max(1, this.trends.length);
                // The scale only covers the signs present in the data (no empty half)
                const values = this.trends.map(m => Number(m.savings) || 0);
                const step = niceScale(Math.max(1, ...values.map(Math.abs)), 2).step;
                const top = Math.ceil(Math.max(0, ...values) / step) * step;
                const bottom = Math.floor(Math.min(0, ...values) / step) * step;
                const range = (top - bottom) || step;
                const y = value => MARGIN.top + ((top - value) / range) * plotHeight;
                const group = plotWidth / n;
                const barWidth = Math.min(48, Math.max(3, group * 0.5));

                const bars = this.trends.map((m, i) => {
                    const value = Number(m.savings) || 0;
                    return {
                        key: m.month,
                        positive: value >= 0,
                        d: barPath(MARGIN.left + i * group + (group - barWidth) / 2, y(0), y(value), barWidth)
                    };
                });

                const ticks = [];
                for (let value = bottom; value <= top + 1e-9; value += step) {
                    ticks.push({ value, y: y(value), label: this.formatCompact(value) });
                }

                return {
                    width: WIDTH,
                    height: SAVINGS_HEIGHT,
                    bars,
                    zero: y(0),
                    ticks,
                    labels: this.monthLabels(group, SAVINGS_HEIGHT),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight)
                };
            },

            /**
             * Geometry of the monthly savings rate chart (line, in %)
             * @returns {Object}
             */
            savingsRateChart() {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = RATE_HEIGHT - MARGIN.top - MARGIN.bottom;
                const n = Math.max(1, this.trends.length);
                const group = plotWidth / n;
                const rates = this.trends.map(m => this.monthSavingsRate(m));
                const known = rates.filter(r => r !== null);
                const step = niceScale(Math.max(10, ...known.map(Math.abs)), 2).step;
                const top = Math.max(step, Math.ceil(Math.max(0, ...known) / step) * step);
                const bottom = Math.floor(Math.min(0, ...known) / step) * step;
                const y = value => MARGIN.top + ((top - value) / (top - bottom)) * plotHeight;

                const points = rates
                    .map((rate, i) => rate === null ? null : { key: this.trends[i].month, x: MARGIN.left + (i + 0.5) * group, y: y(rate), rate })
                    .filter(Boolean);
                const ticks = [];
                for (let value = bottom; value <= top + 1e-9; value += step) {
                    ticks.push({ value, y: y(value), label: `${value} %` });
                }

                return {
                    width: WIDTH,
                    height: RATE_HEIGHT,
                    points,
                    line: points.map(p => `${p.x},${p.y}`).join(' '),
                    average: y(this.trendsStats.savingsRate),
                    zero: y(0),
                    ticks,
                    labels: this.monthLabels(group, RATE_HEIGHT),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight)
                };
            },

            /**
             * Month under the pointer, for the tooltip
             * @returns {Object|null}
             */
            trendsHoverMonth() {
                return this.trendsHover === null ? null : this.trends[this.trendsHover];
            },

            /**
             * Horizontal position of the tooltip, in % of the chart width
             * @returns {number}
             */
            trendsHoverLeft() {
                const group = (WIDTH - MARGIN.left - MARGIN.right) / Math.max(1, this.trends.length);
                const x = MARGIN.left + (this.trendsHover + 0.5) * group;
                return Math.min(85, Math.max(15, x / WIDTH * 100));
            }
        },

        methods: {
            /**
             * Loads the trends of the selected period
             * @returns {Promise<void>}
             */
            async loadTrends() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                this.loadingTrends = true;
                try {
                    const offset = { previous: this.trendsMonths, lastYear: 12 }[this.trendsCompare];
                    [this.trends, this.compareTrends] = await Promise.all([
                        apiService.fetchTrends(this.trendsMonths),
                        offset ? apiService.fetchTrends(this.trendsMonths, offset) : Promise.resolve([])
                    ]);
                } catch (error) {
                    this.error = error.message;
                    this.trends = [];
                } finally {
                    this.loadingTrends = false;
                }
            },

            /**
             * Changes the period and reloads
             * @param {number} months - Number of months
             */
            setTrendsMonths(months) {
                this.trendsMonths = months;
                this.loadTrends();
            },

            /**
             * Savings rate of a month in % (null without income)
             * @param {Object} month - Month of the trends
             * @returns {number|null}
             */
            monthSavingsRate(month) {
                const credit = Number(month.credit) || 0;
                return credit > 0 ? Math.round((Number(month.savings) || 0) / credit * 100) : null;
            },

            /**
             * Changes the comparison period and reloads
             * @param {string} compare - 'none', 'previous' or 'lastYear'
             */
            setTrendsCompare(compare) {
                this.trendsCompare = compare;
                this.loadTrends();
            },

            /**
             * Variation against the comparison period, for a key figure
             * @param {string} key - Key of the statistic
             * @param {boolean} higherIsBetter - False for expenses
             * @returns {{text: string, good: boolean, icon: string}|null}
             */
            trendsDelta(key, higherIsBetter = true) {
                if (!this.compareStats) {
                    return null;
                }
                const current = this.trendsStats[key];
                const previous = this.compareStats[key];
                if (!previous) {
                    return null;
                }
                const percent = Math.round((current - previous) / Math.abs(previous) * 100);
                return {
                    text: `${percent > 0 ? '+' : ''}${percent} %`,
                    good: percent === 0 || (percent > 0) === higherIsBetter,
                    icon: percent > 0 ? 'arrow_upward' : percent < 0 ? 'arrow_downward' : 'remove'
                };
            },

            /**
             * Y axis ticks
             */
            axisTicks(scale, y) {
                const ticks = [];
                for (let value = 0; value <= scale.max + 1e-9; value += scale.step) {
                    ticks.push({ value, y: y(value), label: this.formatCompact(value) });
                }
                return ticks;
            },

            /**
             * X axis labels: one month out of three, plus the last one
             */
            monthLabels(group, height = FLOWS_HEIGHT) {
                const step = this.trends.length > 12 ? 3 : this.trends.length > 6 ? 2 : 1;
                const last = this.trends.length - 1;
                return this.trends
                    .map((m, i) => ({ month: m.month, i }))
                    .filter(({ i }) => (last - i) % step === 0)
                    .map(({ month, i }) => ({
                        key: month,
                        x: MARGIN.left + (i + 0.5) * group,
                        y: height - 12,
                        text: this.formatMonth(month)
                    }));
            },

            /**
             * Invisible hover targets, one per month (bigger than the marks)
             */
            hoverColumns(group, top, height) {
                return this.trends.map((m, i) => ({
                    key: m.month,
                    index: i,
                    x: MARGIN.left + i * group,
                    y: top,
                    width: group,
                    height
                }));
            },

            /**
             * "2026-09" -> "sept. 26"
             * @param {string} month - Year and month
             * @returns {string}
             */
            formatMonth(month) {
                const [year, m] = month.split('-').map(Number);
                return new Intl.DateTimeFormat(this.intlLocale, { month: 'short', year: '2-digit' })
                    .format(new Date(year, m - 1, 1));
            },

            /**
             * Compact amount for the axes: 1 500 -> "1,5 k"
             * @param {number} value - Amount
             * @returns {string}
             */
            formatCompact(value) {
                return new Intl.NumberFormat(this.intlLocale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
            }
        }
    };
}
