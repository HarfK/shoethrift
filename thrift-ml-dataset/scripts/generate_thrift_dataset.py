"""
Step 2 - Generate a semi-synthetic Malaysian thrift-shoe sales history that matches the
`thriftstore` database schema (products, users, addresses, orders, order_items,
user_interactions, user_preferences).

How it is built (write this in your methodology chapter):
  * Demand shape is CALIBRATED from real resale data (calibration.json, from StockX):
    weekday pattern, day-to-day persistence (AR(1)), size demand curve, size price premium.
  * Malaysian calendar effects are added: payday, 9.9/10.10/11.11/12.12, Black Friday,
    Hari Raya Aidilfitri, Chinese New Year, Deepavali, school reopening, monsoon season.
  * Thrift pricing logic: fair price = retail price x model resale ratio x condition x sole
    x box x size premium x model hype trend; the admin's listed price has human error,
    so over-priced pairs sell slower (and get marked down after 45 days).
  * Each pair is unique (stock = 1). The shop restocks weekly from bales, using a lagging
    view of what is popular, so trending models/sizes run out -> realistic restock signal.

Usage:  python generate_thrift_dataset.py calibration.json OUTPUT_DIR
All timestamps are written in UTC (Malaysia time - 8h), like Laravel's default.
"""
import csv
import json
import math
import os
import random
import string
import sys
import uuid
from datetime import date, datetime, timedelta

import numpy as np

SEED = 2026
rng = np.random.default_rng(SEED)
random.seed(SEED)

cal = json.load(open(sys.argv[1]))
OUT = sys.argv[2]
os.makedirs(OUT, exist_ok=True)

START = date(2024, 11, 1)
END = date(2026, 11, 7)  # inclusive, day before "today"
N_DAYS = (END - START).days + 1
MYT = timedelta(hours=8)

# --------------------------------------------------------------------------------------
# Catalogue: the 22 models already in the store (so every product has real photos).
# retail = approximate Malaysian retail price (RM) when new.
# resale = typical like-new thrift value as a fraction of retail.
# pop    = base popularity (relative demand), trend = popularity change per year (log).
# --------------------------------------------------------------------------------------
MODELS = [
    # name,                          brand,        cat, retail, resale, pop, trend, materials,            colors
    ("Nike Air Force 1",              "Nike",        1, 459, 0.62, 1.30, 0.00, ["leather"],             ["White", "Black", "White/Black", "Red/White/Black"]),
    ("Nike Dunk Low",                 "Nike",        1, 459, 0.70, 1.35, -0.30, ["leather"],            ["Black/White", "White", "Grey", "Green/White"]),
    ("Nike Air Jordan 1",             "Nike",        1, 649, 0.72, 1.00, -0.10, ["leather"],            ["White", "Red/White/Black", "Navy", "Black/White"]),
    ("Nike Pegasus 40",               "Nike",        2, 579, 0.48, 0.65, 0.00, ["mesh"],                ["Black", "Black/White", "Grey", "Beige"]),
    ("Adidas Samba OG",               "Adidas",      3, 459, 0.78, 1.10, 0.45, ["leather", "suede"],    ["Black/White", "White", "Red/White/Black", "Beige"]),
    ("Adidas Superstar",              "Adidas",      1, 429, 0.50, 0.75, 0.00, ["leather"],             ["White", "Black", "Brown"]),
    ("Adidas Ultraboost",             "Adidas",      2, 799, 0.42, 0.60, -0.15, ["mesh"],               ["Black", "Navy", "Grey", "Green/White"]),
    ("New Balance 550",               "New Balance", 1, 559, 0.65, 1.05, 0.10, ["leather"],             ["White", "Green/White", "Beige", "Black/White"]),
    ("New Balance 327",               "New Balance", 1, 469, 0.52, 0.75, -0.05, ["suede"],              ["Grey", "Green/White", "Beige", "Brown"]),
    ("Asics Gel-Lyte III",            "Asics",       1, 599, 0.55, 0.55, 0.35, ["suede"],               ["Grey", "Black/White", "Beige", "Green/White"]),
    ("Asics Gel-Kayano 30",           "Asics",       2, 749, 0.50, 0.45, 0.30, ["mesh"],                ["Grey", "Black", "White"]),
    ("Converse Chuck 70",             "Converse",    3, 349, 0.55, 0.85, 0.05, ["canvas"],              ["Black", "White", "Brown", "Green/White"]),
    ("Converse Chuck Taylor All Star", "Converse",   3, 249, 0.50, 0.80, -0.05, ["canvas"],             ["Black", "White", "Navy"]),
    ("Vans Old Skool",                "Vans",        3, 289, 0.52, 0.85, 0.00, ["canvas", "suede"],     ["Black/White", "Navy", "Black"]),
    ("Puma Suede Classic",            "Puma",        3, 339, 0.45, 0.40, 0.05, ["suede"],               ["Black", "Navy", "Red/White/Black"]),
    ("Puma RS-X",                     "Puma",        1, 499, 0.40, 0.35, -0.35, ["mesh"],               ["White", "Black", "Grey"]),
    ("Birkenstock Arizona",           "Birkenstock", 6, 529, 0.58, 0.60, 0.25, ["cork"],                ["Beige", "Brown", "Black"]),
    ("Crocs Classic Clog",            "Crocs",       6, 249, 0.40, 0.65, 0.00, ["rubber"],              ["Black", "Beige", "Navy", "White"]),
    ("Dr. Martens 1460 Boot",         "Dr. Martens", 4, 899, 0.55, 0.35, 0.00, ["leather"],             ["Black", "Brown"]),
    ("Dr. Martens 1461 Oxford",       "Dr. Martens", 5, 799, 0.55, 0.25, 0.00, ["leather"],             ["Black", "Brown"]),
    ("Clarks Tilden Cap Oxford",      "Clarks",      5, 399, 0.45, 0.30, 0.00, ["leather"],             ["Black", "Brown"]),
    ("Clarks Desert Boot",            "Clarks",      4, 699, 0.50, 0.30, 0.00, ["suede", "leather"],    ["Beige", "Brown", "Navy"]),
]
MODEL_KEYS = ["name", "brand", "cat", "retail", "resale", "pop", "trend", "materials", "colors"]
MODELS = [dict(zip(MODEL_KEYS, m)) for m in MODELS]
for i, m in enumerate(MODELS):
    m["idx"] = i

