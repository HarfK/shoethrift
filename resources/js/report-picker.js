/**
 * Admin Sales report: one date picker that changes with the Period dropdown (Flatpickr, bundled by Vite).
 *
 *   Daily   one day                         -> date=YYYY-MM-DD
 *   Weekly  click any day = its Mon–Sun week -> date=<Monday>
 *   Monthly month grid with a year switcher  -> month=YYYY-MM
 *   Custom  range, 2 months side by side     -> from, to (at most data-max-days days, never after today)
 *
 * It only writes the hidden inputs of a normal GET form; the server validates and resolves the range again.
 * Dates are plain calendar dates; "today" comes from the server (Malaysia time), not the browser clock.
 */
import flatpickr from 'flatpickr';
import monthSelectPlugin from 'flatpickr/dist/plugins/monthSelect/index.js';
import 'flatpickr/dist/flatpickr.css';
import 'flatpickr/dist/plugins/monthSelect/style.css';
import '../css/report-picker.css'; // admin theme overrides, after Flatpickr's own CSS

const DAY = 86400000;
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parse = (s) => {
    const m = /^(\d{4})-(\d{2})(?:-(\d{2}))?$/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, m[3] ? +m[3] : 1) : null;
};
const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
const monday = (d) => addDays(d, -((d.getDay() + 6) % 7));
const fmt = (d, opts) => d.toLocaleDateString('en-GB', opts);
const dayLabel = (d) => fmt(d, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).replace(',', '');
/** "2 Nov – 8 Nov 2026" (same rule as ReportPeriod::rangeLabel). */
const rangeLabel = (a, b) => {
    if (ymd(a) === ymd(b)) return dayLabel(a);
    const left = fmt(a, a.getFullYear() === b.getFullYear() ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' });
    return `${left} – ${fmt(b, { day: 'numeric', month: 'short', year: 'numeric' })}`;
};
/** "Mon 2 Nov – Sun 8 Nov 2026" for the weekly picker. */
const weekLabel = (start) => {
    const end = addDays(start, 6);
    const left = fmt(start, { weekday: 'short', day: 'numeric', month: 'short', ...(start.getFullYear() !== end.getFullYear() && { year: 'numeric' }) });
    return `${left.replace(',', '')} – ${dayLabel(end)}`;
};

const FIELDS = { day: ['date'], week: ['date'], month: ['month'], custom: ['from', 'to'] };
const LABELS = { day: 'Day', week: 'Week (Mon–Sun)', month: 'Month', custom: 'Date range' };

function init(form) {
    const today = parse(form.dataset.today);
    const maxDays = Number(form.dataset.maxDays) || 366;
    const select = form.querySelector('[data-period]');
    const input = form.querySelector('[data-picker-input]');
    const label = form.querySelector('[data-picker-label]');
    const summary = form.querySelector('[data-picker-summary]');
    const clear = form.querySelector('[data-picker-clear]');
    const submit = form.querySelector('[data-submit]');
    const field = (name) => form.querySelector(`[data-field="${name}"]`);
    let picker = null;
    let weekStart = null;

    /** Write the hidden inputs (only the period's own fields are submitted) and the visible text. */
    const write = (values, text, complete) => {
        for (const name of ['date', 'month', 'from', 'to']) {
            const el = field(name);
            el.value = values[name] ?? '';
            el.disabled = !FIELDS[select.value].includes(name);
        }
        input.value = text;
        submit.disabled = !complete;
    };

    const highlightWeek = (fp) => {
        for (const el of fp.days.querySelectorAll('.flatpickr-day')) {
            const inWeek = weekStart && el.dateObj >= weekStart && el.dateObj < addDays(weekStart, 7);
            el.classList.toggle('week-selected', !!inWeek);
            el.classList.toggle('week-start', !!inWeek && ymd(el.dateObj) === ymd(weekStart));
            el.classList.toggle('week-end', !!inWeek && ymd(el.dateObj) === ymd(addDays(weekStart, 6)));
        }
    };

    const build = (restore) => {
        picker?.destroy();
        input.value = ''; // Flatpickr would otherwise try to parse the previous label as a date
        weekStart = null;
        const period = select.value;
        label.textContent = LABELS[period];
        summary.textContent = '';
        clear.classList.add('hidden');
        const common = {
            locale: { firstDayOfWeek: 1, rangeSeparator: ' – ' },
            maxDate: today,
            now: today, // "today" is the server's Malaysian date, not the browser clock
            disableMobile: true, // keep the same picker on phones
            monthSelectorType: 'static',
        };

        if (period === 'day') {
            const value = restore ? parse(field('date').value) : null;
            picker = flatpickr(input, {
                ...common,
                defaultDate: value,
                onChange: ([d]) => d && write({ date: ymd(d) }, dayLabel(d), true),
            });
            value ? write({ date: ymd(value) }, dayLabel(value), true) : write({}, '', false);
        } else if (period === 'week') {
            const value = restore ? parse(field('date').value) : null;
            weekStart = value ? monday(value) : null;
            const pick = (d, fp) => {
                weekStart = monday(d);
                write({ date: ymd(weekStart) }, weekLabel(weekStart), true);
                summary.textContent = '';
                if (fp) highlightWeek(fp);
            };
            picker = flatpickr(input, {
                ...common,
                maxDate: addDays(monday(today), 6), // the current week may be chosen ("so far")
                disable: [(d) => monday(d) > today],
                defaultDate: weekStart,
                onChange: ([d], _s, fp) => d && pick(d, fp),
                onReady: (_d, _s, fp) => {
                    fp.calendarContainer.classList.add('week-picker');
                    // Hovering a day shows the whole week it would select.
                    fp.days.addEventListener('mouseover', (e) => {
                        const day = e.target.closest('.flatpickr-day');
                        if (!day) return;
                        const start = monday(day.dateObj);
                        for (const el of fp.days.querySelectorAll('.flatpickr-day')) {
                            el.classList.toggle('week-hover', el.dateObj >= start && el.dateObj < addDays(start, 7));
                        }
                    });
                    fp.days.addEventListener('mouseleave', () => fp.days.querySelectorAll('.week-hover').forEach((el) => el.classList.remove('week-hover')));
                    highlightWeek(fp);
                },
                onMonthChange: (_d, _s, fp) => highlightWeek(fp),
                onYearChange: (_d, _s, fp) => highlightWeek(fp),
                onOpen: (_d, _s, fp) => highlightWeek(fp),
                onValueUpdate: () => weekStart && (input.value = weekLabel(weekStart)),
            });
            weekStart ? pick(weekStart, picker) : write({}, '', false);
        } else if (period === 'month') {
            const value = restore ? parse(field('month').value) : null;
            const monthText = (d) => fmt(d, { month: 'long', year: 'numeric' });
            picker = flatpickr(input, {
                ...common,
                plugins: [monthSelectPlugin({ shorthand: true, dateFormat: 'Y-m', altFormat: 'F Y' })],
                defaultDate: value,
                onChange: ([d]) => d && write({ month: `${d.getFullYear()}-${pad(d.getMonth() + 1)}` }, monthText(d), true),
                onValueUpdate: ([d]) => d && (input.value = monthText(d)),
            });
            value ? write({ month: `${value.getFullYear()}-${pad(value.getMonth() + 1)}` }, monthText(value), true) : write({}, '', false);
        } else {
            const from = restore ? parse(field('from').value) : null;
            const to = restore ? parse(field('to').value) : null;
            const show = (a, b) => {
                const days = Math.round((b - a) / DAY) + 1;
                summary.textContent = `${days} ${days === 1 ? 'day' : 'days'} selected`;
                clear.classList.remove('hidden');
            };
            picker = flatpickr(input, {
                ...common,
                mode: 'range',
                showMonths: window.matchMedia('(min-width: 768px)').matches ? 2 : 1,
                defaultDate: from && to ? [from, to] : null,
                onChange: (dates, _s, fp) => {
                    if (dates.length === 1) {
                        // Start chosen: the end may be at most maxDays days long, and never after today.
                        const last = addDays(dates[0], maxDays - 1);
                        fp.set('maxDate', last < today ? last : today);
                        write({ from: ymd(dates[0]) }, `${dayLabel(dates[0])} – pick the end date`, false);
                        summary.textContent = 'Now click the end date';
                        clear.classList.remove('hidden');
                    } else if (dates.length === 2) {
                        fp.set('maxDate', today);
                        write({ from: ymd(dates[0]), to: ymd(dates[1]) }, rangeLabel(dates[0], dates[1]), true);
                        show(dates[0], dates[1]);
                    }
                },
                onValueUpdate: (dates) => {
                    if (dates.length === 2) input.value = rangeLabel(dates[0], dates[1]);
                },
                onClose: (dates, _s, fp) => {
                    if (dates.length === 1) fp.set('maxDate', today); // half-chosen: free the end again next time
                },
            });
            from && to ? (write({ from: ymd(from), to: ymd(to) }, rangeLabel(from, to), true), show(from, to)) : write({}, '', false);
            // Two months side by side: the end month (or this month) on the right, the month before on the left.
            const showEnd = (fp) => {
                const right = fp.selectedDates[1] || today;
                if (fp.config.showMonths === 2) fp.jumpToDate(new Date(right.getFullYear(), right.getMonth() - 1, 1));
            };
            picker.config.onOpen.push((_d, _s, fp) => showEnd(fp));
            showEnd(picker);
        }
        if (!restore) picker.open();
    };

    clear.addEventListener('click', (e) => {
        e.preventDefault();
        picker.clear();
        picker.set('maxDate', today);
        write({}, '', false);
        summary.textContent = '';
        clear.classList.add('hidden');
    });
    select.addEventListener('change', () => build(false));
    build(true);
}

document.querySelectorAll('[data-report-picker]').forEach(init);
