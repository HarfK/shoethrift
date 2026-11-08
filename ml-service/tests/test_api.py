"""API tests (pytest + FastAPI TestClient). They use the saved models and the real MySQL database (start XAMPP first).

    ml-service\\venv\\Scripts\\python -m pytest ml-service\\tests -v
"""
import os
import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

ML_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ML_DIR))

from api.main import app  # noqa: E402

KEY = os.environ["ML_API_KEY"]  # loaded from ml-service/.env by api.main
KNOWN_PAIR = {"brand": "Nike", "name": "Nike Dunk Low", "category": "Sneakers", "condition": "good", "sole_condition": "good",
              "box_included": False, "size": 9, "color": "White/Black", "material": "Leather", "retail_price": 459}


@pytest.fixture(scope="module")
def client():
    with TestClient(app, headers={"X-API-Key": KEY}) as c:  # "with" runs startup (loads the models)
        yield c


def test_health_ok(client):
    r = client.get("/health")
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "ok"
    for key in ("revenue", "restock", "price"):
        assert body["models"][key]["available"] is True
        assert body["models"][key]["trained_until"]
        assert body["models"][key]["key_metric"]["value"] is not None


def test_forecast_days_and_bounds(client):
    r = client.get("/forecast/revenue", params={"days": 10})
    assert r.status_code == 200
    body = r.json()
    assert len(body["days"]) == 10
    for day in body["days"]:
        assert day["lower"] <= day["forecast"] <= day["upper"]
    assert len(body["last_30_days_actual"]) == 30
    assert body["next_7_days"]["lower"] <= body["next_7_days"]["total"] <= body["next_7_days"]["upper"]


def test_forecast_event_labels_and_major_flags(client):
    body = client.get("/forecast/revenue", params={"days": 30}).json()
    by_key = {d["event_key"]: d for d in body["days"] + body["last_30_days_actual"] if d["event_key"]}
    if "11.11" in by_key:
        assert by_key["11.11"]["event"] == "11.11 shopping day" and by_key["11.11"]["major"] is True
    if "payday" in by_key:
        assert by_key["payday"]["major"] is False
    assert all(("event_key" in d and "major" in d) for d in body["days"] + body["last_30_days_actual"])


def test_forecast_rejects_too_many_days(client):
    r = client.get("/forecast/revenue", params={"days": 31})
    assert r.status_code == 422
    assert "days" in r.json()["details"][0]


def test_restock_total_equals_sum_of_groups(client):
    body = client.get("/restock/report", params={"as_of": "2026-11-08"}).json()
    assert body["summary"]["total_pairs_suggested"] == sum(g["suggested_restock_qty"] for g in body["groups"])
    assert body["summary"]["groups"] == len(body["groups"]) == 66
    assert sum(b["suggested_restock_qty"] for b in body["brands"]) == body["summary"]["total_pairs_suggested"]


def test_restock_risk_filter(client):
    body = client.get("/restock/report", params={"as_of": "2026-11-08", "risk": "HIGH"}).json()
    assert body["groups"] and all(g["risk"] == "HIGH" for g in body["groups"])
    assert all(g["sizes_to_look_for"] for g in body["groups"] if g["suggested_restock_qty"] > 0)
    assert client.get("/restock/report", params={"risk": "URGENT"}).status_code == 422


def test_price_known_model(client):
    r = client.post("/price/recommend", json=KNOWN_PAIR)
    assert r.status_code == 200
    body = r.json()
    assert body["suggested_price"] % 5 == 0
    assert body["range_80"]["lower"] <= body["suggested_price"] <= body["range_80"]["upper"]
    assert body["unseen_model"] is False
    assert abs(sum(b["rm"] for b in body["breakdown"]) - body["model_estimate"]) < 0.05


def test_price_flags_unseen_model(client):
    pair = {**KNOWN_PAIR, "name": "Nike Air Max 90", "retail_price": 599}
    body = client.post("/price/recommend", json=pair).json()
    assert body["unseen_model"] is True
    assert any("New model" in w for w in body["warnings"])


@pytest.mark.parametrize("change, field", [({"size": 13.3}, "size"), ({"size": 8.3}, "size"), ({"condition": "new"}, "condition"),
                                           ({"brand": "Gucci"}, "brand"), ({"material": "Plastic"}, "material")])
def test_price_rejects_bad_input(client, change, field):
    r = client.post("/price/recommend", json={**KNOWN_PAIR, **change})
    assert r.status_code == 422
    body = r.json()
    assert body["error"] == "Invalid input."
    assert any(d.startswith(field) for d in body["details"])


def test_price_reasons_never_show_missing_details_and_give_a_hint(client):
    pair = {k: v for k, v in KNOWN_PAIR.items() if k not in ("material", "sole_condition", "color", "box_included")}
    body = client.post("/price/recommend", json=pair).json()
    assert not any("not given" in r for r in body["reasons"])
    assert body["reasons"][-1].startswith("Other details")
    assert abs(sum(b["rm"] for b in body["breakdown"]) - body["model_estimate"]) < 0.05
    assert body["missing_details"] == ["material", "sole_condition", "box_included"]
    assert body["hint"] == "Fill in material, sole condition and box for a more accurate price."

    full = client.post("/price/recommend", json=KNOWN_PAIR).json()
    assert full["hint"] is None and full["missing_details"] == []


def test_price_flags_an_estimated_retail_price(client):
    body = client.post("/price/recommend", json={**KNOWN_PAIR, "retail_price": None}).json()
    assert body["retail_price_estimated"] is True and body["retail_price_used"] > 0 and body["notes"]
    assert client.post("/price/recommend", json=KNOWN_PAIR).json()["retail_price_estimated"] is False


def test_restock_sizes_are_most_popular_first_with_share(client):
    import re

    body = client.get("/restock/report", params={"as_of": "2026-11-08"}).json()
    for group in body["groups"]:
        if not group["suggested_restock_qty"]:
            continue
        items = re.findall(r"UK ([\d.]+) x(\d+) \((\d+)%\)", group["sizes_to_look_for"])
        assert items, group["sizes_to_look_for"]
        shares = [int(s) for _, _, s in items]
        assert shares == sorted(shares, reverse=True), group["sizes_to_look_for"]
        assert sum(int(n) for _, n, _ in items) == group["suggested_restock_qty"]


def test_missing_model_returns_503(client):
    from api.main import state

    saved = state.models.pop("price")
    state.missing["price"] = "price_model.joblib not found"
    try:
        r = client.post("/price/recommend", json=KNOWN_PAIR)
        assert r.status_code == 503
        assert client.get("/health").json()["models"]["price"]["available"] is False
    finally:
        state.models["price"] = saved
        state.missing.pop("price")


def test_database_down_returns_503(client, monkeypatch):
    import api.main
    from sqlalchemy.exc import OperationalError

    def down(*args, **kwargs):
        raise OperationalError("select 1", {}, Exception("Can't connect to MySQL server"))

    monkeypatch.setattr(api.main, "load_daily_revenue", down)
    api.main.state.cache.clear()
    r = client.get("/forecast/revenue")
    assert r.status_code == 503
    assert r.json()["error"] == "The database is not reachable."


def test_missing_api_key_returns_401():
    with TestClient(app) as anonymous:
        assert anonymous.get("/health").status_code == 401
        assert anonymous.post("/price/recommend", json=KNOWN_PAIR, headers={"X-API-Key": "wrong"}).status_code == 401