COND_FACTOR = {"like_new": 1.00, "good": 0.78, "fair": 0.58}
SOLE_FACTOR = {"excellent": 1.03, "good": 1.00, "fair": 0.94, "worn": 0.86}
SOLE_GIVEN_COND = {
    "like_new": (["excellent", "good"], [0.8, 0.2]),
    "good": (["excellent", "good", "fair"], [0.15, 0.65, 0.20]),
    "fair": (["good", "fair", "worn"], [0.20, 0.50, 0.30]),
}
SIZES = [x / 2 for x in range(6, 25)]  # UK 3.0 .. 12.0
SIZE_PREMIUM = {float(k): 1 + 0.5 * (v - 1) for k, v in cal["size_price_premium_uk"].items()}

# StockX is US men's resale; Malaysian buyers have smaller feet -> shift the curve 1 size down
_sx = {float(k): v for k, v in cal["size_demand_share_uk"].items()}
MEN_SIZE_P = np.array([_sx.get(s + 1.0, 0.004) for s in SIZES])
MEN_SIZE_P /= MEN_SIZE_P.sum()
WOMEN_SIZE_P = np.array([math.exp(-((s - 5.0) ** 2) / (2 * 1.1 ** 2)) for s in SIZES])
WOMEN_SIZE_P /= WOMEN_SIZE_P.sum()
DEMAND_SIZE_P = 0.6 * MEN_SIZE_P + 0.4 * WOMEN_SIZE_P
# what arrives in thrift bales is broader than what people want -> size mismatch
SUPPLY_SIZE_P = 0.75 * DEMAND_SIZE_P + 0.25 / len(SIZES)

WEEKDAY = cal["weekday_factor_mon_to_sun"]
PHI = cal["ar1_phi_daily_log_demand"]
SIGMA = cal["residual_sd_daily_log_demand"]  # calibrated from StockX

# --------------------------------------------------------------------------------------
# Malaysian calendar
# --------------------------------------------------------------------------------------
RAYA = [date(2025, 3, 31), date(2026, 3, 20)]
CNY = [date(2025, 1, 29), date(2026, 2, 17)]
DEEPAVALI = [date(2025, 10, 20)]
SCHOOL = [date(2025, 2, 17), date(2026, 1, 12)]  # approx. school session start
BLACK_FRIDAY = [date(2024, 11, 29), date(2025, 11, 28)]


