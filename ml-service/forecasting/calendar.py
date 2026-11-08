"""Malaysian calendar features for revenue forecasting (any date range in 2024-2027).

IMPORTANT: holiday dates must be verified against official sources (the Prime Minister's
Department / kabinet.gov.my public holiday circular, and the Ministry of Education school calendar)
before relying on them. Lunar-calendar holidays (Hari Raya, Deepavali) are announced as
"subject to change", and school reopening dates here are approximate.
Dates checked on 8 Nov 2026 against published 2026/2027 holiday lists:
  - Hari Raya Aidilfitri 2027: 10 Mar 2027 (provisional)
  - Chinese New Year 2027: 6 Feb 2027
  - Deepavali 2026: 8 Nov 2026; Deepavali 2027: 28 Oct 2027 (provisional, single source)
"""
import pandas as pd

# Hari Raya Aidilfitri (first day).
HARI_RAYA = ["2024-04-10", "2025-03-31", "2026-03-20", "2027-03-10"]
# Chinese New Year (first day).
CHINESE_NEW_YEAR = ["2024-02-10", "2025-01-29", "2026-02-17", "2027-02-06"]
DEEPAVALI = ["2024-10-31", "2025-10-20", "2026-11-08", "2027-10-28"]
# New school session (approximate; states differ by a day).
SCHOOL_REOPENING = ["2024-03-11", "2025-02-17", "2026-01-12", "2027-01-11"]

PRE_RAYA_DAYS = 21  # Raya shopping window before the first day
RAYA_CLOSURE_DAYS = 3  # Raya day to 3 days after (shop closed / balik kampung)
BACK_TO_SCHOOL_DAYS = 14  # shopping window before school reopens
DOUBLE_DAYS = [(9, 9), (10, 10), (11, 11), (12, 12)]

FEATURES = [
    "payday", "double_day", "double_day_adjacent", "black_friday", "pre_raya", "raya_closure",
    "chinese_new_year", "deepavali", "back_to_school", "month_end", "december",
]


def _dates(values) -> pd.DatetimeIndex:
    return pd.DatetimeIndex(pd.to_datetime(values))


def black_fridays(years) -> pd.DatetimeIndex:
    """Fourth Friday of November."""
    return _dates([pd.date_range(f"{y}-11-01", f"{y}-11-30", freq="W-FRI")[3] for y in years])


def _window(index: pd.DatetimeIndex, anchors, before: int, after: int) -> pd.Series:
    """1 where a date is within [anchor - before, anchor + after] for any anchor."""
    flag = pd.Series(0, index=index)
    for anchor in _dates(anchors):
        flag[(index >= anchor - pd.Timedelta(days=before)) & (index <= anchor + pd.Timedelta(days=after))] = 1
    return flag


def calendar_features(dates) -> pd.DataFrame:
    """One row per date with 0/1 calendar flags (see FEATURES).

    payday               25th to 2nd of the next month
    double_day           9.9, 10.10, 11.11, 12.12 campaigns
    double_day_adjacent  the day before or after a double day
    black_friday         Black Friday to Cyber Monday
    pre_raya             21 days before Hari Raya Aidilfitri (not Raya itself)
    raya_closure         Raya day to 3 days after
    chinese_new_year     the two CNY public holidays
    deepavali            Deepavali day
    back_to_school       14 days before the school session starts
    month_end            last day of the month
    december             any day in December (year-end holidays)
    """
    index = _dates(dates).normalize()
    day, month = index.day, index.month
    years = range(index.year.min() - 1, index.year.max() + 2)

    double = pd.Series([(m, d) in DOUBLE_DAYS for m, d in zip(month, day)], index=index).astype(int)
    double_dates = [pd.Timestamp(year=y, month=m, day=d) for y in years for m, d in DOUBLE_DAYS]
    adjacent = (_window(index, double_dates, 1, 1) - double).clip(lower=0)
    raya = _window(index, HARI_RAYA, 0, RAYA_CLOSURE_DAYS)

    features = pd.DataFrame({
        "payday": ((day >= 25) | (day <= 2)).astype(int),
        "double_day": double.values,
        "double_day_adjacent": adjacent.values,
        "black_friday": _window(index, black_fridays(years), 0, 3).values,
        "pre_raya": _window(index, HARI_RAYA, PRE_RAYA_DAYS, -1).values,
        "raya_closure": raya.values,
        "chinese_new_year": _window(index, CHINESE_NEW_YEAR, 0, 1).values,
        "deepavali": _window(index, DEEPAVALI, 0, 0).values,
        "back_to_school": _window(index, SCHOOL_REOPENING, BACK_TO_SCHOOL_DAYS, -1).values,
        "month_end": index.is_month_end.astype(int),
        "december": (month == 12).astype(int),
    }, index=index)
    return features[FEATURES]


def prophet_holidays(start="2024-01-01", end="2027-12-31") -> pd.DataFrame:
    """Holiday table for Prophet: one-day events with windows (pre-Raya, payday, school are regressors)."""
    years = range(pd.Timestamp(start).year, pd.Timestamp(end).year + 1)
    rows = []
    for y in years:
        for m, d in DOUBLE_DAYS:
            rows.append(("double_day", pd.Timestamp(year=y, month=m, day=d), -1, 1))
    rows += [("black_friday", d, 0, 3) for d in black_fridays(years)]
    rows += [("hari_raya", d, 0, RAYA_CLOSURE_DAYS) for d in _dates(HARI_RAYA)]
    rows += [("chinese_new_year", d, -1, 1) for d in _dates(CHINESE_NEW_YEAR)]
    rows += [("deepavali", d, -1, 0) for d in _dates(DEEPAVALI)]
    table = pd.DataFrame(rows, columns=["holiday", "ds", "lower_window", "upper_window"])
    return table[(table["ds"] >= pd.Timestamp(start)) & (table["ds"] <= pd.Timestamp(end))].reset_index(drop=True)
