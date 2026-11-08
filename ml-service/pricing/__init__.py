"""Price recommendation for the ShoeThrift admin (Stage 6).

- data:      sold pairs (before any markdown) with their features
- models:    RatioBaseline, RidgeModel, RandomForestModel, XGBoostModel, LightGBMModel (fit / predict / explain)
- recommend: recommend_price(product) -> suggested price (nearest RM5), 80% range and reasons in RM
"""
