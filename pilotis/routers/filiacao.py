import secrets
from datetime import datetime
from pathlib import Path

from fastapi import APIRouter, Form, HTTPException, Request
from fastapi.responses import HTMLResponse, RedirectResponse
from fastapi.templating import Jinja2Templates

from ..config import settings
from ..db import fetchone, execute, registrar_log


def buscar_cadastrado_por_email(email: str):
    """Busca cadastrado pelo email (pode ter múltiplos separados por ;)."""
    # Busca exata
    cadastrado = fetchone(
        "SELECT * FROM cadastrados WHERE email = ?",
        (email.strip().lower(),)
    )
    if cadastrado:
        return cadastrado

    # Busca se o email está em uma lista de emails
    cadastrado = fetchone(
        "SELECT * FROM cadastrados WHERE email LIKE ?",
        (f"%{email.strip().lower()}%",)
    )
    return cadastrado


def gerar_token():
    """Gera token único para cadastrado."""
    return secrets.token_urlsafe(16)

router = APIRouter(prefix="/filiacao", tags=["filiacao"])
templates = Jinja2Templates(directory=Path(__file__).parent.parent / "templates")

# Categorias válidas para filiação (exclui cadastrado e participante_seminario)
CATEGORIAS_FILIACAO = [
    ("profissional_internacional", "Docomomo. Filiado Pleno Internacional + Brasil", settings.VALOR_INTERNACIONAL),
    ("profissional_nacional", "Docomomo. Filiado Pleno Brasil", settings.VALOR_PROFISSIONAL),
    ("estudante", "Docomomo. Filiado Estudante (Graduacao/Pos) Brasil", settings.VALOR_ESTUDANTE),
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


@router.get("/{ano}", response_class=HTMLResponse)
async def tela_entrada(request: Request, ano: int):
    """Exibe tela de entrada que pede o email."""
    return templates.TemplateResponse(
        "entrada.html",
        {
            "request": request,
            "ano": ano,
            "mensagem": None,
        },
    )


@router.post("/{ano}", response_class=HTMLResponse)
async def processar_entrada(request: Request, ano: int, email: str = Form(...)):
    """Processa email e redireciona para formulário."""
    email = email.strip().lower()

    # Busca cadastrado pelo email
    cadastrado = buscar_cadastrado_por_email(email)

    if cadastrado:
        # Já existe, redireciona para formulário com token
        token = cadastrado["token"]
        if not token:
            # Gera token se não tiver
            token = gerar_token()
            execute(
                "UPDATE cadastrados SET token = ? WHERE id = ?",
                (token, cadastrado["id"])
            )
        registrar_log("entrada_email", cadastrado["id"], f"Entrada pelo email para {ano}")
        return RedirectResponse(
            url=f"/filiacao/{ano}/{token}",
            status_code=303,
        )
    else:
        # Novo cadastrado
        token = gerar_token()
        cursor = execute(
            """
            INSERT INTO cadastrados (nome, email, token, data_cadastro)
            VALUES (?, ?, ?, ?)
            """,
            ("", email, token, datetime.now().isoformat())
        )
        registrar_log("novo_cadastro", cursor.lastrowid, f"Novo cadastro via entrada {ano}")
        return RedirectResponse(
            url=f"/filiacao/{ano}/{token}",
            status_code=303,
        )


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
    observacoes_filiado: str = Form(None),
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
            formacao = ?, instituicao = ?, categoria = ?,
            observacoes_filiado = ?, data_atualizacao = ?
        WHERE id = ?
        """,
        (
            nome.strip(), email.strip(), cpf, telefone, endereco,
            cep, cidade, estado, pais, profissao,
            formacao, instituicao, categoria,
            observacoes_filiado, datetime.now().isoformat(),
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
    from ..services import pagbank

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

    # Dados para a tela
    valor_centavos = int(pagamento["valor"] * 100)
    pix_data = None
    erro_pagbank = None

    # Se ainda não tem order_id, cria cobrança PIX no PagBank
    if not pagamento["pagbank_order_id"]:
        try:
            pix_data = await pagbank.criar_cobranca_pix(
                cadastrado_id=cadastrado["id"],
                ano=ano,
                nome=cadastrado["nome"],
                email=cadastrado["email"],
                cpf=cadastrado["cpf"],
                valor_centavos=valor_centavos,
                dias_expiracao=3,
            )

            # Salva order_id e data de vencimento
            execute(
                """
                UPDATE pagamentos SET
                    pagbank_order_id = ?,
                    data_vencimento = ?,
                    metodo = 'pix'
                WHERE id = ?
                """,
                (pix_data["order_id"], pix_data["expiration_date"], pagamento["id"])
            )
            registrar_log("pix_gerado", cadastrado["id"], f"PIX gerado: {pix_data['order_id']}")

        except Exception as e:
            erro_pagbank = str(e)
            registrar_log("erro_pagbank", cadastrado["id"], f"Erro ao criar PIX: {erro_pagbank}")
    else:
        # Já tem order_id, busca dados do PIX
        try:
            order_data = await pagbank.consultar_pedido(pagamento["pagbank_order_id"])
            qr_codes = order_data.get("qr_codes", [])
            if qr_codes:
                qr = qr_codes[0]
                pix_data = {
                    "order_id": pagamento["pagbank_order_id"],
                    "qr_code": qr.get("text", ""),
                    "qr_code_link": qr.get("links", [{}])[0].get("href", "") if qr.get("links") else "",
                    "expiration_date": pagamento["data_vencimento"],
                }
        except Exception as e:
            erro_pagbank = str(e)

    # Converte pagamento para dict para usar .get()
    pagamento_dict = dict(pagamento)

    # Busca dados de boleto se existir
    boleto_data = None
    if pagamento_dict.get("pagbank_boleto_link"):
        boleto_data = {
            "boleto_link": pagamento_dict["pagbank_boleto_link"],
            "barcode": pagamento_dict.get("pagbank_boleto_barcode", ""),
            "due_date": pagamento_dict.get("data_vencimento", ""),
        }

    # Chave publica para criptografia de cartao
    pagbank_public_key = await pagbank.obter_chave_publica()

    return templates.TemplateResponse(
        "pagamento.html",
        {
            "request": request,
            "ano": ano,
            "token": token,
            "cadastrado": dict(cadastrado),
            "pagamento": pagamento_dict,
            "valor_formatado": formatar_valor(valor_centavos),
            "pix": pix_data,
            "boleto": boleto_data,
            "pagbank_public_key": pagbank_public_key,
            "erro_pagbank": erro_pagbank,
        },
    )


@router.post("/{ano}/{token}/gerar-pix", response_class=HTMLResponse)
async def gerar_pix(request: Request, ano: int, token: str):
    """Gera cobranca PIX."""
    from ..services import pagbank

    cadastrado = buscar_cadastrado_por_token(token)
    if not cadastrado:
        raise HTTPException(status_code=404, detail="Token invalido")

    pagamento = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (cadastrado["id"], ano)
    )
    if not pagamento:
        return RedirectResponse(url=f"/filiacao/{ano}/{token}")

    if pagamento["status"] == "pago":
        return RedirectResponse(url=f"/filiacao/{ano}/{token}/pagamento")

    valor_centavos = int(pagamento["valor"] * 100)

    try:
        pix_data = await pagbank.criar_cobranca_pix(
            cadastrado_id=cadastrado["id"],
            ano=ano,
            nome=cadastrado["nome"],
            email=cadastrado["email"],
            cpf=cadastrado["cpf"],
            valor_centavos=valor_centavos,
            dias_expiracao=3,
        )

        execute(
            """
            UPDATE pagamentos SET
                pagbank_order_id = ?,
                data_vencimento = ?,
                metodo = 'pix'
            WHERE id = ?
            """,
            (pix_data["order_id"], pix_data["expiration_date"], pagamento["id"])
        )
        registrar_log("pix_gerado", cadastrado["id"], f"PIX gerado: {pix_data['order_id']}")

    except Exception as e:
        registrar_log("erro_pagbank", cadastrado["id"], f"Erro ao criar PIX: {e}")

    return RedirectResponse(url=f"/filiacao/{ano}/{token}/pagamento", status_code=303)


@router.post("/{ano}/{token}/gerar-boleto", response_class=HTMLResponse)
async def gerar_boleto(request: Request, ano: int, token: str):
    """Gera cobranca por boleto."""
    from ..services import pagbank

    cadastrado = buscar_cadastrado_por_token(token)
    if not cadastrado:
        raise HTTPException(status_code=404, detail="Token invalido")

    pagamento = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (cadastrado["id"], ano)
    )
    if not pagamento:
        return RedirectResponse(url=f"/filiacao/{ano}/{token}")

    if pagamento["status"] == "pago":
        return RedirectResponse(url=f"/filiacao/{ano}/{token}/pagamento")

    valor_centavos = int(pagamento["valor"] * 100)

    # Monta endereco
    endereco = {
        "street": cadastrado.get("endereco") or "Nao informado",
        "number": "S/N",
        "locality": cadastrado.get("cidade") or "Nao informado",
        "city": cadastrado.get("cidade") or "Nao informado",
        "region_code": cadastrado.get("estado") or "DF",
        "postal_code": (cadastrado.get("cep") or "70000000").replace("-", ""),
    }

    try:
        boleto_data = await pagbank.criar_cobranca_boleto(
            cadastrado_id=cadastrado["id"],
            ano=ano,
            nome=cadastrado["nome"],
            email=cadastrado["email"],
            cpf=cadastrado["cpf"],
            valor_centavos=valor_centavos,
            endereco=endereco,
            dias_vencimento=3,
        )

        execute(
            """
            UPDATE pagamentos SET
                pagbank_order_id = ?,
                pagbank_charge_id = ?,
                pagbank_boleto_link = ?,
                pagbank_boleto_barcode = ?,
                data_vencimento = ?,
                metodo = 'boleto'
            WHERE id = ?
            """,
            (
                boleto_data["order_id"],
                boleto_data["charge_id"],
                boleto_data["boleto_link"],
                boleto_data["barcode"],
                boleto_data["due_date"],
                pagamento["id"],
            )
        )
        registrar_log("boleto_gerado", cadastrado["id"], f"Boleto gerado: {boleto_data['order_id']}")

    except Exception as e:
        registrar_log("erro_pagbank", cadastrado["id"], f"Erro ao criar boleto: {e}")

    return RedirectResponse(url=f"/filiacao/{ano}/{token}/pagamento", status_code=303)


@router.post("/{ano}/{token}/pagar-cartao", response_class=HTMLResponse)
async def pagar_cartao(
    request: Request,
    ano: int,
    token: str,
    card_encrypted: str = Form(...),
    holder_name: str = Form(...),
):
    """Processa pagamento com cartao de credito."""
    from ..services import pagbank

    cadastrado = buscar_cadastrado_por_token(token)
    if not cadastrado:
        raise HTTPException(status_code=404, detail="Token invalido")

    pagamento = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (cadastrado["id"], ano)
    )
    if not pagamento:
        return RedirectResponse(url=f"/filiacao/{ano}/{token}")

    if pagamento["status"] == "pago":
        return RedirectResponse(url=f"/filiacao/{ano}/{token}/pagamento")

    valor_centavos = int(pagamento["valor"] * 100)

    try:
        cartao_data = await pagbank.criar_cobranca_cartao(
            cadastrado_id=cadastrado["id"],
            ano=ano,
            nome=cadastrado["nome"],
            email=cadastrado["email"],
            cpf=cadastrado["cpf"],
            valor_centavos=valor_centavos,
            card_encrypted=card_encrypted,
            holder_name=holder_name,
        )

        execute(
            """
            UPDATE pagamentos SET
                pagbank_order_id = ?,
                pagbank_charge_id = ?,
                metodo = 'cartao'
            WHERE id = ?
            """,
            (cartao_data["order_id"], cartao_data["charge_id"], pagamento["id"])
        )

        # Se pagamento aprovado imediatamente
        if cartao_data["status"] == "PAID":
            execute(
                "UPDATE pagamentos SET status = 'pago', data_pagamento = ? WHERE id = ?",
                (datetime.now().isoformat(), pagamento["id"])
            )
            registrar_log("pagamento_cartao", cadastrado["id"], f"Pagamento com cartao aprovado: {cartao_data['order_id']}")

            return templates.TemplateResponse(
                "confirmacao.html",
                {
                    "request": request,
                    "cadastrado": dict(cadastrado),
                    "ano": ano,
                    "mensagem": "Pagamento aprovado! Sua filiacao esta confirmada.",
                },
            )
        else:
            registrar_log("cartao_pendente", cadastrado["id"], f"Cartao pendente/recusado: {cartao_data['status']}")

    except Exception as e:
        registrar_log("erro_pagbank", cadastrado["id"], f"Erro ao processar cartao: {e}")

    return RedirectResponse(url=f"/filiacao/{ano}/{token}/pagamento", status_code=303)