def calendar_effect(d):
    """Return (overall multiplier, {category_id: extra multiplier})."""
    f, cat = 1.0, {}
    # payday (25th to 2nd)
    if d.day >= 25 or d.day <= 2:
        f *= 1.15
    # double-day campaigns
    if d.month == d.day and d.month in (9, 10, 11, 12):
        f *= {9: 1.5, 10: 1.35, 11: 2.3, 12: 1.9}[d.month]
    elif d.month in (11, 12) and abs(d.day - d.month) == 1:
        f *= 1.25
    if d in BLACK_FRIDAY:
        f *= 1.45
    if d.month == 12:
        f *= 1.08
        if 20 <= d.day <= 26:
            f *= 1.15
    for r in RAYA:
        delta = (r - d).days
        if 1 <= delta <= 21:  # pre-Raya shopping ramps up
            f *= 1.0 + 0.6 * (1 - abs(delta - 6) / 16)
            cat[5] = cat.get(5, 1) * 2.0  # formal
            cat[3] = cat.get(3, 1) * 1.3  # casual
        elif -3 <= delta <= 0:  # Raya days: everyone balik kampung
            f *= 0.45
    for c in CNY:
        delta = (c - d).days
        if 1 <= delta <= 10:
            f *= 1.2
        elif -2 <= delta <= 0:
            f *= 0.65
    for dv in DEEPAVALI:
        if 1 <= (dv - d).days <= 7:
            f *= 1.1
    for s in SCHOOL:
        if 1 <= (s - d).days <= 10:
            f *= 1.15
            cat[2] = cat.get(2, 1) * 1.4  # running/school PE shoes
    # monsoon (Nov-Jan): boots up, sandals down; dry/hot months: sandals up
    if d.month in (11, 12, 1):
        cat[4] = cat.get(4, 1) * 1.35
        cat[6] = cat.get(6, 1) * 0.8
    elif 3 <= d.month <= 8:
        cat[6] = cat.get(6, 1) * 1.2
    return f, cat


def base_rate(t):
    """Shop growth: ~2.0 orders/day at launch, ~4.6/day two years later (logistic)."""
    x = t / N_DAYS
    return 2.0 + 2.6 / (1 + math.exp(-6 * (x - 0.45)))


def model_pop(m, t):
    return m["pop"] * math.exp(m["trend"] * t / 365)


# --------------------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------------------
def ts(dt):
    """local MYT datetime -> UTC string"""
    return (dt - MYT).strftime("%Y-%m-%d %H:%M:%S")


def rand_alnum(n, upper=False):
    chars = (string.ascii_uppercase if upper else string.ascii_lowercase) + string.digits
    return "".join(random.choice(chars) for _ in range(n))


def slugify(s):
    return "".join(c.lower() if c.isalnum() else "-" for c in s).replace("--", "-").strip("-")


def round5(x):
    return max(40.0, float(5 * round(x / 5)))


def local_time(d, kind="order"):
    if kind == "order":  # evening peak, lunch bump
        h = int(rng.choice(range(24), p=HOUR_P))
    else:  # admin listing hours
        h = int(rng.integers(10, 18))
    return datetime(d.year, d.month, d.day, h, int(rng.integers(0, 60)), int(rng.integers(0, 60)))


_h = np.array([0.5, 0.3, 0.15, 0.1, 0.1, 0.15, 0.4, 0.8, 1.2, 1.6, 2.0, 2.4,
               3.2, 3.0, 2.4, 2.2, 2.2, 2.5, 3.0, 3.6, 4.6, 5.2, 4.8, 2.4])
HOUR_P = _h / _h.sum()

# --------------------------------------------------------------------------------------
# Customers
# --------------------------------------------------------------------------------------
MALAY_M = ["Ahmad", "Muhammad", "Amirul", "Haziq", "Hafiz", "Faris", "Aiman", "Irfan", "Danial", "Syafiq",
           "Hakim", "Izzat", "Zulkifli", "Azlan", "Firdaus", "Arif", "Imran", "Luqman", "Shahrul", "Nazmi"]
MALAY_F = ["Nur Aisyah", "Siti Nurhaliza", "Nurul Izzah", "Farah", "Aina", "Syafiqah", "Alya", "Hannah",
           "Nadia", "Amira", "Balqis", "Iman", "Sofea", "Damia", "Qistina", "Atiqah", "Hani", "Liyana"]
