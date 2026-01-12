from datetime import datetime
from pathlib import Path

from fastapi import APIRouter, Form, HTTPException, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates

from ..config import settings
from ..db import fetchone, execute, registrar_log

router = APIRouter(prefix="/filiacao", tags=["filiacao"])
templates = Jinja2Templates(directory=Path(__file__).parent.parent / "templates")

# Categorias válidas para filiação (exclui cadastrado e participante_seminario)
CATEGORIAS_FILIACAO = [
    ("estudante", "Estudante", settings.VALOR_ESTUDANTE),
    ("profissional_nacional", "Profissional Nacional", settings.VALOR_PROFISSIONAL),
    ("profissional_internacional", "Profissional Internacional", settings.VALOR_INTERNACIONAL),
]


def buscar_cadastrado_por_token(token: str):
    """Busca cadastrado pelo token."""
    return fetchone(
        "SELECT * FROM cadastrados WHERE token = ?",
        (token,)
    )


def formatar_valor(centavos: int) -> str:
    """Formata valor de centavos para reais."""
    return f"R$ {centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")


@router.get("/{ano}/{token}", response_class=HTMLResponse)
async def formulario_filiacao(request: Request, ano: int, token: str):
    """Exibe formulário de filiação pré-preenchido."""
    cadastrado = buscar_cadastrado_por_token(token)

    if not cadastrado:
        raise HTTPException(status_code=404, detail="Token inválido")

    # Monta lista de categorias com valores formatados
    categorias = [
        {
            "valor": cat[0],
            "label": f"{cat[1]} - {formatar_valor(cat[2])}",
            "selecionada": cadastrado["categoria"] == cat[0],
        }
        for cat in CATEGORIAS_FILIACAO
    ]

    # Verifica se já existe pagamento para este ano
    pagamento_existente = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (cadastrado["id"], ano)
    )

    registrar_log("acesso_formulario", cadastrado["id"], f"Acesso ao formulário {ano}")

    return templates.TemplateResponse(
        "filiacao.html",
        {
            "request": request,
            "ano": ano,
            "token": token,
            "cadastrado": dict(cadastrado),
            "categorias": categorias,
            "pagamento_existente": dict(pagamento_existente) if pagamento_existente else None,
        },
    )


@router.post("/{ano}/{token}", response_class=HTMLResponse)
async def salvar_filiacao(
    request: Request,
    ano: int,
    token: str,
    nome: str = Form(...),
    email: str = Form(...),
    cpf: str = Form(None),
    telefone: str = Form(None),
    endereco: str = Form(None),
    cep: str = Form(None),
    cidade: str = Form(None),
    estado: str = Form(None),
    pais: str = Form("Brasil"),
    profissao: str = Form(None),
    formacao: str = Form(None),
    instituicao: str = Form(None),
    categoria: str = Form(...),
):
    """Salva dados atualizados e redireciona para pagamento."""
    cadastrado = buscar_cadastrado_por_token(token)

    if not cadastrado:
        raise HTTPException(status_code=404, detail="Token inválido")

    # Valida categoria
    categorias_validas = [c[0] for c in CATEGORIAS_FILIACAO]
    if categoria not in categorias_validas:
        raise HTTPException(status_code=400, detail="Categoria inválida")

    # Atualiza dados do cadastrado
    execute(
        """
        UPDATE cadastrados SET
            nome = ?, email = ?, cpf = ?, telefone = ?, endereco = ?,
            cep = ?, cidade = ?, estado = ?, pais = ?, profissao = ?,
            formacao = ?, instituicao = ?, categoria = ?, data_atualizacao = ?
        WHERE id = ?
        """,
        (
            nome.strip(), email.strip(), cpf, telefone, endereco,
            cep, cidade, estado, pais, profissao,
            formacao, instituicao, categoria, datetime.now().isoformat(),
            cadastrado["id"],
        ),
    )

    registrar_log("dados_atualizados", cadastrado["id"], f"Dados atualizados para filiação {ano}")

    # Verifica se já existe pagamento pendente para este ano
    pagamento = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (cadastrado["id"], ano)
    )

    # Calcula valor baseado na categoria
    valor = settings.valor_por_categoria(categoria)

    if pagamento:
        if pagamento["status"] == "pago":
            # Já pagou, mostra confirmação
            return templates.TemplateResponse(
                "confirmacao.html",
                {
                    "request": request,
                    "cadastrado": dict(cadastrado),
                    "ano": ano,
                    "mensagem": "Sua filiação já está confirmada!",
                },
            )
        else:
            # Atualiza valor se categoria mudou
            execute(
                "UPDATE pagamentos SET valor = ? WHERE id = ?",
                (valor / 100, pagamento["id"]),
            )
    else:
        # Cria novo pagamento pendente
        execute(
            """
            INSERT INTO pagamentos (cadastrado_id, ano, valor, status, metodo)
            VALUES (?, ?, ?, 'pendente', 'pix')
            """,
            (cadastrado["id"], ano, valor / 100),
        )

    registrar_log("pagamento_criado", cadastrado["id"], f"Pagamento criado para {ano}: R$ {valor/100:.2f}")

    # Redireciona para tela de pagamento
    return RedirectResponse(
        url=f"/filiacao/{ano}/{token}/pagamento",
        status_code=303,
    )


@router.get("/{ano}/{token}/pagamento", response_class=HTMLResponse)
async def tela_pagamento(request: Request, ano: int, token: str):
    """Exibe tela de pagamento com QR Code PIX."""
    cadastrado = buscar_cadastrado_por_token(token)

    if not cadastrado:
        raise HTTPException(status_code=404, detail="Token inválido")

    pagamento = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (cadastrado["id"], ano)
    )

    if not pagamento:
        return RedirectResponse(url=f"/filiacao/{ano}/{token}")

    if pagamento["status"] == "pago":
        return templates.TemplateResponse(
            "confirmacao.html",
            {
                "request": request,
                "cadastrado": dict(cadastrado),
                "ano": ano,
                "mensagem": "Sua filiação já está confirmada!",
            },
        )

    # Por enquanto, mostra tela de pagamento sem integração PagBank
    # A integração será feita na Fase 4
    return templates.TemplateResponse(
        "pagamento.html",
        {
            "request": request,
            "ano": ano,
            "token": token,
            "cadastrado": dict(cadastrado),
            "pagamento": dict(pagamento),
            "valor_formatado": formatar_valor(int(pagamento["valor"] * 100)),
        },
    )
