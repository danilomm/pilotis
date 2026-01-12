from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates

from .config import settings
from .db import fetchone
from .routers import filiacao

app = FastAPI(
    title="Pilotis",
    description="Sistema de gestão de filiados do Docomomo Brasil",
    version="0.1.0",
)

templates = Jinja2Templates(directory=Path(__file__).parent / "templates")

# Registra routers
app.include_router(filiacao.router)


@app.get("/", response_class=HTMLResponse)
async def home(request: Request):
    """Página inicial."""
    # Conta cadastrados e filiados
    total = fetchone("SELECT COUNT(*) as n FROM cadastrados")
    filiados = fetchone("SELECT COUNT(*) as n FROM filiados")

    return templates.TemplateResponse(
        "base.html",
        {
            "request": request,
            "titulo": "Pilotis",
            "conteudo": f"""
                <h1>Pilotis</h1>
                <p>Sistema de gestão de filiados do Docomomo Brasil</p>
                <ul>
                    <li>Total de cadastrados: {total['n'] if total else 0}</li>
                    <li>Filiados no ano corrente: {filiados['n'] if filiados else 0}</li>
                </ul>
            """,
        },
    )


@app.get("/health")
async def health():
    """Endpoint de saúde para monitoramento."""
    return {"status": "ok", "version": "0.1.0"}