MALAY_FATHER = ["Abdullah", "Ismail", "Rahman", "Hassan", "Ibrahim", "Yusof", "Osman", "Razak", "Kamal",
                "Salleh", "Aziz", "Hamid", "Mohd Noor", "Zainal", "Rashid", "Latif"]
CHINESE_SUR = ["Tan", "Lim", "Lee", "Wong", "Ng", "Chong", "Teh", "Ong", "Goh", "Chan", "Liew", "Yap", "Khoo", "Low"]
CHINESE_GIVEN_M = ["Wei Jie", "Jun Hao", "Kah Meng", "Zhi Hao", "Chee Keong", "Jia Le", "Yong Sheng", "Kai Xiang"]
CHINESE_GIVEN_F = ["Mei Ling", "Hui Min", "Xin Yi", "Jia Hui", "Pei Shan", "Yee Ling", "Siew Mei", "Wen Qi"]
INDIAN_M = ["Arun", "Kumar", "Ravi", "Vignesh", "Prakash", "Suresh", "Dinesh", "Kavin"]
INDIAN_F = ["Priya", "Kavitha", "Shalini", "Deepa", "Thivya", "Nisha", "Divya", "Meena"]
INDIAN_FATHER = ["Muthu", "Rajan", "Subramaniam", "Krishnan", "Ganesan", "Raman", "Selvam", "Nair"]

PLACES = [  # city, state, postcode range, weight
    ("Shah Alam", "Selangor", (40000, 40470), 9), ("Petaling Jaya", "Selangor", (46000, 47810), 9),
    ("Subang Jaya", "Selangor", (47500, 47650), 6), ("Kajang", "Selangor", (43000, 43009), 5),
    ("Klang", "Selangor", (41000, 41960), 5), ("Kuala Lumpur", "W.P. Kuala Lumpur", (50000, 60000), 12),
    ("Putrajaya", "W.P. Putrajaya", (62000, 62988), 2), ("Johor Bahru", "Johor", (80000, 81300), 7),
    ("Ipoh", "Perak", (30000, 31650), 4), ("George Town", "Pulau Pinang", (10000, 11960), 5),
    ("Kota Bharu", "Kelantan", (15000, 16450), 3), ("Kuala Terengganu", "Terengganu", (20000, 21810), 3),
    ("Kuantan", "Pahang", (25000, 26820), 3), ("Seremban", "Negeri Sembilan", (70000, 71950), 3),
    ("Melaka", "Melaka", (75000, 78309), 3), ("Alor Setar", "Kedah", (5000, 6990), 2),
    ("Kangar", "Perlis", (1000, 2800), 1), ("Kota Kinabalu", "Sabah", (88000, 88999), 2),
    ("Kuching", "Sarawak", (93000, 93990), 2),
]
PLACE_P = np.array([p[3] for p in PLACES], float)
PLACE_P /= PLACE_P.sum()
STREETS = ["Jalan Mawar", "Jalan Melati", "Jalan Kenanga", "Jalan Cempaka", "Jalan Teratai", "Jalan Dahlia",
           "Jalan Seroja", "Jalan Anggerik", "Jalan Bunga Raya", "Jalan Delima", "Jalan Pinang", "Jalan Merbau"]
TAMANS = ["Taman Sri Muda", "Taman Desa", "Taman Bukit Indah", "Taman Seri Putra", "Taman Melawati",
          "Taman Universiti", "Taman Sentosa", "Taman Perling", "Taman Cahaya", "Taman Mutiara"]

users, addresses, preferences = [], [], []
used_emails = set()


