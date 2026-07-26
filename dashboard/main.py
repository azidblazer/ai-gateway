"""FastAPI application with lifespan for Usage Dashboard."""

from contextlib import asynccontextmanager
from pathlib import Path

import httpx
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates

from api.health import router as health_router
from api.usage import router as usage_router
from api.feedback import router as feedback_router
from api.coaching import router as coaching_router
from config import settings
from db.migrations import ensure_coaching_table, ensure_feedback_ratelimit_table
from db.pool import create_pool

# Paths relative to this file
BASE_DIR = Path(__file__).resolve().parent
templates = Jinja2Templates(directory=BASE_DIR / "templates")


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Lifespan context manager for startup and shutdown events."""
    # Startup: create connection pool
    print("Creating database connection pool...")
    app.state.pool = await create_pool(settings.DATABASE_URL)
    print("Connection pool created successfully")

    # Create shared HTTP client for Open WebUI token validation
    app.state.http_client = httpx.AsyncClient(
        base_url=settings.OPENWEBUI_BASE_URL,
        timeout=10.0,
    )
    print(f"HTTP client created (Open WebUI: {settings.OPENWEBUI_BASE_URL})")

    # OpenWebUI database pool (for coaching feature — chat history analysis)
    app.state.openwebui_pool = None
    if settings.OPENWEBUI_DATABASE_URL:
        try:
            app.state.openwebui_pool = await create_pool(settings.OPENWEBUI_DATABASE_URL)
            print("OpenWebUI database pool created")
        except Exception as e:
            print(f"OpenWebUI database pool failed (coaching will degrade): {e}")

    # LiteLLM API client (for coaching AI calls)
    app.state.litellm_client = None
    if settings.COACHING_API_KEY:
        app.state.litellm_client = httpx.AsyncClient(
            base_url=settings.LITELLM_API_URL,
            timeout=60.0,
        )
        print(f"LiteLLM coaching client created ({settings.LITELLM_API_URL})")

    # Ensure coaching cache table exists
    try:
        await ensure_coaching_table(app.state.pool)
        print("Coaching cache table ready")
    except Exception as e:
        print(f"Coaching cache table creation failed: {e}")

    # Ensure feedback rate-limit table exists
    try:
        await ensure_feedback_ratelimit_table(app.state.pool)
        print("Feedback rate-limit table ready")
    except Exception as e:
        print(f"Feedback rate-limit table creation failed: {e}")

    yield

    # Shutdown: close clients
    print("Closing HTTP client...")
    await app.state.http_client.aclose()
    if app.state.litellm_client:
        print("Closing LiteLLM coaching client...")
        await app.state.litellm_client.aclose()
    if app.state.openwebui_pool:
        print("Closing OpenWebUI database pool...")
        await app.state.openwebui_pool.close()
    print("Closing database connection pool...")
    await app.state.pool.close()
    print("Connection pool closed")


app = FastAPI(
    title="Usage Dashboard",
    lifespan=lifespan,
)

# CORS middleware — set to your chat domain
app.add_middleware(
    CORSMiddleware,
    allow_origins=["https://chat.example.edu"],
    allow_credentials=True,
    allow_methods=["GET", "POST"],
    allow_headers=["Authorization", "Content-Type"],
)

# Mount static files (accessed at /dashboard/static via nginx rewrite)
app.mount("/static", StaticFiles(directory=BASE_DIR / "static"), name="static")

# Mount routers
app.include_router(health_router)
app.include_router(usage_router)
app.include_router(feedback_router)
app.include_router(coaching_router)


@app.get("/")
async def index(request: Request):
    """Serve the main dashboard page."""
    return templates.TemplateResponse("index.html", {"request": request})
