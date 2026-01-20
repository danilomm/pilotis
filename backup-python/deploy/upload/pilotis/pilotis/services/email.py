"""
Serviço de envio de emails via Brevo (ex-Sendinblue).

Documentação: https://developers.brevo.com/reference/sendtransacemail
Limite gratuito: 300 emails/dia
"""
import base64
import httpx
from pathlib import Path

from jinja2 import Environment, FileSystemLoader

from ..config import settings


# Templates de email
TEMPLATES_DIR = Path(__file__).parent.parent / "templates" / "emails"
jinja_env = Environment(loader=FileSystemLoader(TEMPLATES_DIR))


async def enviar_email(
    para: str | list[str],
    assunto: str,
    html: str,
    anexos: list[dict] | None = None,
) -> bool:
    """
    Envia email via API Brevo.

    Args:
        para: email ou lista de emails
        assunto: assunto do email
        html: conteúdo HTML
        anexos: lista de dicts com {name, content (base64), contentType}

    Returns:
        True se enviou com sucesso
    """
    if isinstance(para, str):
        para = [para]

    # Prepara destinatários
    to_list = [{"email": e.strip()} for e in para if e.strip()]

    if not to_list:
        return False

    payload = {
        "sender": {
            "name": "Docomomo Brasil",
            "email": settings.EMAIL_FROM.split("<")[-1].replace(">", "").strip()
            if "<" in settings.EMAIL_FROM
            else settings.EMAIL_FROM,
        },
        "to": to_list,
        "subject": assunto,
        "htmlContent": html,
    }

    if anexos:
        payload["attachment"] = anexos

    async with httpx.AsyncClient() as client:
        response = await client.post(
            "https://api.brevo.com/v3/smtp/email",
            headers={
                "api-key": settings.BREVO_API_KEY,
                "Content-Type": "application/json",
            },
            json=payload,
            timeout=30.0,
        )

        return response.status_code == 201


def preparar_anexo_pdf(nome_arquivo: str, conteudo_bytes: bytes) -> dict:
    """
    Prepara anexo PDF para envio.

    Args:
        nome_arquivo: nome do arquivo (ex: "declaracao.pdf")
        conteudo_bytes: bytes do PDF

    Returns:
        dict com name, content (base64) e contentType
    """
    return {
        "name": nome_arquivo,
        "content": base64.b64encode(conteudo_bytes).decode("utf-8"),
        "contentType": "application/pdf",
    }


async def enviar_confirmacao_filiacao(
    email: str,
    nome: str,
    categoria: str,
    ano: int,
    valor_centavos: int,
    pdf_declaracao: bytes | None = None,
) -> bool:
    """
    Envia email de confirmação de filiação com declaração em anexo.
    """
    # Mapeamento de categorias
    categorias_nome = {
        "profissional_internacional": "Filiação Plena Docomomo Internacional+Brasil",
        "profissional_nacional": "Filiação Plena Docomomo Brasil",
        "estudante": "Filiação Estudante Docomomo Brasil",
    }

    valor_formatado = f"R$ {valor_centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")

    try:
        template = jinja_env.get_template("confirmacao.html")
        html = template.render(
            nome=nome,
            categoria=categorias_nome.get(categoria, categoria),
            ano=ano,
            valor=valor_formatado,
        )
    except Exception:
        # Fallback se template não existir
        html = f"""
        <h1>Filiação Confirmada!</h1>
        <p>Olá {nome},</p>
        <p>Sua filiação ao <strong>Docomomo Brasil</strong> para o ano de <strong>{ano}</strong> está confirmada!</p>
        <p><strong>Categoria:</strong> {categorias_nome.get(categoria, categoria)}</p>
        <p><strong>Valor:</strong> {valor_formatado}</p>
        <p>Em anexo, enviamos sua declaração de filiação.</p>
        <p>Obrigado por fazer parte do Docomomo Brasil!</p>
        <hr>
        <p><small>Associação de Colaboradores do Docomomo Brasil<br>@docomomobrasil</small></p>
        """

    anexos = []
    if pdf_declaracao:
        anexos.append(preparar_anexo_pdf(f"declaracao_docomomo_{ano}.pdf", pdf_declaracao))

    return await enviar_email(
        para=email,
        assunto=f"Filiação Docomomo Brasil {ano} - Confirmada!",
        html=html,
        anexos=anexos if anexos else None,
    )