def new_customer(day):
    eth = rng.choice(["malay", "chinese", "indian"], p=[0.62, 0.26, 0.12])
    gender = "M" if rng.random() < 0.6 else "F"
    if eth == "malay":
        first = random.choice(MALAY_M if gender == "M" else MALAY_F)
        name = f"{first} {'bin' if gender == 'M' else 'binti'} {random.choice(MALAY_FATHER)}"
        handle = first.split()[-1].lower()
    elif eth == "chinese":
        sur = random.choice(CHINESE_SUR)
        name = f"{sur} {random.choice(CHINESE_GIVEN_M if gender == 'M' else CHINESE_GIVEN_F)}"
        handle = name.replace(" ", "").lower()[:10]
    else:
        first = random.choice(INDIAN_M if gender == "M" else INDIAN_F)
        name = f"{first} {'a/l' if gender == 'M' else 'a/p'} {random.choice(INDIAN_FATHER)}"
        handle = first.lower()
    email = f"{handle}{rng.integers(10, 9999)}@example.com"
    while email in used_emails:
        email = f"{handle}{rng.integers(10, 99999)}@example.com"
    used_emails.add(email)
    p3 = rng.choice(["012", "013", "014", "016", "017", "018", "019", "011"])
    num = f"{rng.integers(100, 999)} {rng.integers(1000, 9999)}"
    phone = f"{p3}-{num}"
    place = PLACES[rng.choice(len(PLACES), p=PLACE_P)]
    postcode = f"{rng.integers(*place[2]):05d}"
    line1 = f"No. {rng.integers(1, 120)}, {random.choice(STREETS)} {rng.integers(1, 12)}"
    line2 = random.choice(TAMANS)
    created = local_time(day - timedelta(days=int(rng.integers(1, 20))), "order")
    size_p = MEN_SIZE_P if gender == "M" else WOMEN_SIZE_P
    size = float(rng.choice(SIZES, p=size_p))
    fav = list(rng.choice([m["brand"] for m in MODELS], size=2, replace=False))
    key = f"U{len(users) + 1:05d}"
    u = {
        "key": key, "name": name, "email": email, "phone": phone,
        "phone_normalized": "60" + (p3 + num.replace(" ", ""))[1:],
        "email_verified_at": ts(created + timedelta(minutes=int(rng.integers(2, 90)))),
        "created_at": ts(created),
        # simulation-only fields
        "_size": size, "_fav": fav, "_loyal": float(rng.pareto(1.6) + 0.2),
        "_ship": (name, phone, f"{line1}\n{line2}\n{postcode} {place[0]}\n{place[1]}"),
    }
    users.append(u)
    addresses.append({"user_key": key, "label": random.choice(["Home", "Home", None, "Office"]) or "",
                      "recipient_name": name, "phone": phone, "line1": line1, "line2": line2,
                      "postcode": postcode, "city": place[0], "state": place[1], "is_default": 1,
                      "created_at": u["created_at"]})
    if rng.random() < 0.4:
        lo = int(rng.choice([0, 50, 100]))
        preferences.append({"user_key": key, "shoe_size": size, "preferred_brands": json.dumps(fav),
                            "min_price": lo, "max_price": int(rng.choice([200, 300, 400, 500])),
                            "created_at": u["created_at"]})
    return u


# the existing test customer account gets a real purchase history too
TEST_CUSTOMER = {
    "key": "KEEP:customer@thriftstore.test", "_size": 8.0, "_fav": ["Nike", "Adidas"], "_loyal": 3.0,
    "_ship": ("Test Customer", "012-15151514", "dqdqdq\ndwwdwdww\n21700 KUALA BERANG\nTerengganu"),
}

# --------------------------------------------------------------------------------------
# Products / inventory
# --------------------------------------------------------------------------------------
products = []
pool = {}  # (model_idx, size) -> set(product index)
DESCS = ["Pre-loved pair, cleaned and inspected.", "Original laces and insoles.",
         "Deep-cleaned and deodorised before listing.", "Authentic pair, checked by our team."]
WEAR = {
    "like_new": ["Worn 1-2 times, no visible creasing.", "Almost new, only minor marks on the outsole."],
    "good": ["Light creasing on the toe box, minor sole wear.", "Some creasing; upper is clean."],
    "fair": ["Visible creasing and sole wear; small scuffs on upper. Priced accordingly.",
             "Heavier wear on heel and outsole, still very wearable."],
}


def fair_value(m, cond, sole, box, size, t):
    hype = math.exp(0.5 * m["trend"] * t / 365)  # popular-and-rising models resell higher
    return (m["retail"] * m["resale"] * COND_FACTOR[cond] * SOLE_FACTOR[sole]
            * (1.08 if box else 1.0) * SIZE_PREMIUM.get(size, 1.0) * hype)


