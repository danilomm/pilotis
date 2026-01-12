"""
Integração com API do PagBank (PagSeguro)

Documentação: https://dev.pagbank.uol.com.br/reference
"""
import httpx
from datetime import datetime, timedelta

from ..config import settings


def get_api_url() -> str:
    """Retorna URL da API baseado no ambiente."""
    if settings.PAGBANK_SANDBOX:
        return "https://sandbox.api.pagseguro.com"
    return "https://api.pagseguro.com"


def get_headers() -> dict:
    """Retorna headers para autenticação."""
    return {
        "Authorization": f"Bearer {settings.PAGBANK_TOKEN}",
        "Content-Type": "application/json",
        "Accept": "application/json",
    }


async def criar_cobranca_pix(
    cadastrado_id: int,
    ano: int,
    nome: str,
    email: str,
    cpf: str,
    valor_centavos: int,
    dias_expiracao: int = 3,
) -> dict:
    """
    Cria cobrança PIX no PagBank.

    Retorna dict com:
    - order_id: ID do pedido
    - qr_code: código para copiar
    - qr_code_link: URL da imagem do QR Code
    - expiration_date: data de expiração
    """
    reference_id = f"PILOTIS-{cadastrado_id}-{ano}"
    expiration = (datetime.now() + timedelta(days=dias_expiracao)).strftime("%Y-%m-%dT23:59:59-03:00")

    payload = {
        "reference_id": reference_id,
        "customer": {
            "name": nome,
            "email": email,
            "tax_id": cpf.replace(".", "").replace("-", "") if cpf else None,
        },
        "items": [{
            "reference_id": f"filiacao-{ano}",
            "name": f"Filiação Docomomo Brasil {ano}",
            "quantity": 1,
            "unit_amount": valor_centavos,
        }],
        "qr_codes": [{
            "amount": {"value": valor_centavos},
            "expiration_date": expiration,
        }],
    }

    # Só adiciona webhook se não for localhost (PagBank não aceita)
    if not settings.BASE_URL.startswith("http://localhost"):
        payload["notification_urls"] = [f"{settings.BASE_URL}/webhook/pagbank"]

    # Remove tax_id se não tiver CPF
    if not payload["customer"]["tax_id"]:
        del payload["customer"]["tax_id"]

    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{get_api_url()}/orders",
            headers=get_headers(),
            json=payload,
            timeout=30.0,
        )

        if response.status_code not in (200, 201):
            raise Exception(f"Erro PagBank: {response.status_code} - {response.text}")

        data = response.json()

        qr_codes = data.get("qr_codes", [])
        qr_code_data = qr_codes[0] if qr_codes else {}

        return {
            "order_id": data.get("id"),
            "reference_id": reference_id,
            "qr_code": qr_code_data.get("text", ""),
            "qr_code_link": qr_code_data.get("links", [{}])[0].get("href", "") if qr_code_data.get("links") else "",
            "expiration_date": expiration,
        }


async def criar_cobranca_boleto(
    cadastrado_id: int,
    ano: int,
    nome: str,
    email: str,
    cpf: str,
    valor_centavos: int,
    endereco: dict,
    dias_vencimento: int = 3,
) -> dict:
    """
    Cria cobrança por boleto no PagBank.

    endereco deve conter: street, number, locality, city, region_code, postal_code

    Retorna dict com:
    - order_id: ID do pedido
    - charge_id: ID da cobrança
    - boleto_link: URL do boleto PDF
    - barcode: código de barras
    - due_date: data de vencimento
    """
    reference_id = f"PILOTIS-{cadastrado_id}-{ano}"
    due_date = (datetime.now() + timedelta(days=dias_vencimento)).strftime("%Y-%m-%d")

    payload = {
        "reference_id": reference_id,
        "customer": {
            "name": nome,
            "email": email,
            "tax_id": cpf.replace(".", "").replace("-", "") if cpf else None,
        },
        "items": [{
            "reference_id": f"filiacao-{ano}",
            "name": f"Filiação Docomomo Brasil {ano}",
            "quantity": 1,
            "unit_amount": valor_centavos,
        }],
        "charges": [{
            "reference_id": reference_id,
            "description": f"Filiação Docomomo Brasil {ano}",
            "amount": {
                "value": valor_centavos,
                "currency": "BRL",
            },
            "payment_method": {
                "type": "BOLETO",
                "boleto": {
                    "due_date": due_date,
                    "instruction_lines": {
                        "line_1": "Filiação Docomomo Brasil",
                        "line_2": f"Ano: {ano}",
                    },
                    "holder": {
                        "name": nome,
                        "tax_id": cpf.replace(".", "").replace("-", "") if cpf else "",
                        "email": email,
                        "address": {
                            "street": endereco.get("street", ""),
                            "number": endereco.get("number", "S/N"),
                            "locality": endereco.get("locality", ""),
                            "city": endereco.get("city", ""),
                            "region_code": endereco.get("region_code", ""),
                            "country": "BRA",
                            "postal_code": endereco.get("postal_code", "").replace("-", ""),
                        },
                    },
                },
            },
        }],
        "notification_urls": [f"{settings.BASE_URL}/webhook/pagbank"],
    }

    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{get_api_url()}/orders",
            headers=get_headers(),
            json=payload,
            timeout=30.0,
        )

        if response.status_code not in (200, 201):
            raise Exception(f"Erro PagBank: {response.status_code} - {response.text}")

        data = response.json()

        charges = data.get("charges", [])
        charge_data = charges[0] if charges else {}
        payment_method = charge_data.get("payment_method", {})
        boleto = payment_method.get("boleto", {})

        # Procura link do PDF
        boleto_link = ""
        for link in charge_data.get("links", []):
            if link.get("media") == "application/pdf":
                boleto_link = link.get("href", "")
                break

        return {
            "order_id": data.get("id"),
            "charge_id": charge_data.get("id"),
            "reference_id": reference_id,
            "boleto_link": boleto_link,
            "barcode": boleto.get("barcode", ""),
            "due_date": due_date,
        }