async def enviar_lembrete_pagamento(
    email: str,
    nome: str,
    ano: int,
    token: str,
    dias_restantes: int,
    valor_centavos: int,
) -> bool:
    """
    Envia lembrete de pagamento pendente.
    """
    valor_formatado = f"R$ {valor_centavos / 100:,.2f}".replace(",", "X").replace(".", ",").replace("X", ".")
    link = f"{settings.BASE_URL}/filiacao/{ano}/{token}/pagamento"

    try:
        template = jinja_env.get_template("lembrete.html")
        html = template.render(
            nome=nome,
            ano=ano,
            valor=valor_formatado,
            link=link,
            dias_restantes=dias_restantes,
        )
    except Exception:
        # Fallback
        urgencia = "ÚLTIMO AVISO: " if dias_restantes <= 0 else ""
        html = f"""
        <h1>{urgencia}Lembrete de Pagamento</h1>
        <p>Olá {nome},</p>
        <p>Identificamos que sua filiação ao Docomomo Brasil para {ano} ainda está pendente de pagamento.</p>
        <p><strong>Valor:</strong> {valor_formatado}</p>
        {"<p><strong>Seu PIX expira hoje!</strong></p>" if dias_restantes <= 0 else f"<p>Restam {dias_restantes} dias para o vencimento.</p>"}
        <p><a href="{link}">Clique aqui para realizar o pagamento</a></p>
        <p>Se já realizou o pagamento, por favor desconsidere este email.</p>
        <hr>
        <p><small>Associação de Colaboradores do Docomomo Brasil<br>@docomomobrasil</small></p>
        """

    assunto = f"{'ÚLTIMO AVISO: ' if dias_restantes <= 0 else ''}Filiação Docomomo Brasil {ano} - Pagamento Pendente"

    return await enviar_email(
        para=email,
        assunto=assunto,
        html=html,
    )


async def enviar_campanha_renovacao(
    email: str,
    nome: str,
    ano: int,
    token: str,
) -> bool:
    """
    Envia email de campanha para filiados existentes (renovação).
    """
    link = f"{settings.BASE_URL}/filiacao/{ano}/{token}"

    try:
        template = jinja_env.get_template("campanha_renovacao.html")
        html = template.render(
            nome=nome,
            ano=ano,
            link=link,
        )
    except Exception:
        # Fallback
        html = f"""
        <h1>Renove sua Filiação - Docomomo Brasil {ano}</h1>
        <p>Olá {nome},</p>
        <p>É hora de renovar sua filiação ao Docomomo Brasil!</p>
        <p><strong>Benefícios da filiação:</strong></p>
        <ul>
            <li>Descontos em eventos do Docomomo Brasil e núcleos regionais</li>
            <li>Acesso à rede de profissionais e pesquisadores</li>
            <li>Para internacional: Docomomo Journal, Member Card, descontos em museus</li>
        </ul>
        <p><a href="{link}">Clique aqui para renovar sua filiação</a></p>
        <hr>
        <p><small>Associação de Colaboradores do Docomomo Brasil<br>@docomomobrasil</small></p>
        """

    return await enviar_email(
        para=email,
        assunto=f"Renove sua Filiação - Docomomo Brasil {ano}",
        html=html,
    )


async def enviar_campanha_convite(
    email: str,
    nome: str,
    ano: int,
    token: str,
) -> bool:
    """
    Envia email de campanha para novos (convite à filiação).
    """
    link = f"{settings.BASE_URL}/filiacao/{ano}/{token}"

    try:
        template = jinja_env.get_template("campanha_convite.html")
        html = template.render(
            nome=nome,
            ano=ano,
            link=link,
        )
    except Exception:
        # Fallback
        html = f"""
        <h1>Convite para Filiação - Docomomo Brasil {ano}</h1>
        <p>Olá {nome},</p>
        <p>Gostaríamos de convidar você a se filiar ao <strong>Docomomo Brasil</strong>!</p>
        <p>O Docomomo (Documentation and Conservation of buildings, sites and neighbourhoods of the Modern Movement)
        é uma organização internacional dedicada à documentação e conservação do patrimônio moderno.</p>
        <p><strong>Benefícios da filiação:</strong></p>
        <ul>
            <li>Descontos em eventos do Docomomo Brasil e núcleos regionais</li>
            <li>Acesso à rede de profissionais e pesquisadores</li>
            <li>Participação nas atividades e publicações</li>
        </ul>
        <p><a href="{link}">Clique aqui para se filiar</a></p>
        <hr>
        <p><small>Associação de Colaboradores do Docomomo Brasil<br>@docomomobrasil</small></p>
        """

    return await enviar_email(
        para=email,
        assunto=f"Convite para Filiação - Docomomo Brasil {ano}",
        html=html,
    )


async def enviar_campanha_seminario(
    email: str,
    nome: str,
    ano: int,
    token: str,
) -> bool:
    """
    Envia email de campanha para participantes do seminário (convite especial).
    """
    link = f"{settings.BASE_URL}/filiacao/{ano}/{token}"

    try:
        template = jinja_env.get_template("campanha_seminario.html")
        html = template.render(
            nome=nome,
            ano=ano,
            link=link,
        )
    except Exception:
        # Fallback
        html = f"""
        <h1>Filiação Docomomo Brasil {ano}</h1>
        <p>Olá {nome},</p>
        <p>Obrigado por sua participação no <strong>16º Seminário Docomomo Brasil</strong>!</p>
        <p>Convidamos você a se filiar ao Docomomo Brasil e fortalecer nossa rede de documentação e conservação da arquitetura, urbanismo e paisagismo modernos.</p>
        <p><a href="{link}">Clique aqui para se filiar</a></p>
        <hr>
        <p><small>Associação de Colaboradores do Docomomo Brasil<br>@docomomobrasil</small></p>
        """

    return await enviar_email(
        para=email,
        assunto=f"Filiação Docomomo Brasil {ano} - Participante do 16º Seminário",
        html=html,
    )