def list_product(m, listed_local, t):
    cond = str(rng.choice(["like_new", "good", "fair"], p=[0.25, 0.5, 0.25]))
    opts, probs = SOLE_GIVEN_COND[cond]
    sole = str(rng.choice(opts, p=probs))
    box = int(rng.random() < {"like_new": 0.45, "good": 0.2, "fair": 0.05}[cond])
    size = float(rng.choice(SIZES, p=SUPPLY_SIZE_P))
    fv = fair_value(m, cond, sole, box, size, t)
    price = round5(fv * rng.lognormal(0, 0.11))  # admin's pricing is not perfect
    insole = round(((size + 23) * 0.847 + 0.4) * 2) / 2
    p = {
        "id": len(products) + 1, "category_id": m["cat"], "name": m["name"],
        "slug": f"{slugify(m['name'])}-{rand_alnum(6)}", "brand": m["brand"],
        "description": random.choice(DESCS) + ("" if box else " No box."),
        "price": price, "retail_price": float(m["retail"]), "condition": cond, "size": size,
        "color": random.choice(m["colors"]), "material": random.choice(m["materials"]),
        "insole_length_cm": insole, "sole_condition": sole, "box_included": box,
        "wear_notes": random.choice(WEAR[cond]),
        "authenticity_notes": "Checked size tag, stitching and insole print against a retail pair."
        if m["retail"] >= 450 else "",
        "stock": 1, "status": "available", "created_at": ts(listed_local), "updated_at": ts(listed_local),
        "_m": m["idx"], "_fv": fv, "_listed_t": t, "_marked": False,
    }
    products.append(p)
    pool.setdefault((m["idx"], size), set()).add(p["id"] - 1)


def restock_batch(d, t, n):
    lag_t = max(t - 60, -60)  # admin buys based on what was popular ~2 months ago
    w = np.array([model_pop(m, lag_t) for m in MODELS])
    w = w / w.sum()
    for _ in range(n):
        m = MODELS[rng.choice(len(MODELS), p=w)]
        listed = local_time(d - timedelta(days=1), "listing")
        list_product(m, listed, t - 1)


# initial stock listed in October 2024
for _ in range(160):
    lt = -int(rng.integers(1, 25))
    restock_batch(START + timedelta(days=lt + 1), lt + 1, 1)

# --------------------------------------------------------------------------------------
# Daily simulation
# --------------------------------------------------------------------------------------
orders, items, interactions = [], [], []
release_queue = []  # (local datetime, product index) for cancelled/failed orders
lost_demand = 0
ar = 0.0
used_order_no = set()
p_new_customer = 0.2


def pick_customer(d):
    if not users or rng.random() < p_new_customer:
        return new_customer(d)
    if rng.random() < 0.012:
        return TEST_CUSTOMER
    w = np.array([u["_loyal"] for u in users])
    return users[rng.choice(len(users), p=w / w.sum())]


def choose_product(cust, t, cat_boost):
    """Customer wants a model (by popularity, favourite brands, season) in their size (+/-0.5)."""
    w = np.array([model_pop(m, t) ** 1.5 * cat_boost.get(m["cat"], 1.0)
                  * (2.0 if m["brand"] in cust["_fav"] else 1.0) for m in MODELS])
    if rng.random() < 0.45:
        # "I want THIS model" shopper: only accepts one model, leaves if it's not in their size
        keep = rng.choice(len(MODELS), p=w / w.sum())
        mask = np.zeros(len(MODELS))
        mask[keep] = 1
        w = w * mask
    cands = []
    for mi in np.nonzero(w)[0]:
        for s in (cust["_size"], cust["_size"] - 0.5, cust["_size"] + 0.5):
            cands += list(pool.get((int(mi), s), ()))
    if not cands:
        return None  # lost sale: nothing suitable in their size -> restock signal
    # popular model, good value (fair value / price) and exact size are preferred
    vw = np.array([w[products[i]["_m"]] * (products[i]["_fv"] / products[i]["price"]) ** 4
                   * (1.0 if products[i]["size"] == cust["_size"] else 0.5) for i in cands])
    return cands[rng.choice(len(cands), p=vw / vw.sum())]


def take(pi):
    p = products[pi]
    pool[(p["_m"], p["size"])].discard(pi)


def put_back(pi):
    p = products[pi]
    pool.setdefault((p["_m"], p["size"]), set()).add(pi)


def all_available():
    return [i for s in pool.values() for i in s]


