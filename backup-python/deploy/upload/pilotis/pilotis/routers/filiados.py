from pathlib import Path

from fastapi import APIRouter, Request
from fastapi.responses import HTMLResponse
from fastapi.templating import Jinja2Templates

from ..db import fetchall

router = APIRouter(prefix="/filiados", tags=["filiados"])
templates = Jinja2Templates(directory=Path(__file__).parent.parent / "templates")

# Mapeamento de categorias para nomes de exibição
NOMES_CATEGORIAS = {
    "profissional_internacional": "Filiação Plena Docomomo Internacional+Brasil",
    "profissional_nacional": "Filiação Plena Docomomo Brasil",
    "estudante": "Filiação Estudante Docomomo Brasil",
}

# Ordem de exibição das categorias
ORDEM_CATEGORIAS = [
    "profissional_internacional",
    "profissional_nacional",
    "estudante",
]


@router.get("/{ano}", response_class=HTMLResponse)
async def lista_filiados(request: Request, ano: int):
    """Exibe lista pública de filiados adimplentes do ano."""
    # Busca filiados com pagamento pago no ano
    filiados = fetchall(
        """
        SELECT c.nome, c.instituicao, c.categoria
        FROM cadastrados c
        JOIN pagamentos p ON c.id = p.cadastrado_id
        WHERE p.ano = ? AND p.status = 'pago'
        ORDER BY c.nome
        """,
        (ano,)
    )

    # Agrupa por categoria
    por_categoria = {cat: [] for cat in ORDEM_CATEGORIAS}
    for f in filiados:
        cat = f["categoria"]
        if cat in por_categoria:
            por_categoria[cat].append({
                "nome": f["nome"],
                "instituicao": f["instituicao"] or "",
            })

    # Monta lista de categorias com nomes e filiados
    categorias = [
        {
            "codigo": cat,
            "nome": NOMES_CATEGORIAS[cat],
            "filiados": por_categoria[cat],
            "total": len(por_categoria[cat]),
        }
        for cat in ORDEM_CATEGORIAS
        if por_categoria[cat]  # só mostra categorias com filiados
    ]

    total_geral = sum(c["total"] for c in categorias)

    return templates.TemplateResponse(
        "filiados.html",
        {
            "request": request,
            "ano": ano,
            "categorias": categorias,
            "total": total_geral,
        },
    )
