"""
Router para webhooks do PagBank.
"""
import asyncio
from datetime import datetime

from fastapi import APIRouter, Request, BackgroundTasks

from ..config import settings
from ..db import fetchone, execute, registrar_log
from ..services.pagbank import parse_webhook_payload
from ..services import pdf, email

router = APIRouter(prefix="/webhook", tags=["webhook"])


async def processar_pagamento_confirmado(cadastrado_id: int, ano: int):
    """
    Processa pagamento confirmado: gera PDF e envia email.
    Executado em background para não bloquear o webhook.
    """
    try:
        # Busca dados do cadastrado
        cadastrado = fetchone(
            "SELECT * FROM cadastrados WHERE id = ?",
            (cadastrado_id,)
        )

        if not cadastrado:
            registrar_log("erro_confirmacao", cadastrado_id, "Cadastrado não encontrado")
            return

        # Busca dados do pagamento
        pagamento = fetchone(
            "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
            (cadastrado_id, ano)
        )

        if not pagamento:
            registrar_log("erro_confirmacao", cadastrado_id, "Pagamento não encontrado")
            return

        valor_centavos = int(pagamento["valor"])

        # Gera PDF da declaração
        pdf_bytes = pdf.gerar_declaracao(
            nome=cadastrado["nome"],
            email=cadastrado["email"],
            categoria=cadastrado["categoria"],
            ano=ano,
            valor_centavos=valor_centavos,
        )

        # Envia email de confirmação com PDF anexo
        enviado = await email.enviar_confirmacao_filiacao(
            email=cadastrado["email"],
            nome=cadastrado["nome"],
            categoria=cadastrado["categoria"],
            ano=ano,
            valor_centavos=valor_centavos,
            pdf_declaracao=pdf_bytes,
        )

        if enviado:
            registrar_log("email_confirmacao_enviado", cadastrado_id, f"Email de confirmação enviado para {cadastrado['email']}")
        else:
            registrar_log("erro_email_confirmacao", cadastrado_id, f"Falha ao enviar email para {cadastrado['email']}")

    except Exception as e:
        registrar_log("erro_confirmacao", cadastrado_id, f"Erro ao processar confirmação: {str(e)}")


@router.post("/pagbank")
async def webhook_pagbank(request: Request, background_tasks: BackgroundTasks):
    """
    Recebe notificações do PagBank sobre mudanças de status.

    O webhook deve ser idempotente (pode receber múltiplas vezes).
    """
    try:
        payload = await request.json()
    except Exception:
        return {"status": "error", "message": "Invalid JSON"}

    # Registra no log
    registrar_log("webhook_pagbank", None, f"Payload recebido: {payload}")

    # Processa payload
    dados = parse_webhook_payload(payload)

    if not dados["cadastrado_id"] or not dados["ano"]:
        registrar_log("webhook_pagbank", None, f"Reference ID inválido: {dados['reference_id']}")
        return {"status": "ok", "message": "Reference ID inválido"}

    # Busca pagamento
    pagamento = fetchone(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        (dados["cadastrado_id"], dados["ano"])
    )

    if not pagamento:
        registrar_log("webhook_pagbank", dados["cadastrado_id"], f"Pagamento não encontrado para ano {dados['ano']}")
        return {"status": "ok", "message": "Pagamento não encontrado"}

    # Se já está pago, ignora (idempotente)
    if pagamento["status"] == "pago":
        registrar_log("webhook_pagbank", dados["cadastrado_id"], "Pagamento já confirmado anteriormente")
        return {"status": "ok", "message": "Já processado"}

    # Atualiza status conforme retorno
    if dados["paid"]:
        execute(
            """
            UPDATE pagamentos SET
                status = 'pago',
                data_pagamento = ?,
                pagbank_order_id = ?,
                pagbank_charge_id = ?
            WHERE id = ?
            """,
            (
                datetime.now().isoformat(),
                dados["order_id"],
                dados["charge_id"],
                pagamento["id"],
            )
        )
        registrar_log("pagamento_confirmado", dados["cadastrado_id"], f"Pagamento {dados['ano']} confirmado via webhook")

        # Processa email e PDF em background
        background_tasks.add_task(
            processar_pagamento_confirmado,
            dados["cadastrado_id"],
            dados["ano"]
        )

        return {"status": "ok", "message": "Pagamento confirmado"}

    elif dados["status"] in ("CANCELED", "DECLINED"):
        execute(
            """
            UPDATE pagamentos SET
                status = 'cancelado',
                pagbank_order_id = ?,
                pagbank_charge_id = ?
            WHERE id = ?
            """,
            (
                dados["order_id"],
                dados["charge_id"],
                pagamento["id"],
            )
        )
        registrar_log("pagamento_cancelado", dados["cadastrado_id"], f"Pagamento {dados['ano']} cancelado: {dados['status']}")
        return {"status": "ok", "message": "Pagamento cancelado"}

    else:
        registrar_log("webhook_pagbank", dados["cadastrado_id"], f"Status não tratado: {dados['status']}")
        return {"status": "ok", "message": f"Status: {dados['status']}"}