for t in range(N_DAYS):
    d = START + timedelta(days=t)
    day_start = datetime(d.year, d.month, d.day)

    # cancelled/failed orders release their pairs back to the shelf
    release_queue.sort()
    while release_queue and release_queue[0][0] < day_start:
        put_back(release_queue.pop(0)[1])

    # weekly restock (Mondays), sized on expected demand, bigger before Raya & Nov-Dec
    if d.weekday() == 0:
        f_next = np.mean([calendar_effect(d + timedelta(days=k))[0] for k in range(7, 21)])
        n = int(round(7 * base_rate(t) * 1.0 * f_next * rng.lognormal(0, 0.15)))
        restock_batch(d, t, n)

    # admin marks down pairs unsold for 45+ days (once), hides some very old stock
    if d.weekday() == 3:
        for pi in all_available():
            p = products[pi]
            age = t - p["_listed_t"]
            if (age >= 45 and p["_marked"] is False) or (age >= 100 and p["_marked"] is True):
                p["price"] = round5(p["price"] * 0.85)  # markdown 15% at 45 days, again at 100 days
                p["_marked"] = "twice" if p["_marked"] else True
                p["updated_at"] = ts(local_time(d, "listing"))
            elif age >= 180 and rng.random() < 0.04:
                take(pi)
                p["status"] = "hidden"
                p["updated_at"] = ts(local_time(d, "listing"))

    ar = PHI * ar + rng.normal(0, SIGMA * math.sqrt(1 - PHI ** 2))
    f, cat_boost = calendar_effect(d)
    lam = base_rate(t) * WEEKDAY[d.weekday()] * f * math.exp(ar)
    for _ in range(rng.poisson(lam)):
        cust = pick_customer(d)
        n_items = int(rng.choice([1, 2, 3], p=[0.85, 0.13, 0.02]))
        chosen = []
        for _ in range(n_items):
            pi = choose_product(cust, t, cat_boost)
            if pi is None:
                lost_demand += 1
                continue
            take(pi)
            chosen.append(pi)
        if not chosen:
            continue
        created = local_time(d, "order")
        oid = len(orders) + 1
        on = f"TS-{d:%Y%m%d}-{rand_alnum(6, True)}"
        while on in used_order_no:
            on = f"TS-{d:%Y%m%d}-{rand_alnum(6, True)}"
        used_order_no.add(on)
        method = "online" if rng.random() < 0.7 else "cod"
        subtotal = sum(products[i]["price"] for i in chosen)
        o = {"id": oid, "user_key": cust["key"], "order_number": on, "total": subtotal + 10.0,
             "refunded_amount": 0.0, "shipping_fee": 10.0, "status": "", "payment_method": method,
             "gateway_reference": "", "gateway_payment_id": "", "payment_expires_at": "", "paid_at": "",
             "delivered_at": "", "delivery_failed_at": "", "shipping_name": cust["_ship"][0],
             "shipping_phone": cust["_ship"][1], "shipping_address": cust["_ship"][2],
             "created_at": ts(created), "updated_at": ts(created)}
        age_days = (END - d).days
        r = rng.random()
        fail_time = None
        if method == "online":
            o["gateway_reference"] = str(uuid.UUID(int=int(rng.integers(0, 2**63)) << 64 | int(rng.integers(0, 2**63))))
            o["payment_expires_at"] = ts(created + timedelta(minutes=30))
            if r < 0.04:
                o["status"] = "failed"
                fail_time = created + timedelta(minutes=30)
            elif r < 0.07:
                o["status"] = "cancelled"
                fail_time = created + timedelta(hours=int(rng.integers(1, 20)))
            else:
                paid = created + timedelta(minutes=int(rng.integers(1, 12)))
                o["paid_at"] = ts(paid)
                o["gateway_payment_id"] = str(uuid.UUID(int=int(rng.integers(0, 2**63)) << 64 | int(rng.integers(0, 2**63))))
        else:
            if r < 0.06:
                o["status"] = "cancelled"
                fail_time = created + timedelta(hours=int(rng.integers(1, 30)))
            elif r < 0.09:
                o["status"] = "cancelled"
                fail_time = created + timedelta(days=int(rng.integers(3, 6)))
                o["delivery_failed_at"] = ts(fail_time)
        if fail_time:
            o["updated_at"] = ts(fail_time)
            for pi in chosen:
                release_queue.append((fail_time, pi))
        else:
            deliver = created + timedelta(days=int(rng.integers(2, 6)), hours=int(rng.integers(0, 8)))
            if age_days == 0:
                o["status"] = "paid" if method == "online" else "pending"
            elif age_days <= 2:
                o["status"] = "shipped" if method == "online" else "pending"
                if method == "cod" and rng.random() < 0.6:
                    o["status"] = "shipped"
            elif deliver.date() > END:
                o["status"] = "shipped"
            else:
                o["delivered_at"] = ts(deliver)
                if method == "cod":
                    o["paid_at"] = ts(deliver)
                o["status"] = "completed" if age_days > 9 or rng.random() < 0.4 else "delivered"
                if o["status"] == "completed" and rng.random() < 0.015:
                    o["status"] = "refunded"
                    o["refunded_amount"] = subtotal
                o["updated_at"] = ts(deliver + timedelta(days=int(rng.integers(0, 4))))
            for pi in chosen:
                products[pi]["status"] = "sold"
                products[pi]["stock"] = 0
                products[pi]["updated_at"] = ts(created)
        orders.append(o)
        for pi in chosen:
            items.append({"id": len(items) + 1, "order_id": oid, "product_id": pi + 1, "quantity": 1,
                          "price": products[pi]["price"], "created_at": ts(created), "updated_at": ts(created)})
            # browsing behaviour for the recommendation module
            others = all_available()
            for oi in rng.choice(others, size=min(len(others), int(rng.integers(1, 5))), replace=False):
                vt = created - timedelta(minutes=int(rng.integers(5, 240)))
                interactions.append((cust["key"], int(oi) + 1, "view",
                                     "for_you" if rng.random() < 0.2 else "", ts(vt)))
            interactions.append((cust["key"], pi + 1, "view", "for_you" if rng.random() < 0.2 else "",
                                 ts(created - timedelta(minutes=int(rng.integers(3, 60))))))
            interactions.append((cust["key"], pi + 1, "cart", "", ts(created - timedelta(minutes=int(rng.integers(1, 3))))))
            if o["paid_at"]:
                interactions.append((cust["key"], pi + 1, "purchase", "", o["paid_at"]))

    if t % 30 == 0:
        p_new_customer = max(0.12, p_new_customer - 0.005)