async def criar_cobranca_cartao(
    cadastrado_id: int,
    ano: int,
    nome: str,
    email: str,
    cpf: str,
    valor_centavos: int,
    card_encrypted: str,
    holder_name: str,
) -> dict:
    """
    Cria cobrança por cartão de crédito no PagBank.

    card_encrypted: cartão criptografado via PagBank.js

    Retorna dict com:
    - order_id: ID do pedido
    - charge_id: ID da cobrança
    - status: status do pagamento (PAID, DECLINED, etc)
    """
    reference_id = f"PILOTIS-{cadastrado_id}-{ano}"

    payload = {
        "reference_id": reference_id,
        "customer": {
            "name": nome,
            "email": email,
            "tax_id": cpf.replace(".", "").replace("-", "") if cpf else None,
        },
        "items": [{
            "reference_id": f"filiacao-{ano}",
            "name": f"Filiação Docomomo Brasil {ano}",
            "quantity": 1,
            "unit_amount": valor_centavos,
        }],
        "charges": [{
            "reference_id": reference_id,
            "description": f"Filiação Docomomo Brasil {ano}",
            "amount": {
                "value": valor_centavos,
                "currency": "BRL",
            },
            "payment_method": {
                "type": "CREDIT_CARD",
                "installments": 1,
                "capture": True,
                "card": {
                    "encrypted": card_encrypted,
                    "holder": {
                        "name": holder_name,
                    },
                },
            },
        }],
        "notification_urls": [f"{settings.BASE_URL}/webhook/pagbank"],
    }

    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{get_api_url()}/orders",
            headers=get_headers(),
            json=payload,
            timeout=30.0,
        )

        if response.status_code not in (200, 201):
            raise Exception(f"Erro PagBank: {response.status_code} - {response.text}")

        data = response.json()

        charges = data.get("charges", [])
        charge_data = charges[0] if charges else {}

        return {
            "order_id": data.get("id"),
            "charge_id": charge_data.get("id"),
            "reference_id": reference_id,
            "status": charge_data.get("status", ""),
        }


async def obter_chave_publica() -> str:
    """
    Obtem chave publica para criptografia de cartao.

    No sandbox, retorna a chave padrao de teste.
    Em producao, busca da API.
    """
    if settings.PAGBANK_SANDBOX:
        # Chave publica padrao do sandbox
        return "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr+ZqgD892U9/HXsa7XqBZUayPquAfh9xx4iwUbTSUAvTlmiXFQNTp0Bvt/5vK2FhMj39qSv1zi2OuBjvW38q1E374nzx6NNBL5JosV0+SDINTlCG0cmigHuBOyWzYmjgca+mtQu4WczCaApNaSuVqgb8u7Bd9GCOL4YJotvV5+81frlSwQXralhwRzGhj/A57CGPgGKiuPT+AOGmykIGEZsSD9RKkyoKIoc0OS8CPIzdBOtTQCIwrLn2FxI83Clcg55W8gkFSOS6rWNbG5qFZWMll6yl02HtunalHmUlRUL66YeGXdMDC2PuRcmZbGO5a/2tbVppW6mfSWG3NPRpgwIDAQAB"

    try:
        async with httpx.AsyncClient() as client:
            response = await client.post(
                f"{get_api_url()}/public-keys",
                headers=get_headers(),
                json={"type": "card"},
                timeout=30.0,
            )

            if response.status_code in (200, 201):
                data = response.json()
                return data.get("public_key", "")
    except Exception:
        pass

    return ""


async def consultar_pedido(order_id: str) -> dict:
    """
    Consulta status de um pedido.

    Retorna dict com status e charges.
    """
    async with httpx.AsyncClient() as client:
        response = await client.get(
            f"{get_api_url()}/orders/{order_id}",
            headers=get_headers(),
            timeout=30.0,
        )

        if response.status_code != 200:
            raise Exception(f"Erro PagBank: {response.status_code} - {response.text}")

        return response.json()


def parse_webhook_payload(payload: dict) -> dict:
    """
    Processa payload do webhook do PagBank.

    Retorna dict com:
    - reference_id: identificador do pedido (PILOTIS-{id}-{ano})
    - status: status da cobrança (PAID, CANCELED, etc)
    - paid: True se status == PAID
    - cadastrado_id: extraído do reference_id
    - ano: extraído do reference_id
    """
    reference_id = payload.get("reference_id", "")

    # Extrai cadastrado_id e ano do reference_id
    cadastrado_id = None
    ano = None
    if reference_id.startswith("PILOTIS-"):
        parts = reference_id.split("-")
        if len(parts) >= 3:
            try:
                cadastrado_id = int(parts[1])
                ano = int(parts[2])
            except ValueError:
                pass

    # Verifica status das charges
    charges = payload.get("charges", [])
    status = charges[0].get("status", "") if charges else ""

    return {
        "reference_id": reference_id,
        "status": status,
        "paid": status == "PAID",
        "cadastrado_id": cadastrado_id,
        "ano": ano,
        "order_id": payload.get("id"),
        "charge_id": charges[0].get("id") if charges else None,
    }
