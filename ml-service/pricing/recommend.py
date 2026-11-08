"""recommend_price(product): a suggested listing price for one pair, with an 80% range and the reasons in RM.

    recommend_price({"name": "Nike Dunk Low", "brand": "Nike", "category": "Sneakers", "condition": "good",
                     "sole_condition": "good", "box_included": False, "size": 9, "color": "White/Black",
                     "material": "Leather", "retail_price": 469})

The reasons add up: the first line is the starting point (retail price x the model's typical resale ratio),
then the 4 biggest contributions in RM, then "Other details" for the rest, so that
starting point + contributions = the model's estimate (the suggested price is that, rounded to the nearest RM5).
Contributions are SHAP values on the price/retail ratio, converted to RM with this pair's retail price.

Missing retail price: the median retail price of the same model, else of the brand, else of all pairs, is used
(see "notes").
"""
from __future__ import annotations

from pathlib import Path

import joblib
import pandas as pd

from .data import FEATURES, months_since_start

MODEL_PATH = Path(__file__).resolve().parent.parent / "models" / "price_model.joblib"
CONDITION_LABELS = {"like_new": "Like new", "good": "Good", "fair": "Fair"}
SOLE_LABELS = {"excellent": "Excellent", "good": "Good", "fair": "Fair", "worn": "Worn"}
TOP_REASONS = 4
UNSEEN_MODEL_ERROR_RM = 60  # typical error for a model with no sales history (notebook 05, unseen-model test)
WARNING_ABOVE_RETAIL = "Above original retail price - hyped model, check before listing."
WARNING_UNSEEN_MODEL = f"New model with no sales history - price is less certain (typical error about RM{UNSEEN_MODEL_ERROR_RM})."
HINT_MISSING_DETAILS = "Fill in material, sole condition and box for a more accurate price."
# Details that make the price more accurate; if one is missing the result carries HINT_MISSING_DETAILS.
DETAIL_FIELDS = ("material", "sole_condition", "box_included")
# The retail price is already in the starting point, so its own (small) effect on the resale ratio is not
# shown as a separate reason; it is part of "Other details". Features that weren't given are never shown
# as a reason either ("Material not given" says nothing useful); their amounts go to "Other details" too.
HIDDEN_FROM_REASONS = {"retail_price"}


def _missing(value) -> bool:
    return value is None or (isinstance(value, str) and not value.strip()) or (isinstance(value, float) and value != value)


def round_to(value: float, step: int = 5) -> int:
    return int(step * round(float(value) / step))


def _rm(amount: float) -> str:
    return f"{'+' if amount >= 0 else '-'}RM{abs(amount):,.0f}"


def _describe(feature: str, value, unseen: bool) -> str:
    if feature == "condition":
        return f"{CONDITION_LABELS.get(value, value)} condition"
    if feature == "sole_condition":
        return f"{SOLE_LABELS.get(value, value)} sole" if isinstance(value, str) else "Sole condition not given"
    if feature == "box_included":
        return "Original box included" if value else "No original box"
    if feature == "name":
        return f"{value} is a new model (no sales history yet)" if unseen else f"Model {value}"
    if feature == "brand":
        return f"Brand {value}"
    if feature == "category":
        return f"Category {value}"
    if feature == "size":
        return f"Size UK {value:g}"
    if feature == "color":
        return f"Colour {value}" if isinstance(value, str) else "Colour not given"
    if feature == "material":
        return f"{value} material" if isinstance(value, str) else "Material not given"
    if feature == "months_since_start":
        return "Current popularity of this model"
    return feature


def recommend_price(product: dict, model=None, listed_at=None) -> dict:
    """Suggested price (nearest RM5), 80% range, reasons that add up, and warning flags.

    Returns: suggested_price, range_80, model_estimate, reasons (list of text, first = starting point),
    breakdown (list of {"label", "rm"}; sums to model_estimate), above_retail, unseen_model, warnings, notes,
    retail_price_used, retail_price_estimated, missing_details, hint.

    box_included may be None (not given): the model then fills in the usual value and the hint is shown."""
    model = model or joblib.load(MODEL_PATH)
    notes = []
    row = {f: product.get(f) for f in FEATURES if f != "months_since_start"}
    for field in ("color", "material", "sole_condition"):
        if _missing(row.get(field)):
            row[field] = None
    row["box_included"] = float("nan") if product.get("box_included") is None else int(bool(product.get("box_included")))
    missing_details = [f for f in DETAIL_FIELDS if _missing(row.get(f))]
    retail_estimated = not row.get("retail_price")

    if not row.get("retail_price"):
        ref = getattr(model, "retail_reference_", None)
        if ref is None:
            from .data import retail_reference

            ref = retail_reference()
        if row.get("name") in ref["name"]:
            row["retail_price"], source = ref["name"][row["name"]], f"the median for {row['name']}"
        elif row.get("brand") in ref["brand"]:
            row["retail_price"], source = ref["brand"][row["brand"]], f"the median for {row['brand']}"
        else:
            row["retail_price"], source = ref["all"], "the median of all pairs"
        notes.append(f"Retail price not given: used RM{row['retail_price']:,.0f} ({source}).")

    row["months_since_start"] = float(months_since_start(pd.Timestamp(listed_at or product.get("listed_at") or pd.Timestamp.now())))
    X = pd.DataFrame([row])
    pred = model.predict(X).iloc[0]
    retail = float(row["retail_price"])
    known = getattr(model, "known_models_", None)
    unseen = known is not None and row.get("name") not in known

    breakdown = []
    explanation = model.explain(X)
    if explanation is not None:
        parts = explanation.iloc[0]
        start = float(parts.pop("base"))
        breakdown.append({"label": f"Retail RM{retail:,.0f} x typical resale ratio {start / retail:.0%} = RM{start:,.0f}", "rm": start})
        hidden = HIDDEN_FROM_REASONS | {f for f in parts.index if _missing(X.iloc[0][f])}
        shown = parts.drop(labels=list(hidden & set(parts.index)))
        top = shown.reindex(shown.abs().sort_values(ascending=False).index).head(TOP_REASONS)
        for feature, rm in top.items():
            breakdown.append({"label": f"{_describe(feature, X.iloc[0][feature], unseen)}: {_rm(rm)}", "rm": float(rm)})
        other = float(parts.drop(labels=top.index).sum())
        breakdown.append({"label": f"Other details: {_rm(other)}", "rm": other})

    suggested = round_to(pred["yhat"])
    above_retail = suggested > retail
    warnings = ([WARNING_ABOVE_RETAIL] if above_retail else []) + ([WARNING_UNSEEN_MODEL] if unseen else [])
    return {
        "suggested_price": suggested,
        "range_80": (round_to(pred["lower"]), round_to(pred["upper"])),
        "model_estimate": round(float(pred["yhat"]), 2),
        "reasons": [b["label"] for b in breakdown],
        "breakdown": breakdown,
        "above_retail": bool(above_retail),
        "unseen_model": bool(unseen),
        "warnings": warnings,
        "notes": notes,
        "retail_price_used": retail,
        "retail_price_estimated": bool(retail_estimated),
        "missing_details": missing_details,
        "hint": HINT_MISSING_DETAILS if missing_details else None,
    }