# releases still pending after END stay "reserved" -> put back as available
for _, pi in release_queue:
    if products[pi]["status"] == "available":
        put_back(pi)

# --------------------------------------------------------------------------------------
# Write CSVs
# --------------------------------------------------------------------------------------
def write(name, rows, cols):
    with open(os.path.join(OUT, name), "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(cols)
        for r in rows:
            w.writerow([r[c] if isinstance(r, dict) else r[i] for i, c in enumerate(cols)])


write("users.csv", users, ["key", "name", "email", "phone", "phone_normalized", "email_verified_at", "created_at"])
write("addresses.csv", addresses, ["user_key", "label", "recipient_name", "phone", "line1", "line2",
                                   "postcode", "city", "state", "is_default", "created_at"])
write("user_preferences.csv", preferences, ["user_key", "shoe_size", "preferred_brands", "min_price",
                                            "max_price", "created_at"])
write("products.csv", products, ["id", "category_id", "name", "slug", "brand", "description", "price",
                                 "retail_price", "condition", "size", "color", "material", "insole_length_cm",
                                 "sole_condition", "box_included", "wear_notes", "authenticity_notes", "stock",
                                 "status", "created_at", "updated_at"])
write("orders.csv", orders, ["id", "user_key", "order_number", "total", "refunded_amount", "shipping_fee",
                             "status", "payment_method", "gateway_reference", "gateway_payment_id",
                             "payment_expires_at", "paid_at", "delivered_at", "delivery_failed_at",
                             "shipping_name", "shipping_phone", "shipping_address", "created_at", "updated_at"])
write("order_items.csv", items, ["id", "order_id", "product_id", "quantity", "price", "created_at", "updated_at"])
interactions.sort(key=lambda r: r[4])
write("user_interactions.csv", interactions, ["user_key", "product_id", "type", "source", "created_at"])

status = {}
for p in products:
    status[p["status"]] = status.get(p["status"], 0) + 1
ostat = {}
for o in orders:
    ostat[o["status"]] = ostat.get(o["status"], 0) + 1
print(f"customers={len(users)} products={len(products)} {status}")
print(f"orders={len(orders)} {ostat} items={len(items)} interactions={len(interactions)} lost_demand={lost_demand}")
