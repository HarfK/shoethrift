@echo off
rem Start the ShoeThrift ML API on http://127.0.0.1:8001 (local only).
rem Needs MySQL (XAMPP) running and ML_API_KEY in ml-service\.env. Stop with Ctrl+C.
cd /d "%~dp0"
"%~dp0venv\Scripts\python.exe" -m uvicorn api.main:app --host 127.0.0.1 --port 8001
