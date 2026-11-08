"""Database connection for the ML service.

Reads the MySQL settings from ml-service/.env (copied from the Laravel .env) and returns a
SQLAlchemy engine using the pymysql driver. Times in the database are UTC (Laravel default);
convert to Malaysia time (UTC+8) when grouping by day.
"""
import os
from pathlib import Path

from dotenv import load_dotenv
from sqlalchemy import URL, create_engine
from sqlalchemy.engine import Engine

ENV_FILE = Path(__file__).resolve().parent / ".env"


def get_engine() -> Engine:
    """A SQLAlchemy engine for the shop's MySQL database."""
    load_dotenv(ENV_FILE)

    url = URL.create(
        "mysql+pymysql",
        username=os.getenv("DB_USERNAME", "root"),
        password=os.getenv("DB_PASSWORD") or None,
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3306")),
        database=os.getenv("DB_DATABASE", "thriftstore"),
        query={"charset": "utf8mb4"},
    )

    # pool_pre_ping: reconnect cleanly if MySQL (XAMPP) was restarted.
    return create_engine(url, pool_pre_ping=True)
