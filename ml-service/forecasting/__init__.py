"""Revenue forecasting for the ShoeThrift admin (Stage 4).

- calendar: Malaysian calendar features and Prophet holiday table
- data:     load_daily_revenue() with the dashboard's revenue definition
- models:   SeasonalNaive, Sarima, ProphetModel, XGBoostModel (fit(history) / predict(dates))

Import as a package (`from forecasting import ...`) with ml-service/ on sys.path. Do not put
ml-service/forecasting/ itself on sys.path: its calendar.py would hide Python's own `calendar` module.
"""
